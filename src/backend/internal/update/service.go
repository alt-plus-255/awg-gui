package update

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/panelops"
)

const (
	logTailLines        = 80
	stuckAfterSeconds   = 10800  // 3h wall clock before "stuck" (log activity overrides)
	timeoutAfterSeconds = 28800  // 8h hard fail if log also idle
	logActiveWindow     = 15 * time.Minute
	githubRepoDefault   = "alt-plus-255/awg-gui"
)

type Service struct {
	PanelOps       *panelops.Client
	HostGUIDir     string
	HostComposeDir string
	Token          string
	GitHubRepo     string
	HTTP           *http.Client
}

func New(cfg config.Config, ops *panelops.Client) *Service {
	return &Service{
		PanelOps:       ops,
		HostGUIDir:     strings.TrimRight(cfg.HostAWGGUIDir, "/"),
		HostComposeDir: strings.TrimRight(cfg.HostComposeDir, "/"),
		Token:          strings.TrimSpace(cfg.PanelOpsToken),
		GitHubRepo:     strings.TrimSpace(cfg.GitHubRepo),
		HTTP:           &http.Client{Timeout: 20 * time.Second},
	}
}

func (s *Service) Status(locale string, checkRelease bool) map[string]any {
	install := s.readKeyValueFile(s.HostGUIDir + "/install.state")
	update := s.reconcileUpdateState(s.readJSONFile(s.HostGUIDir+"/update.state"), install)
	current := s.detectCurrentVersion(install)
	running := s.isRunningState(update)
	stuck := s.isStuckRunning(update)

	payload := map[string]any{
		"current_version":     current["version"],
		"version_source":      current["source"],
		"installed_at":        install["completed_at"],
		"can_update":          s.canUpdate(install),
		"status":              ternary(running, "running", s.normalizeStatus(strVal(update["status"]))),
		"running":             running,
		"stuck":               stuck,
		"can_retry_stuck":     stuck && s.canUpdate(install),
		"can_clear_log":       !running || stuck,
		"target_version":      update["target_version"],
		"started_at":          update["started_at"],
		"finished_at":         update["finished_at"],
		"message":             s.localizedStatusMessage(locale, update, running),
		"log_tail":            s.readLogTail(logTailLines),
		"latest_version":      nil,
		"update_available":    false,
		"release_checked_at":  nil,
		"release_check_error": nil,
	}
	if checkRelease {
		for k, v := range s.fetchLatestRelease(locale, strPtr(current["version"])) {
			payload[k] = v
		}
	}
	return payload
}

func (s *Service) CheckForUpdates(locale string) map[string]any {
	return s.Status(locale, true)
}

func (s *Service) Start(locale, targetVersion string) (map[string]any, error) {
	status := s.Status(locale, true)
	if !boolVal(status["can_update"]) {
		return nil, fmt.Errorf("update_not_available")
	}
	if boolVal(status["running"]) {
		return nil, fmt.Errorf("update_already_running")
	}
	resolved := strings.TrimPrefix(strings.TrimSpace(targetVersion), "v")
	if resolved == "" {
		resolved = strings.TrimPrefix(strings.TrimSpace(strVal(status["latest_version"])), "v")
	}
	if !s.isNewerVersion(resolved, strVal(status["current_version"])) {
		return nil, fmt.Errorf("update_not_available")
	}
	if _, err := s.PanelOps.StartUpdate(resolved); err != nil {
		return nil, err
	}
	out := s.Status(locale, false)
	out["status"] = "running"
	out["running"] = true
	out["stuck"] = false
	out["can_retry_stuck"] = false
	out["can_clear_log"] = false
	out["target_version"] = resolved
	out["message"] = i18n.T(locale, "settings.update_message_started")
	out["log_tail"] = s.readLogTail(logTailLines)
	return out, nil
}

func (s *Service) ClearLog(locale string) (map[string]any, error) {
	status := s.Status(locale, false)
	if !boolVal(status["can_clear_log"]) {
		return nil, fmt.Errorf("update_log_clear_blocked")
	}
	path := s.HostGUIDir + "/update.log"
	cleared := false
	if st, err := os.Stat(s.HostGUIDir); err == nil && st.IsDir() {
		if _, err := os.Stat(path); os.IsNotExist(err) {
			cleared = true
		} else if err := os.WriteFile(path, []byte{}, 0644); err == nil {
			cleared = true
		}
	}
	if !cleared {
		if _, err := s.PanelOps.ClearUpdateLog(); err != nil {
			return nil, fmt.Errorf("update_log_clear_failed")
		}
	}
	out := s.Status(locale, false)
	out["message"] = i18n.T(locale, "settings.update_log_cleared")
	out["log_tail"] = ""
	return out, nil
}

func (s *Service) ReadFullLog() ([]byte, error) {
	path := s.HostGUIDir + "/update.log"
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return []byte{}, nil
		}
		return nil, err
	}
	return raw, nil
}

func (s *Service) RetryStuck(locale, targetVersion string) (map[string]any, error) {
	update := s.readJSONFile(s.HostGUIDir + "/update.state")
	if !s.isStuckRunning(update) {
		return nil, fmt.Errorf("update_not_stuck")
	}
	previous := strings.TrimPrefix(strings.TrimSpace(strVal(update["target_version"])), "v")
	if previous == "" {
		previous = ""
	}
	s.persistUpdateState(map[string]any{
		"pid":            0,
		"status":         "failed",
		"target_version": nilIfEmpty(previous),
		"started_at":     update["started_at"],
		"finished_at":    time.Now().UTC().Format("2006-01-02T15:04:05Z"),
		"message":        "Update marked stuck and reset for retry.",
	})
	resolved := strings.TrimPrefix(strings.TrimSpace(targetVersion), "v")
	if resolved == "" {
		resolved = previous
	}
	return s.Start(locale, resolved)
}

func (s *Service) canUpdate(install map[string]string) bool {
	bundle := strings.TrimSpace(install["bundle_version"])
	st, err := os.Stat(s.HostGUIDir)
	return err == nil && st.IsDir() && bundle != "source" && s.Token != ""
}

func (s *Service) detectCurrentVersion(install map[string]string) map[string]any {
	bundle := strings.TrimSpace(install["bundle_version"])
	if bundle != "" && bundle != "unknown" {
		return map[string]any{"version": bundle, "source": "install_state"}
	}
	compose := s.HostComposeDir + "/docker-compose.yml"
	if raw, err := os.ReadFile(compose); err == nil {
		re := regexp.MustCompile(`image:\s*awggui-app:([^\s]+)`)
		if m := re.FindStringSubmatch(string(raw)); m != nil {
			return map[string]any{"version": strings.TrimSpace(m[1]), "source": "compose"}
		}
	}
	return map[string]any{"version": nil, "source": "unknown"}
}

func (s *Service) readKeyValueFile(path string) map[string]string {
	out := map[string]string{}
	raw, err := os.ReadFile(path)
	if err != nil {
		return out
	}
	for _, line := range strings.Split(string(raw), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || !strings.Contains(line, "=") {
			continue
		}
		k, v, _ := strings.Cut(line, "=")
		k = strings.TrimSpace(k)
		if k == "" {
			continue
		}
		out[k] = strings.TrimSpace(v)
	}
	return out
}

func (s *Service) readJSONFile(path string) map[string]any {
	raw, err := os.ReadFile(path)
	if err != nil {
		return map[string]any{}
	}
	var m map[string]any
	if json.Unmarshal(raw, &m) != nil || m == nil {
		return map[string]any{}
	}
	return m
}

func (s *Service) isRunningState(update map[string]any) bool {
	return strVal(update["status"]) == "running"
}

func (s *Service) updateLogActive() bool {
	st, err := os.Stat(s.HostGUIDir + "/update.log")
	if err != nil {
		return false
	}
	return time.Since(st.ModTime()) < logActiveWindow
}

func (s *Service) isStuckRunning(update map[string]any) bool {
	if !s.isRunningState(update) {
		return false
	}
	// Slow downloads keep writing progress into update.log — not stuck.
	if s.updateLogActive() {
		return false
	}
	started := strings.TrimSpace(strVal(update["started_at"]))
	if started == "" {
		return false
	}
	ts, err := parseTime(started)
	if err != nil {
		return false
	}
	return time.Since(ts) >= stuckAfterSeconds*time.Second
}

func (s *Service) reconcileUpdateState(update map[string]any, install map[string]string) map[string]any {
	if strVal(update["status"]) != "running" {
		return update
	}
	target := strings.TrimPrefix(strings.TrimSpace(strVal(update["target_version"])), "v")
	installed := strings.TrimPrefix(strings.TrimSpace(install["bundle_version"]), "v")
	if installed == "" || installed == "unknown" {
		installed = strings.TrimPrefix(strings.TrimSpace(strVal(s.detectCurrentVersion(install)["version"])), "v")
	}
	completedAt := strings.TrimSpace(install["completed_at"])
	startedAt := strings.TrimSpace(strVal(update["started_at"]))
	if target != "" && installed != "" && installed != "unknown" && target == installed && completedAt != "" && startedAt != "" && completedAt >= startedAt {
		update["status"] = "success"
		update["finished_at"] = completedAt
		update["message"] = "Update completed successfully."
		s.persistUpdateState(update)
		return update
	}
	if ts, err := parseTime(startedAt); err == nil && time.Since(ts) > timeoutAfterSeconds*time.Second {
		// Do not fail a slow-but-alive install (log still progressing).
		if s.updateLogActive() {
			return update
		}
		update["status"] = "failed"
		update["finished_at"] = time.Now().UTC().Format("2006-01-02T15:04:05Z")
		update["message"] = "Update timed out or was interrupted."
		s.persistUpdateState(update)
	}
	return update
}

func (s *Service) persistUpdateState(state map[string]any) {
	path := s.HostGUIDir + "/update.state"
	b, err := json.MarshalIndent(state, "", "    ")
	if err != nil {
		return
	}
	_ = os.WriteFile(path, append(b, '\n'), 0644)
}

func (s *Service) normalizeStatus(status string) string {
	v := strings.TrimSpace(status)
	switch v {
	case "running", "success", "failed":
		return v
	default:
		return "idle"
	}
}

func (s *Service) localizedStatusMessage(locale string, update map[string]any, running bool) string {
	if running {
		custom := strings.TrimSpace(strVal(update["message"]))
		if custom != "" {
			return custom
		}
		return i18n.T(locale, "settings.update_message_running")
	}
	custom := strings.TrimSpace(strVal(update["message"]))
	st := s.normalizeStatus(strVal(update["status"]))
	if custom != "" && st == "failed" {
		return custom
	}
	switch st {
	case "success":
		return i18n.T(locale, "settings.update_message_success")
	case "failed":
		return i18n.T(locale, "settings.update_message_failed")
	default:
		if custom != "" {
			return custom
		}
		return i18n.T(locale, "settings.update_message_idle")
	}
}

func (s *Service) fetchLatestRelease(locale, currentVersion string) map[string]any {
	checkedAt := time.Now().Format(time.RFC3339)
	repo := s.GitHubRepo
	if repo == "" {
		repo = githubRepoDefault
	}
	url := "https://api.github.com/repos/" + repo + "/releases/latest"
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return releaseErr(locale, checkedAt)
	}
	req.Header.Set("Accept", "application/vnd.github+json")
	req.Header.Set("User-Agent", "awg-gui-panel")
	resp, err := s.HTTP.Do(req)
	if err != nil {
		return releaseErr(locale, checkedAt)
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return releaseErr(locale, checkedAt)
	}
	raw, _ := io.ReadAll(resp.Body)
	var payload map[string]any
	if json.Unmarshal(raw, &payload) != nil {
		return releaseErr(locale, checkedAt)
	}
	tag := strings.TrimSpace(strVal(payload["tag_name"]))
	latest := strings.TrimPrefix(tag, "v")
	var latestAny any
	if latest != "" {
		latestAny = latest
	}
	return map[string]any{
		"latest_version":      latestAny,
		"update_available":    s.isNewerVersion(latest, currentVersion),
		"release_checked_at":  checkedAt,
		"release_check_error": nil,
	}
}

func releaseErr(locale, checkedAt string) map[string]any {
	return map[string]any{
		"latest_version":      nil,
		"update_available":    false,
		"release_checked_at":  checkedAt,
		"release_check_error": i18n.T(locale, "settings.update_release_fetch_failed"),
	}
}

func (s *Service) isNewerVersion(latest, current string) bool {
	latest = strings.TrimPrefix(strings.TrimSpace(latest), "v")
	if latest == "" {
		return false
	}
	current = strings.TrimPrefix(strings.TrimSpace(current), "v")
	if current == "" || current == "source" || current == "unknown" {
		return true
	}
	if latest == current {
		return false
	}
	verRE := regexp.MustCompile(`^\d+(\.\d+)*([.-][\w.-]+)?$`)
	if verRE.MatchString(latest) && verRE.MatchString(current) {
		return versionCompare(latest, current) > 0
	}
	return latest != current
}

func (s *Service) readLogTail(maxLines int) string {
	path := s.HostGUIDir + "/update.log"
	raw, err := os.ReadFile(path)
	if err != nil || len(raw) == 0 {
		return ""
	}
	text := strings.ReplaceAll(string(raw), "\r\n", "\n")
	text = strings.ReplaceAll(text, "\r", "\n")
	var lines []string
	for _, line := range strings.Split(text, "\n") {
		if line != "" {
			lines = append(lines, line)
		}
	}
	if len(lines) == 0 {
		return ""
	}
	if len(lines) > maxLines {
		lines = lines[len(lines)-maxLines:]
	}
	return strings.Join(lines, "\n")
}

func parseTime(s string) (time.Time, error) {
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05Z", "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, s); err == nil {
			return t, nil
		}
	}
	return time.Time{}, fmt.Errorf("bad time")
}

func strVal(v any) string {
	if v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	default:
		return fmt.Sprint(t)
	}
}

func strPtr(v any) string {
	if v == nil {
		return ""
	}
	return strVal(v)
}

func boolVal(v any) bool {
	b, _ := v.(bool)
	return b
}

func ternary(cond bool, a, b string) string {
	if cond {
		return a
	}
	return b
}

func nilIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func versionCompare(a, b string) int {
	as := strings.Split(a, ".")
	bs := strings.Split(b, ".")
	n := len(as)
	if len(bs) > n {
		n = len(bs)
	}
	for i := 0; i < n; i++ {
		var ai, bi int
		if i < len(as) {
			fmt.Sscanf(nonDigitPrefix(as[i]), "%d", &ai)
		}
		if i < len(bs) {
			fmt.Sscanf(nonDigitPrefix(bs[i]), "%d", &bi)
		}
		if ai > bi {
			return 1
		}
		if ai < bi {
			return -1
		}
	}
	if a == b {
		return 0
	}
	if a > b {
		return 1
	}
	return -1
}

func nonDigitPrefix(s string) string {
	s = strings.SplitN(s, "-", 2)[0]
	s = strings.SplitN(s, "+", 2)[0]
	return s
}

package system

import (
	"context"
	"os"
	"strings"
	"time"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/docker"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
)

const (
	restartLockKey = "awg_restarting"
	restartLockTTL = 120 * time.Second
)

type Service struct {
	Cfg    config.Config
	Docker *docker.Runtime
	Stats  *stats.Service
	Host   *HostMetrics
	Cache  *store.Cache
}

func New(cfg config.Config, d *docker.Runtime, st *stats.Service, host *HostMetrics, cache *store.Cache) *Service {
	return &Service{Cfg: cfg, Docker: d, Stats: st, Host: host, Cache: cache}
}

func (s *Service) Status(ctx context.Context, locale string) map[string]any {
	awgRunning := s.Stats.IsContainerRunning(ctx, "")
	statsAvailable := false
	if awgRunning {
		statsAvailable = s.Stats.ProbeStatsAvailable(ctx)
	}
	var messages []string
	if !awgRunning {
		messages = append(messages, i18n.T(locale, "system.awg_not_running"))
	} else if !statsAvailable {
		messages = append(messages, i18n.T(locale, "system.awg_stats_unavailable"))
	} else {
		messages = append(messages, i18n.T(locale, "system.awg_ok"))
	}
	return map[string]any{
		"ok":             awgRunning,
		"awg_restarting": s.IsAwgRestarting(ctx),
		"services": map[string]any{
			"app":   map[string]any{"ok": true},
			"db":    map[string]any{"ok": true},
			"awg":   map[string]any{"ok": awgRunning, "running": awgRunning},
			"stats": map[string]any{"ok": statsAvailable, "available": statsAvailable},
		},
		"messages": messages,
	}
}

func (s *Service) IsAwgRestarting(ctx context.Context) bool {
	if s.Cache == nil {
		return false
	}
	return s.Cache.Has(ctx, restartLockKey)
}

func (s *Service) RestartAWG(ctx context.Context, locale string) (int, map[string]any) {
	if s.Cache != nil && !s.Cache.Add(ctx, restartLockKey, store.UnixString(time.Now()), restartLockTTL) {
		return 409, map[string]any{
			"ok":                 false,
			"already_restarting": true,
			"message":            i18n.T(locale, "api.awg_restart_already_running"),
			"details": map[string]any{
				"ok":                 false,
				"already_restarting": true,
				"exit_code":          nil,
				"stderr":             "",
			},
		}
	}
	defer func() {
		if s.Cache != nil {
			s.Cache.Forget(ctx, restartLockKey)
		}
	}()

	result := s.Docker.Restart(ctx, s.Stats.ContainerName(), 60*time.Second)
	details := map[string]any{
		"ok":        result.Successful(),
		"exit_code": result.ExitCode,
		"stderr":    strings.TrimSpace(result.Stderr),
	}
	if !result.Successful() {
		return 500, map[string]any{
			"ok":      false,
			"message": i18n.T(locale, "api.awg_restart_failed"),
			"details": details,
		}
	}
	return 200, map[string]any{
		"ok":      true,
		"message": i18n.T(locale, "api.awg_restart_ok"),
		"details": details,
	}
}

func (s *Service) RestartSingBox(ctx context.Context, locale string) (int, map[string]any) {
	configPath := s.Stats.ConfigDir() + "/sing-box.json"
	configExists := fileExists(configPath)
	container := s.Stats.ContainerName()

	if !s.Stats.IsContainerRunning(ctx, "") {
		body := map[string]any{
			"ok":            false,
			"running":       false,
			"config_exists": configExists,
			"message":       i18n.T(locale, "system.awg_container_not_running"),
			"check_output":  "",
			"reload_output": "",
		}
		return 422, withDetails(body)
	}
	if !configExists {
		body := map[string]any{
			"ok":            false,
			"running":       false,
			"config_exists": false,
			"message":       i18n.T(locale, "system.singbox_json_not_found"),
			"check_output":  "",
			"reload_output": "",
		}
		return 422, withDetails(body)
	}

	check := s.Docker.Exec(ctx, container, []string{"/usr/local/bin/sing-box", "check", "-c", "/config/sing-box.json"}, 20*time.Second, "")
	checkOut := strings.TrimSpace(check.Stderr + "\n" + check.Stdout)
	if !check.Successful() {
		msg := checkOut
		if msg == "" {
			msg = i18n.T(locale, "system.singbox_check_failed")
		}
		running := s.IsSingBoxRunning(ctx)
		body := map[string]any{
			"ok":            false,
			"running":       running,
			"config_exists": true,
			"message":       msg,
			"check_output":  checkOut,
			"reload_output": "",
		}
		return 422, withDetails(body)
	}

	reload := s.Docker.Exec(ctx, container, []string{"sh", "-c", "if [ -x /config/reload-singbox.sh ]; then /config/reload-singbox.sh; else /usr/local/bin/reload-singbox.sh; fi"}, 30*time.Second, "")
	reloadOut := strings.TrimSpace(reload.Stderr + "\n" + reload.Stdout)
	running := s.IsSingBoxRunning(ctx)
	if !reload.Successful() || !running {
		msg := reloadOut
		if msg == "" {
			msg = i18n.T(locale, "system.singbox_restart_failed")
		}
		body := map[string]any{
			"ok":            false,
			"running":       running,
			"config_exists": true,
			"message":       msg,
			"check_output":  checkOut,
			"reload_output": reloadOut,
		}
		return 422, withDetails(body)
	}
	body := map[string]any{
		"ok":            true,
		"running":       true,
		"config_exists": true,
		"message":       i18n.T(locale, "system.singbox_restart_ok"),
		"check_output":  checkOut,
		"reload_output": reloadOut,
	}
	return 200, withDetails(body)
}

func withDetails(body map[string]any) map[string]any {
	body["details"] = map[string]any{
		"ok":            body["ok"],
		"running":       body["running"],
		"config_exists": body["config_exists"],
		"message":       body["message"],
		"check_output":  body["check_output"],
		"reload_output": body["reload_output"],
	}
	return body
}

func (s *Service) IsSingBoxRunning(ctx context.Context) bool {
	if !s.Stats.IsContainerRunning(ctx, "") {
		return false
	}
	script := `pid=$(cat /run/sing-box.pid 2>/dev/null); if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then echo yes; elif pgrep -f "/usr/local/bin/sing-box run -c /config/sing-box.json" >/dev/null 2>&1; then echo yes; else echo no; fi`
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script}, 10*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

func (s *Service) RestartAll() map[string]any {
	_ = s.Docker.Start([]string{"restart", s.Stats.ContainerName(), "awggui-caddy"})
	return map[string]any{
		"ok":      true,
		"message": i18n.T(s.Cfg.AppLocale, "system.restart_all_started"),
	}
}

func (s *Service) RestartAllLocalized(locale string) map[string]any {
	_ = s.Docker.Start([]string{"restart", s.Stats.ContainerName(), "awggui-caddy"})
	return map[string]any{
		"ok":      true,
		"message": i18n.T(locale, "system.restart_all_started"),
	}
}

func fileExists(path string) bool {
	st, err := os.Stat(path)
	return err == nil && !st.IsDir()
}

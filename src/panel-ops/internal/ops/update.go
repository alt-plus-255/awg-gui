package ops

import (
	"encoding/base64"
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"strings"
	"sync"
)

var updateMu sync.Mutex

func UpdateStatePath() string {
	return Env("AWG_GUI_UPDATE_STATE_PATH", "/host-awg-gui/update.state")
}

func UpdateLogPath() string {
	return Env("AWG_GUI_UPDATE_LOG_PATH", "/host-awg-gui/update.log")
}

func IsUpdateRunning(state map[string]any) bool {
	if AsString(state["status"]) != "running" {
		return false
	}
	return ProcAlive(AsInt(state["pid"]))
}

func ClearUpdateLog() map[string]any {
	path := UpdateLogPath()
	if err := TruncateLog(path); err != nil {
		if os.IsNotExist(err) {
			return map[string]any{"ok": false, "error": "Host GUI directory is missing.", "status": 500}
		}
		return map[string]any{"ok": false, "error": "Failed to clear update log.", "status": 500}
	}
	return map[string]any{"ok": true, "status": 200}
}

var versionRe = regexp.MustCompile(`^[0-9A-Za-z._-]+$`)

func StartUpdate(version *string) map[string]any {
	updateMu.Lock()
	defer updateMu.Unlock()

	current := ReadJSONMap(UpdateStatePath())
	if IsUpdateRunning(current) {
		return map[string]any{"ok": false, "error": "Update is already running.", "status": 409}
	}

	var target *string
	if version != nil {
		v := strings.TrimPrefix(strings.TrimSpace(*version), "v")
		if v != "" {
			target = &v
		}
	}

	pid := os.Getpid()
	startedAt := IsoNow()
	msg := "Updating to the latest release..."
	if target != nil {
		msg = fmt.Sprintf("Updating to %s...", *target)
	}
	state := map[string]any{
		"pid":            pid,
		"status":         "running",
		"target_version": nil,
		"started_at":     startedAt,
		"finished_at":    nil,
		"message":        msg,
	}
	if target != nil {
		state["target_version"] = *target
	}
	WriteJSONMap(UpdateStatePath(), state)

	logPath := UpdateLogPath()
	_ = os.WriteFile(logPath, []byte("["+startedAt+"] update started\n"), 0o666)
	_ = os.Chmod(logPath, 0o666)

	go runUpdate(target)

	out := map[string]any{
		"ok":      true,
		"status":  202,
		"pid":     pid,
		"message": "Update has started.",
	}
	if target != nil {
		out["message"] = "Update has started for the requested version."
	}
	return out
}

func runUpdate(target *string) {
	statePath := UpdateStatePath()
	logPath := UpdateLogPath()

	state := map[string]any{
		"pid":            os.Getpid(),
		"status":         "running",
		"target_version": nil,
		"started_at":     IsoNow(),
		"finished_at":    nil,
		"message":        "Updating to the latest release...",
	}
	if target != nil {
		state["target_version"] = *target
		state["message"] = fmt.Sprintf("Updating to %s...", *target)
	}
	WriteJSONMap(statePath, state)

	RotateLogIfHuge(logPath, 10*1024*1024)
	_ = os.WriteFile(logPath, []byte("["+IsoNow()+"] update started\n"), 0o666)
	_ = os.Chmod(logPath, 0o666)

	if err := executeUpdate(target, logPath); err != nil {
		AppendLog(logPath, "["+IsoNow()+"] "+err.Error()+"\n")
		state["status"] = "failed"
		state["finished_at"] = IsoNow()
		state["message"] = err.Error()
		WriteJSONMap(statePath, state)
		return
	}

	AppendLog(logPath, "["+IsoNow()+"] host update unit started (awg-gui-update.service); waiting for installer to finish\n")
}

func executeUpdate(target *string, logPath string) error {
	repo := Env("AWG_GUI_GITHUB_REPO", "alt-plus-255/awg-gui")
	installURL := fmt.Sprintf("https://raw.githubusercontent.com/%s/refs/heads/main/dist/install.sh", repo)

	if target != nil && !versionRe.MatchString(*target) {
		return fmt.Errorf("Invalid update version.")
	}

	bootstrap := buildHostUpdateBootstrap(target, installURL)
	helper := "set -eu; apk add --no-cache util-linux >/dev/null; " +
		"nsenter -t 1 -m -u -i -n -p -- /bin/bash -lc " + ShellQuote(bootstrap)

	cmd := exec.Command(
		"docker", "run", "--rm",
		"--privileged", "--pid=host", "--network", "host",
		alpineImage, "sh", "-lc", helper,
	)
	f, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o666)
	if err != nil {
		return fmt.Errorf("Failed to open update log: %w", err)
	}
	defer f.Close()
	cmd.Stdout = f
	cmd.Stderr = f

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("Update helper container exited with error: %w", err)
	}
	return nil
}

func buildHostUpdateJobScript(version *string, installURL string) string {
	var b strings.Builder
	b.WriteString("#!/bin/bash\n")
	b.WriteString("set -euxo pipefail\n")
	b.WriteString("mkdir -p /etc/awg-gui\n")
	b.WriteString("touch /etc/awg-gui/update.log\n")
	b.WriteString("chmod 666 /etc/awg-gui/update.log\n")
	b.WriteString("exec >>/etc/awg-gui/update.log 2>&1\n")
	b.WriteString("echo \"[$(date -u +%Y-%m-%dT%H:%M:%SZ)] host update job running\"\n")
	if version != nil && *version != "" {
		b.WriteString("export AWG_GUI_VERSION=" + ShellQuote(*version) + "\n")
	}
	b.WriteString(`install_args=(--yes)
kernel_present=0
if [[ -x /etc/awg-gui/awg-kernel-host.sh ]]; then
  st="$(/etc/awg-gui/awg-kernel-host.sh status 2>/dev/null || true)"
  if echo "$st" | grep -qE '"package_installed":true|"module_loaded":true'; then
    kernel_present=1
  fi
fi
wanted="$(grep -E '^AWG_KERNEL_WANTED=' /opt/awg-gui/runtime/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || true)"
if [[ "$kernel_present" -ne 1 && "$wanted" != "1" ]]; then
  echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] AmneziaWG kernel not installed - skipping forced kernel install"
  install_args+=(--no-awg-kernel)
fi
tmp="$(mktemp /tmp/awg-gui-install.XXXXXX)"
trap 'rm -f "$tmp" /etc/awg-gui/update-job.sh; find /tmp -maxdepth 1 -type d \( -name "awg-gui-install.*" -o -name "awg-gui-extract.*" \) -exec rm -rf {} + 2>/dev/null || true' EXIT
curl -fsSL ` + ShellQuote(installURL) + ` -o "$tmp"
/bin/bash "$tmp" "${install_args[@]}"
`)
	return b.String()
}

func buildHostUpdateBootstrap(version *string, installURL string) string {
	jobB64 := base64.StdEncoding.EncodeToString([]byte(buildHostUpdateJobScript(version, installURL)))
	return `set -euo pipefail
mkdir -p /etc/awg-gui
echo ` + jobB64 + ` | base64 -d > /etc/awg-gui/update-job.sh
chmod 700 /etc/awg-gui/update-job.sh
if systemctl is-active --quiet awg-gui-update.service; then
  echo 'awg-gui-update.service is already active' >&2
  exit 1
fi
systemctl stop awg-gui-update.service >/dev/null 2>&1 || true
systemctl reset-failed awg-gui-update.service >/dev/null 2>&1 || true
systemd-run --no-block --collect --unit=awg-gui-update.service \
  --property=Type=oneshot \
  --property=TimeoutStartSec=infinity \
  --property=TimeoutStopSec=300 \
  /bin/bash /etc/awg-gui/update-job.sh
`
}

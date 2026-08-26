package ops

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"sync"
	"time"
)

var kernelMu sync.Mutex

func KernelStatePath() string {
	return Env("AWG_GUI_KERNEL_STATE_PATH", "/host-awg-gui/awg-kernel.state")
}

func KernelLogPath() string {
	return Env("AWG_GUI_KERNEL_LOG_PATH", "/host-awg-gui/awg-kernel.log")
}

func kernelHostScript() string {
	return "/etc/awg-gui/awg-kernel-host.sh"
}

func IsKernelOpRunning(state map[string]any) bool {
	if AsString(state["status"]) != "running" {
		return false
	}
	pid := AsInt(state["pid"])
	if pid > 0 && ProcAlive(pid) {
		return true
	}
	startedAt := AsString(state["started_at"])
	if startedAt == "" {
		return false
	}
	t, err := time.Parse(time.RFC3339, startedAt)
	if err != nil {
		return false
	}
	return time.Since(t) < 30*time.Minute
}

func AWGKernelStatus() map[string]any {
	hostCmd := ShellQuote(kernelHostScript()) + " status"
	stdout, stderr, err := RunNsenterBashCapture(120*time.Second, hostCmd)

	opState := ReadJSONMap(KernelStatePath())
	running := IsKernelOpRunning(opState)

	opBlock := map[string]any{
		"status":      "idle",
		"message":     AsString(opState["message"]),
		"op":          AsString(opState["op"]),
		"started_at":  opState["started_at"],
		"finished_at": opState["finished_at"],
		"running":     running,
	}
	if running {
		opBlock["status"] = "running"
	} else if s := AsString(opState["status"]); s != "" {
		opBlock["status"] = s
	}

	var host map[string]any
	_ = json.Unmarshal([]byte(strings.TrimSpace(stdout)), &host)
	if err != nil || host == nil {
		detail := strings.TrimSpace(stderr)
		if detail == "" {
			detail = strings.TrimSpace(stdout)
		}
		return map[string]any{
			"ok":                 true,
			"status":             200,
			"module_loaded":      false,
			"package_installed":  false,
			"module_blacklisted": false,
			"awg_datapath":       "unknown",
			"os_family":          "unknown",
			"detail":             detail,
			"script_present":     false,
			"op":                 opBlock,
		}
	}

	datapath := stringOr(host["awg_datapath"], "unknown")
	moduleLoaded := AsBool(host["module_loaded"])
	moduleBlacklisted := AsBool(host["module_blacklisted"])
	// Don't keep a stale "using kernel datapath" success after live status shows userspace.
	if !running && AsString(opBlock["status"]) == "ok" {
		msg := AsString(opBlock["message"])
		if datapath == "userspace" || !moduleLoaded {
			if strings.Contains(msg, "kernel datapath") || strings.Contains(msg, "using kernel") {
				opBlock["message"] = "Package installed, but AWG datapath is userspace (module not loaded or amneziawg-go still running). Run: modprobe amneziawg && docker restart awggui-awg"
				opBlock["status"] = "error"
			}
		}
	}
	if moduleBlacklisted && !running {
		opBlock["message"] = "blacklist-amneziawg.conf present — module will not load after reboot (userspace fallback). Remove /etc/modprobe.d/blacklist-amneziawg.conf or re-run Install kernel module."
		if AsString(opBlock["status"]) == "ok" || AsString(opBlock["status"]) == "idle" {
			opBlock["status"] = "error"
		}
	}

	return map[string]any{
		"ok":                 true,
		"status":             200,
		"module_loaded":      moduleLoaded,
		"package_installed":  AsBool(host["package_installed"]),
		"module_blacklisted": moduleBlacklisted,
		"awg_datapath":       datapath,
		"os_family":          stringOr(host["os_family"], "unknown"),
		"detail":             AsString(host["detail"]),
		"script_present":     true,
		"op":                 opBlock,
	}
}

func stringOr(v any, fallback string) string {
	s := AsString(v)
	if s == "" {
		return fallback
	}
	return s
}

func StartAWGKernelOp(op string) map[string]any {
	if op != "install" && op != "uninstall" {
		return map[string]any{"ok": false, "error": "Invalid op", "status": 400}
	}

	kernelMu.Lock()
	defer kernelMu.Unlock()

	current := ReadJSONMap(KernelStatePath())
	if IsKernelOpRunning(current) {
		return map[string]any{"ok": false, "error": "Kernel module operation is already running.", "status": 409}
	}

	if _, err := os.Stat("/host-awg-gui/awg-kernel-host.sh"); err != nil {
		return map[string]any{
			"ok":     false,
			"error":  "Host script /etc/awg-gui/awg-kernel-host.sh is missing. Re-run the awg-gui installer.",
			"status": 503,
		}
	}

	pid := os.Getpid()
	startedAt := IsoNow()
	msg := "Removing AmneziaWG kernel module..."
	if op == "install" {
		msg = "Installing AmneziaWG kernel module..."
	}
	state := map[string]any{
		"pid":         pid,
		"status":      "running",
		"op":          op,
		"started_at":  startedAt,
		"finished_at": nil,
		"message":     msg,
	}
	WriteJSONMap(KernelStatePath(), state)

	go runKernelOp(op, pid, startedAt)

	outMsg := "Kernel module uninstall has started."
	if op == "install" {
		outMsg = "Kernel module install has started."
	}
	return map[string]any{
		"ok":      true,
		"status":  202,
		"pid":     pid,
		"message": outMsg,
	}
}

func runKernelOp(op string, pid int, startedAt string) {
	statePath := KernelStatePath()
	logPath := KernelLogPath()

	msg := "Removing AmneziaWG kernel module..."
	if op == "install" {
		msg = "Installing AmneziaWG kernel module..."
	}
	state := map[string]any{
		"pid":         pid,
		"status":      "running",
		"op":          op,
		"started_at":  startedAt,
		"finished_at": nil,
		"message":     msg,
	}
	WriteJSONMap(statePath, state)

	RotateLogIfHuge(logPath, 10*1024*1024)
	AppendLog(logPath, "["+IsoNow()+"] "+op+" started\n")

	hostCmd := ShellQuote(kernelHostScript()) + " " + ShellQuote(op)
	f, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o666)
	if err != nil {
		failKernel(statePath, state, err.Error())
		return
	}
	cmd := exec.Command(
		"docker", "run", "--rm",
		"--privileged", "--pid=host", "--network", "host",
		alpineImage, "sh", "-lc",
		"set -eu; apk add --no-cache util-linux >/dev/null; "+
			"nsenter -t 1 -m -u -i -n -p -- /bin/bash -lc "+ShellQuote(hostCmd),
	)
	cmd.Stdout = f
	cmd.Stderr = f
	runErr := cmd.Run()
	_ = f.Close()

	if runErr != nil {
		msg := fmt.Sprintf("Kernel helper exited with error: %v", runErr)
		AppendLog(logPath, "["+IsoNow()+"] "+msg+"\n")
		failKernel(statePath, state, msg)
		return
	}

	hostState := ReadJSONMap(statePath)
	hostStatus := AsString(hostState["status"])
	if hostStatus == "ok" || hostStatus == "error" {
		hostState["pid"] = pid
		hostState["op"] = op
		hostState["started_at"] = startedAt
		hostState["finished_at"] = IsoNow()
		WriteJSONMap(statePath, hostState)
		return
	}

	state["status"] = "ok"
	state["finished_at"] = IsoNow()
	if op == "install" {
		state["message"] = "Kernel module install finished."
	} else {
		state["message"] = "Kernel module uninstall finished."
	}
	WriteJSONMap(statePath, state)
}

func failKernel(statePath string, state map[string]any, message string) {
	state["status"] = "error"
	state["finished_at"] = IsoNow()
	state["message"] = message
	WriteJSONMap(statePath, state)
}

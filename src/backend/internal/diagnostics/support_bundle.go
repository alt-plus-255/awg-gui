package diagnostics

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"
)

var supportBundleContainers = []struct {
	name string
	tail int
}{
	{"awggui-awg", 2000},
	{"awggui-app", 500},
	{"awggui-panel-ops", 500},
	{"awggui-caddy", 500},
	{"awggui-docker-proxy", 500},
}

func (s *Service) SupportBundle(ctx context.Context, locale string, awgTail, otherTail int) ([]byte, string, error) {
	if awgTail <= 0 {
		awgTail = 2000
	}
	if otherTail <= 0 {
		otherTail = 500
	}
	var b strings.Builder
	now := time.Now().UTC()
	filename := fmt.Sprintf("awg-gui-support-%s.txt", now.Format("20060102-150405"))

	writeSection := func(title, body string) {
		b.WriteString("\n===== ")
		b.WriteString(title)
		b.WriteString(" =====\n")
		body = strings.TrimSpace(body)
		if body == "" {
			body = "(empty)"
		}
		b.WriteString(body)
		b.WriteByte('\n')
	}

	fmt.Fprintf(&b, "===== awg-gui support bundle =====\ngenerated_at: %s\npanel_version: %s\n",
		now.Format(time.RFC3339), strings.TrimSpace(s.Cfg.Version))

	// Kernel status JSON
	kernelJSON := "(panel-ops unavailable)"
	if s.PanelOps != nil {
		if data, err := s.PanelOps.AWGKernelStatus(); err == nil && data != nil {
			if raw, err := json.MarshalIndent(data, "", "  "); err == nil {
				kernelJSON = string(raw)
			}
		} else if err != nil {
			kernelJSON = err.Error()
		}
	}
	writeSection("kernel status (JSON)", kernelJSON)

	// Host files from mount
	hostDir := strings.TrimRight(s.Cfg.HostAWGGUIDir, "/")
	for _, name := range []string{"awg-kernel.state", "awg-kernel.log"} {
		path := hostDir + "/" + name
		raw, err := os.ReadFile(path)
		if err != nil {
			writeSection("host: "+name, fmt.Sprintf("(unreadable: %v)", err))
			continue
		}
		writeSection("host: "+name, string(raw))
	}

	// Host debug via panel-ops nsenter
	hostDebug := "(panel-ops unavailable)"
	if s.PanelOps != nil {
		if data, err := s.PanelOps.CollectHostDebug(); err == nil {
			if sections, ok := data["sections"].(map[string]any); ok {
				if h, ok := sections["host"].(string); ok {
					hostDebug = h
				}
			}
		} else {
			hostDebug = err.Error()
		}
	}
	writeSection("host debug (uname, lsmod, modinfo, blacklist, dmesg)", hostDebug)

	// Docker logs
	awgName := s.Stats.ContainerName()
	for _, c := range supportBundleContainers {
		name := c.name
		tail := c.tail
		if name == "awggui-awg" {
			name = awgName
			tail = awgTail
		} else {
			tail = otherTail
		}
		title := fmt.Sprintf("container %s: docker logs --tail %d", name, tail)
		if !s.Stats.IsContainerRunning(ctx, name) {
			writeSection(title, "(container not running)")
			continue
		}
		r := s.Docker.Logs(ctx, name, tail, 45*time.Second)
		body := strings.TrimSpace(r.Stdout)
		if body == "" && r.Stderr != "" {
			body = strings.TrimSpace(r.Stderr)
		}
		if body == "" && !r.Successful() {
			body = fmt.Sprintf("(docker logs failed, exit %d)", r.ExitCode)
		}
		writeSection(title, body)
	}

	// AWG container internals
	if s.Stats.IsContainerRunning(ctx, "") {
		for _, script := range []struct {
			title string
			cmd   string
		}{
			{"awggui-awg: awg-kernel-bad marker", `for f in /run/awg-kernel-bad /config/awg-kernel-bad; do if [ -f "$f" ]; then echo "=== $f ==="; cat "$f"; fi; done; if [ ! -f /run/awg-kernel-bad ] && [ ! -f /config/awg-kernel-bad ]; then echo "(no kernel-bad marker)"; fi`},
			{"awggui-awg: ps aux (amneziawg)", `ps aux 2>/dev/null | grep -E '[a]mneziawg|[a]wg-quick|[s]ing-box' || echo "(no matching processes)"`},
			{"awggui-awg: ip link (awg ifaces)", `ip -o link show 2>/dev/null | grep -E 'awg|wg' || echo "(no awg/wg ifaces)"`},
		} {
			r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script.cmd}, 15*time.Second, "")
			body := strings.TrimSpace(r.Stdout)
			if body == "" && r.Stderr != "" {
				body = strings.TrimSpace(r.Stderr)
			}
			writeSection(script.title, body)
		}
	} else {
		writeSection("awggui-awg: internal state", "(container not running)")
	}

	// Diagnostics snapshot
	snap := s.Status(ctx, locale)
	if raw, err := json.MarshalIndent(maskJSONSecrets(snap), "", "  "); err == nil {
		writeSection("diagnostics snapshot (JSON, secrets masked)", string(raw))
	}

	return []byte(b.String()), filename, nil
}

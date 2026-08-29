package diagnostics

import (
	"context"
	"strings"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

const streamingRTTThresholdMS = 200

func datapathShortLabel(locale, mode string) string {
	switch mode {
	case "kernel":
		return i18n.T(locale, "system.awg_datapath_kernel")
	case "userspace":
		return i18n.T(locale, "system.awg_datapath_userspace")
	default:
		return i18n.T(locale, "system.awg_datapath_unknown")
	}
}

func (s *Service) ifaceDatapath(ctx context.Context, iface string) string {
	if s.Docker == nil || !diagIfaceRE.MatchString(iface) || !s.Stats.IsContainerRunning(ctx, "") {
		return "unknown"
	}
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c",
		`IFACE="` + iface + `"
if pgrep -f "amneziawg-go ${IFACE}" >/dev/null 2>&1; then
  echo userspace
elif ip link show "${IFACE}" >/dev/null 2>&1 && { [ -d /sys/module/amneziawg ] || lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg; }; then
  echo kernel
else
  echo unknown
fi`},
		5*time.Second, "")
	if !r.Successful() {
		return "unknown"
	}
	v := strings.TrimSpace(r.Stdout)
	if v == "kernel" || v == "userspace" {
		return v
	}
	return "unknown"
}

func (s *Service) awgDatapath(ctx context.Context) string {
	if s.Docker == nil || !s.Stats.IsContainerRunning(ctx, "") {
		return "unknown"
	}
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c",
		`if ps aux 2>/dev/null | grep -q '[a]mneziawg-go '; then echo userspace; exit 0; fi
for iface in $(ip -o link show 2>/dev/null | awk -F': ' '{print $2}' | grep -E '^awg[0-9]+$'); do
  if pgrep -f "amneziawg-go ${iface}" >/dev/null 2>&1; then echo userspace; exit 0; fi
done
if [ -d /sys/module/amneziawg ] || lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg; then echo kernel; else echo unknown; fi`},
		8*time.Second, "")
	if !r.Successful() {
		return "unknown"
	}
	v := strings.TrimSpace(r.Stdout)
	if v == "kernel" || v == "userspace" || v == "unknown" {
		return v
	}
	return "unknown"
}

func (s *Service) listUpAwgIfaces(ctx context.Context, ifaces []string) []string {
	var up []string
	for _, iface := range ifaces {
		if !diagIfaceRE.MatchString(iface) {
			continue
		}
		if s.Stats.IfaceIsUp(ctx, iface) {
			up = append(up, iface)
		}
	}
	return up
}

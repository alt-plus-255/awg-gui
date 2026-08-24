package resolver

import (
	"context"
	"regexp"
	"strings"
	"time"
)

type Egress struct {
	Docker    Docker
	Container string
	KV        *KV
	resolved  *string
	detected  *string
	detDone   bool
}

var ifaceNameRE = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,14}$`)

func (e *Egress) SettingValue(ctx context.Context) string {
	raw := strings.TrimSpace(e.KV.Get(ctx, SettingEgress, "auto"))
	if raw == "" {
		return "auto"
	}
	return raw
}

func (e *Egress) Resolve(ctx context.Context) string {
	if e.resolved != nil {
		return *e.resolved
	}
	setting := e.SettingValue(ctx)
	if setting != "auto" && e.valid(setting) {
		e.resolved = &setting
		return setting
	}
	if d := e.DetectDefault(ctx); d != "" {
		e.resolved = &d
		return d
	}
	fb := EgressFallback
	e.resolved = &fb
	return fb
}

func (e *Egress) DetectDefault(ctx context.Context) string {
	if e.detDone {
		if e.detected == nil {
			return ""
		}
		return *e.detected
	}
	e.detDone = true
	if !e.Docker.ContainerRunning(ctx, e.Container) {
		return ""
	}
	script := `iface=$(ip -4 route show default 0.0.0.0/0 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}'); ` +
		`if [ -z "$iface" ]; then iface=$(ip -o -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}'); fi; echo "$iface"`
	r, err := e.Docker.Exec(ctx, e.Container, []string{"sh", "-c", script}, 8*time.Second)
	if err != nil {
		return ""
	}
	iface := strings.TrimSpace(r.Stdout)
	if e.valid(iface) && !e.isTunnel(iface) {
		e.detected = &iface
		return iface
	}
	return ""
}

func (e *Egress) valid(iface string) bool { return ifaceNameRE.MatchString(iface) }

func (e *Egress) isTunnel(iface string) bool {
	return regexp.MustCompile(`^(awg|awgc)\d+$`).MatchString(iface) || iface == TunIface
}

func (e *Egress) Forget() {
	e.resolved = nil
	e.detected = nil
	e.detDone = false
}

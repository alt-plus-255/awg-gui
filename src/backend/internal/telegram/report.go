package telegram

import (
	"context"
	"fmt"
	"html"
	"os"
	"strings"
	"time"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/store"
	"github.com/awggui/backend/internal/system"
)

type DailyReport struct {
	Settings *Settings
	Bot      *Client
	Host     *system.HostMetrics
	AWG      *awg.Service
	Configs  *store.Configs
	Peers    *store.Peers
	Clients  *store.Clients
}

func (d *DailyReport) Send(ctx context.Context) bool {
	if !d.Settings.IsConfigured(ctx) || !d.Settings.DailyReportEnabled(ctx) {
		return false
	}
	if !d.Bot.IsReady(ctx) {
		return false
	}
	text := d.BuildReport(ctx)
	d.Bot.SendMessage(ctx, d.Settings.AdminID(ctx), text, nil)
	return true
}

func (d *DailyReport) BuildReport(ctx context.Context) string {
	locale := d.Settings.Language(ctx)
	na := i18n.T(locale, "telegram.dashboard_na")
	now := time.Now().Format("2006-01-02 15:04:05")
	hostname, _ := os.Hostname()
	if hostname == "" {
		hostname = na
	}
	var host map[string]any
	if d.Host != nil {
		host = d.Host.Collect()
	}
	uptime := formatUptime(locale, host["uptime_seconds"])
	load := formatLoad(locale, asMap(host["loadavg"]))
	cpu := formatPercent(locale, nested(host, "cpu", "percent"))
	memUsed := formatBytes(locale, nested(host, "memory", "used"))
	memTotal := formatBytes(locale, nested(host, "memory", "total"))
	memPercent := formatPercent(locale, nested(host, "memory", "percent"))
	disk := formatPercent(locale, nested(host, "disk", "percent"))

	peersTotal, _ := d.Clients.Count(ctx)
	enabled, _ := d.Peers.CountEnabled(ctx, nil)
	memberships, _ := d.Peers.ListAll(ctx)
	online := 0
	var rx, tx int64
	for _, m := range memberships {
		if m.Online != nil && *m.Online {
			online++
		}
		rx += m.TransferRx
		tx += m.TransferTx
	}

	awgStatus := na
	endpoint := na
	if d.AWG.IsContainerRunning(ctx) {
		awgStatus = i18n.T(locale, "telegram.dashboard_awg_up")
	} else {
		awgStatus = i18n.T(locale, "telegram.dashboard_awg_down")
	}
	if st, err := d.AWG.EndpointStatus(ctx, ""); err == nil {
		if ep := strings.TrimSpace(asStr(st["endpoint"])); ep != "" {
			endpoint = ep
		}
	}

	configs, _ := d.Configs.All(ctx)
	resolverCount := 0
	for _, c := range configs {
		if c.Type == "server" && c.ResolverEnabled {
			resolverCount++
		}
	}

	lines := []string{
		i18n.T(locale, "telegram.daily_report_title"),
		i18n.Tf(locale, "telegram.daily_report_datetime", map[string]string{"datetime": now}),
		i18n.Tf(locale, "telegram.daily_report_hostname", map[string]string{"hostname": html.EscapeString(hostname)}),
		i18n.Tf(locale, "telegram.daily_report_uptime", map[string]string{"uptime": uptime}),
		i18n.Tf(locale, "telegram.daily_report_load", map[string]string{"load": load}),
		i18n.Tf(locale, "telegram.daily_report_cpu", map[string]string{"cpu": cpu}),
		i18n.Tf(locale, "telegram.daily_report_memory", map[string]string{"used": memUsed, "total": memTotal, "percent": memPercent}),
		i18n.Tf(locale, "telegram.daily_report_disk", map[string]string{"percent": disk}),
		i18n.Tf(locale, "telegram.daily_report_peers", map[string]string{
			"peers": itoa(peersTotal), "enabled": itoa(enabled), "online": itoa(online),
		}),
		i18n.Tf(locale, "telegram.daily_report_traffic", map[string]string{
			"total": formatBytes(locale, rx+tx), "tx": formatBytes(locale, tx), "rx": formatBytes(locale, rx),
		}),
		i18n.Tf(locale, "telegram.daily_report_awg", map[string]string{"status": awgStatus, "endpoint": html.EscapeString(endpoint)}),
		i18n.Tf(locale, "telegram.daily_report_resolver", map[string]string{"count": itoa(resolverCount)}),
	}
	return strings.Join(lines, "\n")
}

func formatUptime(locale string, seconds any) string {
	if seconds == nil {
		return i18n.T(locale, "telegram.dashboard_na")
	}
	var n int
	switch t := seconds.(type) {
	case int:
		n = t
	case int64:
		n = int(t)
	case float64:
		n = int(t)
	default:
		return i18n.T(locale, "telegram.dashboard_na")
	}
	if n < 0 {
		n = 0
	}
	days := n / 86400
	hours := (n % 86400) / 3600
	mins := (n % 3600) / 60
	if days > 0 {
		return fmt.Sprintf("%d d %02d:%02d", days, hours, mins)
	}
	return fmt.Sprintf("%02d:%02d", hours, mins)
}

func formatLoad(locale string, load map[string]any) string {
	if load == nil {
		return i18n.T(locale, "telegram.dashboard_na")
	}
	a, b, c := load["1"], load["5"], load["15"]
	if a == nil && b == nil && c == nil {
		return i18n.T(locale, "telegram.dashboard_na")
	}
	return fmt.Sprintf("%s, %s, %s", fmtFloat(a), fmtFloat(b), fmtFloat(c))
}

func fmtFloat(v any) string {
	if v == nil {
		return "—"
	}
	switch t := v.(type) {
	case float64:
		return fmt.Sprintf("%.2f", t)
	default:
		return fmt.Sprint(v)
	}
}

func formatPercent(locale string, value any) string {
	if value == nil || value == "" {
		return i18n.T(locale, "telegram.dashboard_na")
	}
	var f float64
	switch t := value.(type) {
	case float64:
		f = t
	case int:
		f = float64(t)
	case int64:
		f = float64(t)
	default:
		return i18n.T(locale, "telegram.dashboard_na")
	}
	return fmt.Sprintf("%.1f%%", f)
}

func formatBytes(locale string, bytes any) string {
	if bytes == nil || bytes == "" {
		return i18n.T(locale, "telegram.dashboard_na")
	}
	var n float64
	switch t := bytes.(type) {
	case int:
		n = float64(t)
	case int64:
		n = float64(t)
	case float64:
		n = t
	default:
		return i18n.T(locale, "telegram.dashboard_na")
	}
	if n < 0 {
		n = 0
	}
	units := []string{"B", "KB", "MB", "GB", "TB", "PB"}
	i := 0
	for n >= 1024 && i < len(units)-1 {
		n /= 1024
		i++
	}
	if i == 0 {
		return fmt.Sprintf("%.0f%s", n, units[i])
	}
	return fmt.Sprintf("%.2f%s", n, units[i])
}

func asMap(v any) map[string]any {
	m, _ := v.(map[string]any)
	return m
}

func nested(m map[string]any, a, b string) any {
	if m == nil {
		return nil
	}
	inner, _ := m[a].(map[string]any)
	if inner == nil {
		return nil
	}
	return inner[b]
}

package resolver

import (
	"context"
	"encoding/json"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

func diagnose(ctx context.Context, s *Service) map[string]any {
	locale := Locale(ctx)
	var checks []map[string]any
	var hints []string
	configs, _ := s.Store.EnabledServerConfigs(ctx)

	singRunning := s.IsSingBoxRunning(ctx)
	detail := "OK"
	if !singRunning {
		detail = i18n.T(locale, "resolver.diag_process_not_found")
		hints = append(hints, i18n.T(locale, "resolver.diag_apply_resolver_hint"))
	}
	checks = append(checks, map[string]any{
		"id": "singbox_running", "ok": singRunning,
		"label": i18n.T(locale, "resolver.diag_singbox_running"), "detail": detail,
	})

	datapath := detectDatapath(ctx, s)
	checks = append(checks, map[string]any{
		"id": "awg_datapath", "ok": datapath != "userspace",
		"label": i18n.T(locale, "resolver.diag_awg_datapath"),
		"detail": datapathDetail(locale, datapath),
	})
	if datapath == "userspace" && len(configs) > 0 {
		hints = append(hints, i18n.T(locale, "resolver.diag_awg_datapath_userspace_hint"))
	}

	listeners := detectListeners(ctx, s)
	dnsOK := listeners["dns_udp"] || listeners["dns_tcp"]
	tproxyOK := listeners["tproxy_udp"] || listeners["tproxy_tcp"]
	checks = append(checks, map[string]any{
		"id": "dns_listen", "ok": dnsOK,
		"label": "DNS listen :" + strconv.Itoa(DNSListenPort),
		"detail": dnsListenDetail(locale, dnsOK),
	})
	checks = append(checks, map[string]any{
		"id": "fakeip_tproxy", "ok": tproxyOK,
		"label": "FakeIP → REDIRECT :" + strconv.Itoa(TProxyPort),
		"detail": tproxyDetail(locale, tproxyOK),
	})

	clashOK := s.Clash.WaitForAPI(ctx, 5, 150*time.Millisecond)
	clashDetail := i18n.T(locale, "resolver.diag_available")
	if !clashOK {
		clashDetail = i18n.T(locale, "resolver.diag_unavailable")
	}
	checks = append(checks, map[string]any{
		"id": "clash_api", "ok": clashOK,
		"label": "Clash API " + ClashAPIAddr, "detail": clashDetail,
	})

	applyOK := true
	applyDetail := "OK"
	for _, c := range configs {
		if strPtrVal(c.ResolverLastError) != "" {
			applyOK = false
			applyDetail = *c.ResolverLastError
			hints = append(hints, i18n.T(locale, "resolver.diag_apply_failed_hint"))
			break
		}
	}
	checks = append(checks, map[string]any{
		"id": "apply_status", "ok": applyOK,
		"label": i18n.T(locale, "resolver.diag_apply_status"), "detail": applyDetail,
	})

	singPath := s.Paths.SingBoxConfigPath()
	singOK := fileExists(singPath) && fileSize(singPath) > 16
	checks = append(checks, map[string]any{
		"id": "singbox_json", "ok": singOK,
		"label": "sing-box.json",
		"detail": fileDetail(singPath),
	})

	for _, cfg := range configs {
		merged := s.Paths.MergedRulesetPath(cfg.ID)
		ok := fileExists(merged) && fileSize(merged) > 8
		detail := "OK"
		if !ok {
			detail = i18n.Tf(locale, "resolver.diag_merged_missing", map[string]string{"id": itoa(int(cfg.ID))})
			if strPtrVal(cfg.ResolverLastError) != "" {
				hints = append(hints, i18n.Tf(locale, "resolver.diag_merged_missing_with_error", map[string]string{
					"name": cfg.Name, "error": *cfg.ResolverLastError,
				}))
			} else {
				hints = append(hints, i18n.Tf(locale, "resolver.diag_merged_missing_hint", map[string]string{"name": cfg.Name}))
			}
		}
		checks = append(checks, map[string]any{
			"id": "merged_" + itoa(int(cfg.ID)), "ok": ok,
			"label": "merged_cfg_" + itoa(int(cfg.ID)) + ".json", "detail": detail,
		})
		for _, tag := range cfg.CommunityLists {
			if tag == "" || strings.HasPrefix(tag, "custom_") {
				continue
			}
			path := s.Paths.CommunityRulesetPath(tag)
			present := fileExists(path) && fileSize(path) > 16
			d := "OK"
			if !present {
				d = i18n.Tf(locale, "resolver.diag_ruleset_missing", map[string]string{"tag": tag})
				hints = append(hints, i18n.Tf(locale, "resolver.diag_list_file_missing", map[string]string{
					"label": first(CommunityLabels[tag], tag), "tag": tag,
				}))
			}
			checks = append(checks, map[string]any{
				"id": "list_" + tag, "ok": present, "label": tag + ".srs", "detail": d,
			})
		}
	}

	statusFile := map[string]any{}
	if raw, err := os.ReadFile(s.Paths.ResolverStatusPath()); err == nil {
		_ = json.Unmarshal(raw, &statusFile)
	}

	if len(configs) > 0 {
		hints = append(hints,
			i18n.T(locale, "resolver.diag_client_hint_reimport"),
			i18n.T(locale, "resolver.diag_client_hint_conf"),
			i18n.T(locale, "resolver.diag_client_hint_2ip"),
			i18n.T(locale, "resolver.diag_client_hint_android"),
			i18n.T(locale, "resolver.diag_client_hint_iphone"),
			i18n.T(locale, "resolver.diag_client_hint_tspu"),
		)
	}

	return map[string]any{
		"ok": applyOK && singRunning,
		"checks": checks,
		"hints":  uniqueStrings(hints),
		"runtime": map[string]any{
			"singbox_running": singRunning,
			"clash_api":       clashOK,
			"datapath":        datapath,
			"listeners":       listeners,
			"status_file":     statusFile,
		},
	}
}

func detectDatapath(ctx context.Context, s *Service) string {
	r, err := s.Docker.Exec(ctx, s.Cfg.AWGContainer, []string{"sh", "-c",
		`if ps aux 2>/dev/null | grep -q '[a]mneziawg-go '; then echo userspace; exit 0; fi
for iface in $(ip -o link show 2>/dev/null | awk -F': ' '{print $2}' | grep -E '^awg[0-9]+$'); do
  if pgrep -f "amneziawg-go ${iface}" >/dev/null 2>&1; then echo userspace; exit 0; fi
done
if [ -d /sys/module/amneziawg ] || lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg; then echo kernel; else echo unknown; fi`},
		8*time.Second)
	if err != nil {
		return "unknown"
	}
	v := strings.TrimSpace(r.Stdout)
	if v == "kernel" || v == "userspace" {
		return v
	}
	return "unknown"
}

func detectListeners(ctx context.Context, s *Service) map[string]bool {
	out := map[string]bool{"dns_udp": false, "dns_tcp": false, "tproxy_udp": false, "tproxy_tcp": false}
	r, err := s.Docker.Exec(ctx, s.Cfg.AWGContainer, []string{"sh", "-c",
		`ss -uln 2>/dev/null | grep -q ':53 ' && echo dns_udp; ss -tln 2>/dev/null | grep -q ':53 ' && echo dns_tcp; ss -uln 2>/dev/null | grep -q ':1603 ' && echo tproxy_udp; ss -tln 2>/dev/null | grep -q ':1602 ' && echo tproxy_tcp; true`},
		8*time.Second)
	if err != nil {
		return out
	}
	for _, line := range strings.Split(r.Stdout, "\n") {
		line = strings.TrimSpace(line)
		if _, ok := out[line]; ok {
			out[line] = true
		}
	}
	return out
}

func datapathDetail(locale, mode string) string {
	switch mode {
	case "kernel":
		return i18n.T(locale, "resolver.diag_awg_datapath_kernel")
	case "userspace":
		return i18n.T(locale, "resolver.diag_awg_datapath_userspace")
	default:
		return i18n.T(locale, "resolver.diag_awg_datapath_unknown")
	}
}

func dnsListenDetail(locale string, ok bool) string {
	port := strconv.Itoa(DNSListenPort)
	if ok {
		return i18n.Tf(locale, "resolver.diag_dns_listening", map[string]string{"port": port})
	}
	return i18n.Tf(locale, "resolver.diag_dns_not_listening", map[string]string{"port": port})
}

func tproxyDetail(locale string, ok bool) string {
	if ok {
		return i18n.T(locale, "resolver.diag_delivery_mode_redirect_detail")
	}
	return i18n.Tf(locale, "resolver.diag_tproxy_down", map[string]string{"port": strconv.Itoa(TProxyPort)})
}

func fileDetail(path string) string {
	if !fileExists(path) {
		return "missing"
	}
	return strconv.FormatInt(fileSize(path), 10) + " bytes"
}

package diagnostics

import (
	"context"
	"database/sql"
	"net"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/resolver"
)

var diagDNSHostRE = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9._-]{0,253}$`)

func (s *Service) bootstrapDNS(ctx context.Context) string {
	fallback := resolver.DefaultBootstrapDNS
	if s.Settings == nil {
		return fallback
	}
	v := strings.TrimSpace(s.Settings.GetValue(ctx, "resolver_bootstrap_dns", fallback))
	if v == "" || !validDNSHost(v) {
		return fallback
	}
	return v
}

func validDNSHost(host string) bool {
	host = strings.TrimSpace(host)
	if host == "" {
		return false
	}
	if net.ParseIP(host) != nil {
		return true
	}
	return diagDNSHostRE.MatchString(host) && strings.Contains(host, ".")
}

func (s *Service) digAt(ctx context.Context, server, domain string) bool {
	if !s.Stats.IsContainerRunning(ctx, "") || !validDNSHost(server) || domain == "" {
		return false
	}
	script := "dig @" + server + " " + domain + " +time=3 +tries=1 +short 2>/dev/null | grep -q . && echo yes || echo no"
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script}, 10*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

func (s *Service) fakeipProbe(ctx context.Context, gateway string) bool {
	if !s.Stats.IsContainerRunning(ctx, "") || !validDNSHost(gateway) {
		return false
	}
	script := "dig @" + gateway + " youtube.com +time=3 +tries=1 +short 2>/dev/null | grep -q '^198\\.18\\.' && echo yes || echo no"
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script}, 10*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

func (s *Service) singboxListeners(ctx context.Context) map[string]bool {
	out := map[string]bool{"dns_udp": false, "dns_tcp": false, "tproxy_tcp": false, "tproxy_udp": false}
	if !s.Stats.IsContainerRunning(ctx, "") {
		return out
	}
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c",
		`ss -uln 2>/dev/null | grep -q ':53 ' && echo dns_udp; ss -tln 2>/dev/null | grep -q ':53 ' && echo dns_tcp; ss -tln 2>/dev/null | grep -q ':1602 ' && echo tproxy_tcp; ss -uln 2>/dev/null | grep -q ':1603 ' && echo tproxy_udp; true`},
		8*time.Second, "")
	for _, line := range strings.Split(r.Stdout, "\n") {
		line = strings.TrimSpace(line)
		if _, ok := out[line]; ok {
			out[line] = true
		}
	}
	return out
}

func (s *Service) resolverOutboundHost(ctx context.Context, configs []models.AwgConfig) string {
	if s.DB == nil {
		return ""
	}
	for _, c := range configs {
		if c.Type != "server" || !c.ResolverEnabled || c.ConnectionID == nil {
			continue
		}
		var shareURL, subURL sql.NullString
		err := s.DB.QueryRowContext(ctx,
			`SELECT share_url, subscription_url FROM resolver_connections WHERE id=? LIMIT 1`, *c.ConnectionID).
			Scan(&shareURL, &subURL)
		if err != nil {
			continue
		}
		for _, raw := range []string{strNull(shareURL), strNull(subURL)} {
			raw = strings.TrimSpace(raw)
			if raw == "" {
				continue
			}
			u, err := url.Parse(raw)
			if err == nil {
				if h := u.Hostname(); h != "" {
					return h
				}
			}
		}
	}
	return ""
}

func strNull(v sql.NullString) string {
	if v.Valid {
		return v.String
	}
	return ""
}

func (s *Service) appendResolverSingboxChecks(ctx context.Context, locale string, configs []models.AwgConfig, checks *[]map[string]any, hints *[]string) {
	if !s.Stats.IsContainerRunning(ctx, "") {
		return
	}

	bootstrapDNS := s.bootstrapDNS(ctx)
	*checks = append(*checks, map[string]any{
		"id": "bootstrap_dns_config", "ok": true,
		"label": i18n.T(locale, "system.bootstrap_dns_label"), "detail": bootstrapDNS,
	})

	bootstrapOK := s.digAt(ctx, bootstrapDNS, "google.com")
	bootstrapDetail := "ok"
	if !bootstrapOK {
		bootstrapDetail = i18n.Tf(locale, "system.bootstrap_dns_probe_fail", map[string]string{"server": bootstrapDNS})
		*hints = append(*hints, i18n.Tf(locale, "system.bootstrap_dns_probe_hint", map[string]string{"server": bootstrapDNS}))
	}
	*checks = append(*checks, map[string]any{
		"id": "bootstrap_dns_probe", "ok": bootstrapOK,
		"label": i18n.T(locale, "system.bootstrap_dns_probe_label"), "detail": bootstrapDetail,
	})

	if bootstrapDNS != "8.8.8.8" && !s.digAt(ctx, "8.8.8.8", "google.com") {
		*hints = append(*hints, i18n.T(locale, "system.bootstrap_dns_8888_blocked_hint"))
	}

	outboundHost := s.resolverOutboundHost(ctx, configs)
	if outboundHost != "" {
		outboundOK := s.digAt(ctx, bootstrapDNS, outboundHost)
		outboundDetail := "ok"
		if !outboundOK {
			outboundDetail = i18n.Tf(locale, "system.outbound_domain_probe_fail", map[string]string{
				"host": outboundHost, "server": bootstrapDNS,
			})
			*hints = append(*hints, i18n.Tf(locale, "system.outbound_domain_probe_hint", map[string]string{
				"host": outboundHost, "server": bootstrapDNS,
			}))
		}
		*checks = append(*checks, map[string]any{
			"id": "outbound_domain", "ok": outboundOK,
			"label": i18n.T(locale, "system.outbound_domain_probe_label"),
			"detail": outboundDetail + " (" + outboundHost + ")",
		})
	}

	var gateway string
	for _, c := range configs {
		if c.Type == "server" && c.ResolverEnabled && c.Enabled && strings.TrimSpace(c.ServerAddress) != "" {
			gateway = strings.SplitN(strings.TrimSpace(c.ServerAddress), "/", 2)[0]
			break
		}
	}
	if gateway != "" {
		fakeipOK := s.fakeipProbe(ctx, gateway)
		fakeipDetail := "ok"
		if !fakeipOK {
			fakeipDetail = i18n.T(locale, "system.fakeip_probe_fail")
			*hints = append(*hints, i18n.T(locale, "system.fakeip_probe_hint"))
		}
		*checks = append(*checks, map[string]any{
			"id": "fakeip_probe", "ok": fakeipOK,
			"label": i18n.T(locale, "system.fakeip_probe_label"), "detail": fakeipDetail,
		})
	}

	listeners := s.singboxListeners(ctx)
	dnsOK := listeners["dns_udp"]
	dnsDetail := "ok"
	if !dnsOK {
		dnsDetail = i18n.T(locale, "system.dns_listen_fail")
	}
	*checks = append(*checks, map[string]any{
		"id": "dns_listen", "ok": dnsOK,
		"label": i18n.T(locale, "system.dns_listen_label"), "detail": dnsDetail,
	})

	fakeipListenOK := listeners["tproxy_tcp"] && listeners["tproxy_udp"]
	fakeipListenDetail := "ok"
	if !fakeipListenOK {
		fakeipListenDetail = i18n.T(locale, "system.fakeip_listen_fail")
	}
	*checks = append(*checks, map[string]any{
		"id": "fakeip_listen", "ok": fakeipListenOK,
		"label": i18n.T(locale, "system.fakeip_listen_label"), "detail": fakeipListenDetail,
	})
}

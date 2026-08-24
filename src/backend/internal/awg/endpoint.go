package awg

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/settings"
)

func (s *Service) UsesDomainInEndpoint(ctx context.Context) bool {
	return settings.AsBool(s.Settings.GetValue(ctx, "endpoint_use_domain", "0"))
}

func (s *Service) ResolveServerEndpointHost(ctx context.Context, requestHost string) string {
	host := s.Settings.GetValue(ctx, "server_endpoint", s.Cfg.ServerEndpoint)
	if host == "auto" || host == "" {
		host = requestHost
		if host == "" {
			if h, err := os.Hostname(); err == nil && h != "" {
				host = h
			} else {
				host = "127.0.0.1"
			}
		}
	}
	return host
}

func (s *Service) DetectPublicIPv4(ctx context.Context, requestHost string) string {
	client := &http.Client{Timeout: 5 * time.Second}
	for _, url := range []string{"https://ifconfig.me", "https://api.ipify.org"} {
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		if err != nil {
			continue
		}
		req.Header.Set("Accept", "text/plain")
		resp, err := client.Do(req)
		if err != nil {
			continue
		}
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 64))
		resp.Body.Close()
		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			continue
		}
		ip := strings.TrimSpace(string(body))
		if net.ParseIP(ip) != nil && net.ParseIP(ip).To4() != nil {
			return ip
		}
	}
	host := strings.TrimSpace(requestHost)
	if ip := net.ParseIP(host); ip != nil && ip.To4() != nil {
		return host
	}
	return ""
}

func (s *Service) ResolveEndpointHost(ctx context.Context, requestHost string) string {
	domain := s.ResolvePanelDomain(ctx)
	if s.UsesDomainInEndpoint(ctx) && domain != "" {
		return domain
	}
	return s.ResolveServerEndpointHost(ctx, requestHost)
}

func (s *Service) ResolvePanelDomain(ctx context.Context) string {
	return strings.TrimSpace(s.Settings.GetValue(ctx, "panel_domain", ""))
}

func (s *Service) ShouldRedirectIPToDomain(ctx context.Context) bool {
	if s.ResolvePanelDomain(ctx) == "" {
		return false
	}
	return settings.AsBool(s.Settings.GetValue(ctx, "redirect_ip_to_domain", "0"))
}

func (s *Service) ResolvePanelHost(ctx context.Context, requestHost string) string {
	if domain := s.ResolvePanelDomain(ctx); domain != "" {
		return domain
	}
	return s.ResolveServerEndpointHost(ctx, requestHost)
}

func (s *Service) ResolveIPv4Addresses(host string) []string {
	host = strings.TrimSpace(host)
	if host == "" {
		return nil
	}
	if ip := net.ParseIP(host); ip != nil && ip.To4() != nil {
		return []string{host}
	}
	ips, err := net.LookupIP(host)
	if err != nil {
		return nil
	}
	var out []string
	seen := map[string]bool{}
	for _, ip := range ips {
		if v4 := ip.To4(); v4 != nil {
			s := v4.String()
			if !seen[s] {
				seen[s] = true
				out = append(out, s)
			}
		}
	}
	return out
}

func (s *Service) IsPublicIPv4(ip string) bool {
	parsed := net.ParseIP(strings.TrimSpace(ip))
	if parsed == nil || parsed.To4() == nil {
		return false
	}
	return parsed.IsGlobalUnicast() && !parsed.IsPrivate() && !parsed.IsLoopback() &&
		!parsed.IsLinkLocalUnicast() && !parsed.IsMulticast()
}

type DomainError struct {
	Key  string
	Vars map[string]string
}

func (e *DomainError) Error() string { return e.Key }

func (s *Service) AssertPanelDomainDNS(ctx context.Context, domain, requestHost string) error {
	domain = strings.TrimSpace(domain)
	if domain == "" {
		return nil
	}
	resolved := s.ResolveIPv4Addresses(domain)
	if len(resolved) == 0 {
		return &DomainError{Key: "settings.domain_no_a_record", Vars: map[string]string{"domain": domain}}
	}
	for _, ip := range resolved {
		if !s.IsPublicIPv4(ip) {
			return &DomainError{Key: "settings.domain_points_private", Vars: map[string]string{
				"domain": domain, "got": strings.Join(resolved, ", "),
			}}
		}
	}
	var candidates []string
	if detected := s.DetectPublicIPv4(ctx, requestHost); detected != "" && s.IsPublicIPv4(detected) {
		candidates = append(candidates, detected)
	}
	endpoint := s.ResolveServerEndpointHost(ctx, requestHost)
	if s.IsPublicIPv4(endpoint) {
		candidates = append(candidates, endpoint)
	}
	candidates = uniqueNonEmpty(candidates)
	if len(candidates) == 0 {
		return &DomainError{Key: "settings.public_ip_detect_failed"}
	}
	for _, ip := range candidates {
		for _, r := range resolved {
			if ip == r {
				return nil
			}
		}
	}
	return &DomainError{Key: "settings.domain_points_elsewhere", Vars: map[string]string{
		"domain": domain, "got": strings.Join(resolved, ", "), "host": candidates[0],
	}}
}

func (s *Service) AssertPanelPorts(httpPort, httpsPort string) error {
	for label, port := range map[string]string{"HTTP": httpPort, "HTTPS": httpsPort} {
		if !digitsOnly.MatchString(port) {
			return &DomainError{Key: "settings.port_must_be_number", Vars: map[string]string{"label": label}}
		}
		n, _ := strconv.Atoi(port)
		if n < 1 || n > 65535 {
			return &DomainError{Key: "settings.port_out_of_range", Vars: map[string]string{"label": label}}
		}
	}
	if httpPort == httpsPort {
		return &DomainError{Key: "settings.http_https_ports_must_differ"}
	}
	return nil
}

func (s *Service) ResolvePanelHTTPSPort(ctx context.Context) string {
	return s.Settings.GetValue(ctx, "panel_https_port", s.Cfg.PanelHTTPSPort)
}

func (s *Service) ResolvePanelURL(ctx context.Context, requestHost string) string {
	sslEnabled := settings.AsBool(s.Settings.GetValue(ctx, "ssl_enabled", "0"))
	domain := s.ResolvePanelDomain(ctx)
	if sslEnabled && domain != "" {
		return "https://" + domain + ":" + s.ResolvePanelHTTPSPort(ctx)
	}
	port := s.Settings.GetValue(ctx, "panel_port", s.Cfg.PanelPort)
	return "http://" + s.ResolvePanelHost(ctx, requestHost) + ":" + port
}

func (s *Service) ResolveTimezone(ctx context.Context) string {
	tz := strings.TrimSpace(s.Settings.GetValue(ctx, "timezone", s.Cfg.TZ))
	if tz == "" {
		return "UTC"
	}
	if _, err := time.LoadLocation(tz); err != nil {
		return "UTC"
	}
	return tz
}

func (s *Service) EndpointStatus(ctx context.Context, requestHost string) (map[string]any, error) {
	if _, err := s.EnsureDBDefaults(ctx); err != nil {
		return nil, err
	}
	stored := s.Settings.GetValue(ctx, "server_endpoint", s.Cfg.ServerEndpoint)
	display := s.ResolveEndpointHost(ctx, requestHost)
	awgPort := s.Cfg.AWGPort
	var listen any
	port := awgPort
	if cfg, err := s.Configs.First(ctx); err == nil && cfg != nil {
		listen = cfg.ListenPort
		port = cfg.ListenPort
	} else {
		listen = nil
	}
	return map[string]any{
		"server_endpoint":  stored,
		"display_endpoint": display,
		"awg_port":         awgPort,
		"listen_port":      listen,
		"endpoint":         fmt.Sprintf("%s:%d", display, port),
	}, nil
}

func (s *Service) UpdateServerEndpoint(ctx context.Context, endpoint *string, port *int, restart bool) (map[string]any, error) {
	if _, err := s.EnsureDBDefaults(ctx); err != nil {
		return nil, err
	}
	if endpoint != nil {
		ep := strings.TrimSpace(*endpoint)
		if ep == "" {
			return nil, ErrEmptyEndpoint
		}
		if ep != "auto" && !isValidEndpointHost(ep) {
			return nil, ErrInvalidEndpoint
		}
		if err := s.Settings.Set(ctx, "server_endpoint", ep); err != nil {
			return nil, err
		}
	}
	portChanged := false
	if port != nil {
		if *port < PortMin || *port > PortMax {
			return nil, fmt.Errorf("port must be between %d and %d", PortMin, PortMax)
		}
		cfg, err := s.Configs.First(ctx)
		if err != nil {
			return nil, err
		}
		if cfg == nil {
			return nil, ErrNoConfig
		}
		taken, err := s.Configs.PortTaken(ctx, *port, cfg.ID)
		if err != nil {
			return nil, err
		}
		if taken {
			return nil, ErrPortConflict
		}
		if cfg.ListenPort != *port {
			cfg.ListenPort = *port
			if err := s.Configs.Update(ctx, cfg); err != nil {
				return nil, err
			}
			portChanged = true
		}
	}
	_ = s.WriteWebhookConf(ctx)
	restarted := false
	if portChanged {
		if err := s.ApplyConfig(ctx, nil, true, true); err != nil {
			return nil, err
		}
		if restart {
			result := s.RestartAWG(ctx)
			ok, _ := result["ok"].(bool)
			restarted = ok
			if !restarted {
				return nil, ErrRestartFailed
			}
		}
	}
	status, err := s.EndpointStatus(ctx, "")
	if err != nil {
		return nil, err
	}
	status["restarted"] = restarted
	return status, nil
}

func isValidEndpointHost(host string) bool {
	if ip := net.ParseIP(host); ip != nil {
		return true
	}
	return hostnameRE.MatchString(host)
}

var (
	digitsOnly = regexp.MustCompile(`^\d+$`)
	hostnameRE = regexp.MustCompile(`^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$`)
)

func DomainNameValid(host string) bool {
	return hostnameRE.MatchString(strings.TrimSpace(host))
}

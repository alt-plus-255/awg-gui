package awg

import (
	"bufio"
	"context"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/settings"
)

func (s *Service) EnsureDBDefaults(ctx context.Context) (bool, error) {
	provisioned := false
	for key, value := range s.DefaultSettings() {
		if _, ok := s.Settings.Get(ctx, key); !ok {
			if err := s.Settings.Set(ctx, key, value); err != nil {
				return false, err
			}
			provisioned = true
		}
	}

	exists, err := s.Configs.Exists(ctx)
	if err != nil {
		return false, err
	}
	if !exists {
		keys, err := s.GenerateKeyPair(ctx)
		if err != nil {
			return false, err
		}
		version := s.Versions.Latest()
		junk := s.Versions.ProfileForConfig(version).GenerateJunkParams()
		defaults := s.DefaultConfigAttributes()
		cfg := &models.AwgConfig{
			Name:                    "Default",
			Type:                    defaults["type"].(string),
			Iface:                   "awg0",
			ListenPort:              s.Cfg.AWGPort,
			ProtocolVersion:         version,
			ServerPrivateKey:        keys.Private,
			ServerPublicKey:         keys.Public,
			InternalSubnet:          defaults["internal_subnet"].(string),
			ServerAddress:           defaults["server_address"].(string),
			PeerDNS:                 defaults["peer_dns"].(string),
			ClientAllowedIPs:        defaults["client_allowed_ips"].(string),
			PersistentKeepalive:     defaults["persistent_keepalive"].(int),
			Enabled:                 true,
			ClientImportNameStyle:   ClientImportNamePeer,
			VnPolicy:                "allow_all",
		}
		cfg.ApplyJunk(junk)
		if err := s.Configs.Create(ctx, cfg); err != nil {
			return false, err
		}
		provisioned = true
		return provisioned, nil
	}

	all, err := s.Configs.All(ctx)
	if err != nil {
		return false, err
	}
	for i := range all {
		cfg := &all[i]
		if ok, err := s.ApplyObfuscationParams(ctx, cfg); err != nil {
			return false, err
		} else if ok {
			provisioned = true
		}
		if ok, err := s.EnsureServerKeys(ctx, cfg); err != nil {
			return false, err
		} else if ok {
			provisioned = true
		}
		peers, err := s.Peers.ListByConfig(ctx, cfg.ID)
		if err != nil {
			return false, err
		}
		for j := range peers {
			if ok, err := s.EnsurePeerKeys(ctx, &peers[j]); err != nil {
				return false, err
			} else if ok {
				provisioned = true
			}
		}
	}
	return provisioned, nil
}

func (s *Service) BootstrapRuntime(ctx context.Context) error {
	_ = s.WriteWebhookConf(ctx)
	// SSL Caddyfile rewrite is not ported in phase 2.
	_ = s.SyncPanelURLToHostEnv(ctx, nil)
	return s.ApplyConfig(ctx, nil, true, true)
}

func (s *Service) WriteWebhookConf(ctx context.Context) error {
	dir := s.HostGUIDir()
	if err := os.MkdirAll(dir, 0755); err != nil {
		return nil
	}
	url := s.Settings.GetValue(ctx, "failure_webhook_url", "")
	panelPort := s.Settings.GetValue(ctx, "panel_port", s.Cfg.PanelPort)
	panelHTTPS := s.ResolvePanelHTTPSPort(ctx)
	endpoint := s.Settings.GetValue(ctx, "server_endpoint", "auto")
	panelDomain := s.ResolvePanelDomain(ctx)
	timezone := s.ResolveTimezone(ctx)
	sslEnabled := "0"
	if settings.AsBool(s.Settings.GetValue(ctx, "ssl_enabled", "0")) {
		sslEnabled = "1"
	}
	content := "WEBHOOK_URL=" + url +
		"\nPANEL_PORT=" + panelPort +
		"\nPANEL_HTTPS_PORT=" + panelHTTPS +
		"\nSERVER_ENDPOINT=" + endpoint +
		"\nPANEL_DOMAIN=" + panelDomain +
		"\nSSL_ENABLED=" + sslEnabled +
		"\nTZ=" + timezone + "\n"
	_ = os.WriteFile(dir+"/webhook.conf", []byte(content), 0644)
	return nil
}

func (s *Service) SyncPanelURLToHostEnv(ctx context.Context, extra map[string]string) error {
	httpPort := s.Settings.GetValue(ctx, "panel_port", s.Cfg.PanelPort)
	httpsPort := s.ResolvePanelHTTPSPort(ctx)
	appURL := s.ResolvePanelURL(ctx, "")
	sslEnabled := settings.AsBool(s.Settings.GetValue(ctx, "ssl_enabled", "0"))
	secureCookie := sslEnabled && s.ShouldRedirectIPToDomain(ctx)
	secure := "false"
	if secureCookie {
		secure = "true"
	}
	values := map[string]string{
		"PANEL_PORT":               httpPort,
		"PANEL_HTTPS_PORT":         httpsPort,
		"APP_URL":                  appURL,
		"SESSION_SECURE_COOKIE":    secure,
		"SANCTUM_STATEFUL_DOMAINS": strings.Join(s.ResolveSanctumStatefulDomains(ctx), ","),
	}
	for k, v := range extra {
		values[k] = v
	}

	var candidates []string
	conf := s.HostGUIDir() + "/awg-gui.conf"
	if f, err := os.Open(conf); err == nil {
		sc := bufio.NewScanner(f)
		for sc.Scan() {
			line := sc.Text()
			if strings.HasPrefix(line, "ENV_FILE=") {
				candidates = append(candidates, strings.TrimPrefix(line, "ENV_FILE="))
			}
		}
		f.Close()
	}
	candidates = append(candidates, strings.TrimRight(s.Cfg.HostComposeDir, "/")+"/.env")
	candidates = append(candidates, "../.env")

	envKey := regexp.MustCompile(`^[A-Z][A-Z0-9_]*$`)
	for _, path := range uniqueNonEmpty(candidates) {
		info, err := os.Stat(path)
		if err != nil || info.IsDir() {
			continue
		}
		raw, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		text := string(raw)
		for key, value := range values {
			if !envKey.MatchString(key) {
				continue
			}
			value = strings.ReplaceAll(strings.ReplaceAll(value, "\r", ""), "\n", "")
			re := regexp.MustCompile(`(?m)^` + regexp.QuoteMeta(key) + `=.*`)
			if re.MatchString(text) {
				text = re.ReplaceAllString(text, key+"="+value)
			} else {
				text = strings.TrimRight(text, "\n") + "\n" + key + "=" + value + "\n"
			}
		}
		_ = os.WriteFile(path, []byte(text), info.Mode())
		break
	}
	return nil
}

func (s *Service) SyncTimezoneToHostEnv(timezone string) {
	if _, err := time.LoadLocation(timezone); err != nil {
		return
	}
	var candidates []string
	conf := s.HostGUIDir() + "/awg-gui.conf"
	if f, err := os.Open(conf); err == nil {
		sc := bufio.NewScanner(f)
		for sc.Scan() {
			line := sc.Text()
			if strings.HasPrefix(line, "ENV_FILE=") {
				candidates = append(candidates, strings.TrimPrefix(line, "ENV_FILE="))
			}
		}
		f.Close()
	}
	candidates = append(candidates, "../.env")
	re := regexp.MustCompile(`(?m)^TZ=.*`)
	for _, path := range uniqueNonEmpty(candidates) {
		raw, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		text := string(raw)
		if re.MatchString(text) {
			text = re.ReplaceAllString(text, "TZ="+timezone)
		} else {
			text = strings.TrimRight(text, "\n") + "\nTZ=" + timezone + "\n"
		}
		_ = os.WriteFile(path, []byte(text), 0644)
		break
	}
}

func (s *Service) ResolveSanctumStatefulDomains(ctx context.Context) []string {
	port := s.Settings.GetValue(ctx, "panel_port", s.Cfg.PanelPort)
	httpsPort := s.ResolvePanelHTTPSPort(ctx)
	var domains []string
	for _, host := range []string{"localhost", "127.0.0.1", "::1"} {
		domains = append(domains, host, host+":"+port, host+":"+httpsPort)
	}
	endpoint := s.ResolveServerEndpointHost(ctx, "")
	if endpoint != "" && endpoint != "auto" {
		domains = append(domains, endpoint, endpoint+":"+port, endpoint+":"+httpsPort)
	}
	if panel := s.ResolvePanelDomain(ctx); panel != "" {
		domains = append(domains, panel, panel+":"+port, panel+":"+httpsPort)
	}
	for _, d := range strings.Split(s.Cfg.SanctumStatefulDomains, ",") {
		if t := strings.TrimSpace(d); t != "" {
			domains = append(domains, t)
		}
	}
	return uniqueNonEmpty(domains)
}


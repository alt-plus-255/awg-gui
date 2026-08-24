package ssl

import (
	"context"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"log"
	"net/mail"
	"os"
	"strings"
	"time"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/docker"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/panelops"
	"github.com/awggui/backend/internal/settings"
)

type Service struct {
	AWG      *awg.Service
	Docker   *docker.Runtime
	PanelOps *panelops.Client
	Settings *settings.Store
	Cfg      config.Config
	issuer   *AcmeDNSIssuer
}

func New(cfg config.Config, awgSvc *awg.Service, d *docker.Runtime, ops *panelops.Client, st *settings.Store) *Service {
	return &Service{AWG: awgSvc, Docker: d, PanelOps: ops, Settings: st, Cfg: cfg}
}

func (s *Service) HostGUIDir() string {
	return s.AWG.HostGUIDir()
}

func (s *Service) CaddyfilePath() string {
	return s.HostGUIDir() + "/Caddyfile"
}

func (s *Service) CertsPanelDir() string {
	return s.HostGUIDir() + "/certs/panel"
}

func (s *Service) Issuer() *AcmeDNSIssuer {
	if s.issuer == nil {
		dir := s.Cfg.ACMEDirectoryURL
		if dir == "" {
			dir = "https://acme-v02.api.letsencrypt.org/directory"
		}
		s.issuer = NewAcmeDNSIssuer(s.HostGUIDir(), dir)
	}
	return s.issuer
}

func (s *Service) IsSSLEnabled(ctx context.Context) bool {
	return settings.AsBool(s.Settings.GetValue(ctx, "ssl_enabled", "0"))
}

func (s *Service) Status(ctx context.Context, locale string) map[string]any {
	if !s.IsSSLEnabled(ctx) && s.HasLiveCertificate() {
		st := strings.TrimSpace(s.Settings.GetValue(ctx, "ssl_status", "disabled"))
		errMsg := strings.TrimSpace(s.Settings.GetValue(ctx, "ssl_error", ""))
		if st == "error" || strings.Contains(errMsg, "Successfully received certificate") {
			if _, err := s.ActivateInstalledCertificate(ctx, locale); err != nil {
				log.Printf("ssl auto-recover failed: %v", err)
			}
		}
	}
	enabled := s.IsSSLEnabled(ctx)
	email := strings.TrimSpace(s.Settings.GetValue(ctx, "ssl_email", ""))
	status := strings.TrimSpace(s.Settings.GetValue(ctx, "ssl_status", map[bool]string{true: "active", false: "disabled"}[enabled]))
	errVal := strings.TrimSpace(s.Settings.GetValue(ctx, "ssl_error", ""))
	expiresAt := strings.TrimSpace(s.Settings.GetValue(ctx, "ssl_expires_at", ""))
	domain := s.AWG.ResolvePanelDomain(ctx)
	httpsPort := s.Settings.GetValue(ctx, "panel_https_port", s.Cfg.PanelHTTPSPort)

	challenge := s.resolvePendingChallenge(ctx)
	if challenge != nil && (status == "disabled" || status == "active" || status == "error") {
		status = "pending"
	}
	if expiresAt == "" {
		if exp := s.readCertExpiresAt(s.CertsPanelDir() + "/fullchain.pem"); exp != "" {
			expiresAt = exp
			_ = s.Settings.Set(ctx, "ssl_expires_at", expiresAt)
		}
	}
	var expAny any
	if expiresAt != "" {
		expAny = expiresAt
	}
	return map[string]any{
		"enabled":    enabled,
		"email":      email,
		"status":     status,
		"error":      errVal,
		"expires_at": expAny,
		"domain":     domain,
		"https_port": httpsPort,
		"challenge":  challenge,
		"panel_url":  s.AWG.ResolvePanelURL(ctx, ""),
		"hint":       i18n.T(locale, "settings.ssl_dns_hint"),
	}
}

func (s *Service) HasLiveCertificate() bool {
	live := s.HostGUIDir() + "/certs/live/panel"
	if readable(live+"/fullchain.pem") && readable(live+"/privkey.pem") {
		return true
	}
	return readable(s.CertsPanelDir()+"/fullchain.pem") && readable(s.CertsPanelDir()+"/privkey.pem")
}

func (s *Service) RecoverIfCertificateExists(ctx context.Context, locale string) (map[string]any, error) {
	if !s.HasLiveCertificate() && !readable(s.CertsPanelDir()+"/fullchain.pem") {
		return nil, nil
	}
	return s.ActivateInstalledCertificate(ctx, locale)
}

func (s *Service) WriteCaddyfile(ctx context.Context, sslOn bool) error {
	s.ensureHostLayout()
	if err := os.WriteFile(s.CaddyfilePath(), []byte(s.CaddyfileContents(ctx, sslOn)), 0644); err != nil {
		return fmt.Errorf("%s", i18n.T("en", "settings.caddyfile_write_failed"))
	}
	return nil
}

func (s *Service) CaddyfileContents(ctx context.Context, sslOn bool) string {
	if sslOn {
		return s.buildSSLCaddyfile(ctx)
	}
	return s.siteBlock(":80", false, "")
}

func (s *Service) RefreshSSLCaddyfileIfEnabled(ctx context.Context) error {
	if !s.IsSSLEnabled(ctx) {
		return nil
	}
	if err := s.WriteCaddyfile(ctx, true); err != nil {
		return err
	}
	return s.ReloadOrRecreateCaddy(ctx)
}

type ArgError struct{ Msg string }

func (e *ArgError) Error() string { return e.Msg }

func (s *Service) StartIssue(ctx context.Context, locale, email string, forceRenew bool) (map[string]any, error) {
	domain := s.AWG.ResolvePanelDomain(ctx)
	if domain == "" {
		return nil, &ArgError{i18n.T(locale, "settings.panel_domain_required")}
	}
	email = strings.TrimSpace(email)
	if email == "" || !validEmail(email) {
		return nil, &ArgError{i18n.T(locale, "settings.le_email_required")}
	}
	if err := s.AWG.AssertPanelDomainDNS(ctx, domain, ""); err != nil {
		return nil, err
	}
	if existing := s.Issuer().ReusableChallengeFor(domain); existing != nil {
		_ = s.Settings.Set(ctx, "ssl_email", email)
		_ = s.Settings.Set(ctx, "ssl_status", "pending")
		_ = s.Settings.Set(ctx, "ssl_error", "")
		s.storeChallenge(ctx, existing)
		return map[string]any{"domain": existing.Domain, "txt_name": existing.TXTName, "txt_value": existing.TXTValue, "email": email}, nil
	}
	s.AbortChallenge(ctx, true)
	s.ensureHostLayout()
	_ = s.Settings.Set(ctx, "ssl_email", email)
	_ = s.Settings.Set(ctx, "ssl_status", "pending")
	_ = s.Settings.Set(ctx, "ssl_error", "")
	ch, err := s.Issuer().Start(locale, domain, email, forceRenew)
	if err != nil {
		s.clearStoredChallenge(ctx)
		_ = s.Settings.Set(ctx, "ssl_status", "error")
		msg := strings.TrimSpace(err.Error())
		if msg == "" {
			msg = i18n.T(locale, "settings.acme_start_failed")
		}
		_ = s.Settings.Set(ctx, "ssl_error", msg)
		return nil, fmt.Errorf("%s", msg)
	}
	s.storeChallenge(ctx, &ch)
	return map[string]any{"domain": ch.Domain, "txt_name": ch.TXTName, "txt_value": ch.TXTValue, "email": email}, nil
}

func (s *Service) CompleteIssue(ctx context.Context, locale string) (map[string]any, error) {
	challenge := s.resolvePendingChallenge(ctx)
	if challenge == nil && !s.Issuer().HasPendingOrder() {
		recovered, err := s.RecoverIfCertificateExists(ctx, locale)
		if err != nil {
			return nil, err
		}
		if recovered != nil {
			return recovered, nil
		}
		return nil, &ArgError{i18n.T(locale, "settings.no_active_dns_challenge")}
	}
	if _, err := s.Issuer().Complete(locale, 180); err != nil {
		if s.HasLiveCertificate() {
			return s.ActivateInstalledCertificate(ctx, locale)
		}
		_ = s.Settings.Set(ctx, "ssl_status", "error")
		msg := strings.TrimSpace(err.Error())
		if msg == "" {
			msg = i18n.T(locale, "settings.acme_complete_failed")
		}
		_ = s.Settings.Set(ctx, "ssl_error", msg)
		return nil, fmt.Errorf("%s", msg)
	}
	if !s.HasLiveCertificate() {
		recovered, err := s.RecoverIfCertificateExists(ctx, locale)
		if err != nil {
			return nil, err
		}
		if recovered != nil {
			return recovered, nil
		}
		_ = s.Settings.Set(ctx, "ssl_status", "error")
		msg := i18n.T(locale, "settings.cert_files_not_found_after_issue")
		_ = s.Settings.Set(ctx, "ssl_error", msg)
		return nil, fmt.Errorf("%s", msg)
	}
	return s.ActivateInstalledCertificate(ctx, locale)
}

func (s *Service) ActivateInstalledCertificate(ctx context.Context, locale string) (map[string]any, error) {
	s.clearStoredChallenge(ctx)
	if err := s.installPanelCertsFromLetsEncrypt(locale); err != nil {
		return nil, err
	}
	expiresAt := s.readCertExpiresAt(s.CertsPanelDir() + "/fullchain.pem")
	_ = s.Settings.Set(ctx, "ssl_enabled", "1")
	_ = s.Settings.Set(ctx, "ssl_status", "active")
	_ = s.Settings.Set(ctx, "ssl_error", "")
	_ = s.Settings.Set(ctx, "ssl_expires_at", expiresAt)
	if err := s.WriteCaddyfile(ctx, true); err != nil {
		return nil, err
	}
	_ = s.AWG.WriteWebhookConf(ctx)
	_ = s.AWG.SyncPanelURLToHostEnv(ctx, nil)
	if err := s.ReloadOrRecreateCaddy(ctx); err != nil {
		return nil, err
	}
	return s.Status(ctx, locale), nil
}

func (s *Service) Disable(ctx context.Context, locale string) (map[string]any, error) {
	s.AbortChallenge(ctx, true)
	_ = s.Settings.Set(ctx, "ssl_enabled", "0")
	_ = s.Settings.Set(ctx, "ssl_status", "disabled")
	_ = s.Settings.Set(ctx, "ssl_error", "")
	if err := s.WriteCaddyfile(ctx, false); err != nil {
		return nil, err
	}
	_ = s.AWG.WriteWebhookConf(ctx)
	_ = s.AWG.SyncPanelURLToHostEnv(ctx, nil)
	if err := s.ReloadOrRecreateCaddy(ctx); err != nil {
		return nil, err
	}
	return s.Status(ctx, locale), nil
}

func (s *Service) AbortChallenge(ctx context.Context, quiet bool) {
	s.Issuer().Abort()
	s.clearStoredChallenge(ctx)
	if !quiet && s.Settings.GetValue(ctx, "ssl_status", "") == "pending" && !s.IsSSLEnabled(ctx) {
		_ = s.Settings.Set(ctx, "ssl_status", "disabled")
	}
}

func (s *Service) RecreateCaddy() error {
	if err := s.PanelOps.RecreateCaddy(); err != nil {
		log.Printf("caddy recreate failed: %v", err)
		msg := err.Error()
		if msg == "" {
			msg = i18n.T("en", "settings.caddy_recreate_failed")
		}
		return fmt.Errorf("%s", msg)
	}
	return nil
}

func (s *Service) ReloadCaddy(ctx context.Context) error {
	res := s.Docker.Exec(ctx, "awggui-caddy", []string{"caddy", "reload", "--config", "/etc/caddy/Caddyfile"}, 30*time.Second, "")
	if !res.Successful() {
		err := strings.TrimSpace(res.ErrorOutput())
		if err == "" {
			err = strings.TrimSpace(res.Output())
		}
		if err == "" {
			err = i18n.T("en", "settings.caddy_reload_failed")
		}
		return fmt.Errorf("%s", err)
	}
	return nil
}

func (s *Service) ReloadOrRecreateCaddy(ctx context.Context) error {
	if err := s.ReloadCaddy(ctx); err != nil {
		log.Printf("caddy reload failed, recreating: %v", err)
		return s.RecreateCaddy()
	}
	return nil
}

func (s *Service) installPanelCertsFromLetsEncrypt(locale string) error {
	live := s.HostGUIDir() + "/certs/live/panel"
	fullchain := live + "/fullchain.pem"
	privkey := live + "/privkey.pem"
	if !readable(fullchain) || !readable(privkey) {
		fullchain = s.CertsPanelDir() + "/fullchain.pem"
		privkey = s.CertsPanelDir() + "/privkey.pem"
	}
	if !readable(fullchain) || !readable(privkey) {
		return fmt.Errorf("%s", i18n.T(locale, "settings.acme_ok_but_files_missing"))
	}
	dest := s.CertsPanelDir()
	_ = os.MkdirAll(dest, 0755)
	fc, err1 := os.ReadFile(fullchain)
	pk, err2 := os.ReadFile(privkey)
	if err1 != nil || err2 != nil || len(fc) == 0 || len(pk) == 0 {
		return fmt.Errorf("%s", i18n.T(locale, "settings.cert_read_failed"))
	}
	_ = os.WriteFile(dest+"/fullchain.pem", fc, 0644)
	_ = os.WriteFile(dest+"/privkey.pem", pk, 0640)
	return nil
}

func (s *Service) readCertExpiresAt(path string) string {
	raw, err := os.ReadFile(path)
	if err != nil || len(raw) == 0 {
		return ""
	}
	block, _ := pem.Decode(raw)
	if block == nil {
		return ""
	}
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return ""
	}
	return cert.NotAfter.UTC().Format(time.RFC3339)
}

func (s *Service) ensureHostLayout() {
	s.Issuer().EnsureLayout()
	for _, dir := range []string{s.HostGUIDir(), s.CertsPanelDir(), s.HostGUIDir() + "/certs"} {
		_ = os.MkdirAll(dir, 0755)
	}
}

func (s *Service) resolvePendingChallenge(ctx context.Context) *Challenge {
	domain := strings.ToLower(strings.TrimSpace(s.AWG.ResolvePanelDomain(ctx)))
	fromFiles := s.Issuer().ReadPendingChallenge()
	if fromFiles != nil {
		if domain != "" && fromFiles.Domain != domain {
			return nil
		}
		s.storeChallenge(ctx, fromFiles)
		return fromFiles
	}
	stored := s.readStoredChallenge(ctx)
	if stored == nil {
		return nil
	}
	if domain != "" && stored.Domain != domain {
		s.clearStoredChallenge(ctx)
		return nil
	}
	if !s.Issuer().HasPendingOrder() {
		s.clearStoredChallenge(ctx)
		return nil
	}
	return stored
}

func (s *Service) storeChallenge(ctx context.Context, ch *Challenge) {
	if ch == nil {
		return
	}
	domain := strings.ToLower(strings.TrimSpace(ch.Domain))
	txtName := strings.TrimSpace(ch.TXTName)
	txtValue := strings.TrimSpace(ch.TXTValue)
	if domain == "" || txtValue == "" {
		return
	}
	if txtName == "" {
		txtName = "_acme-challenge." + domain
	}
	b, _ := json.Marshal(map[string]string{
		"domain":    domain,
		"txt_name":  txtName,
		"txt_value": txtValue,
		"saved_at":  time.Now().UTC().Format(time.RFC3339),
	})
	_ = s.Settings.Set(ctx, "ssl_pending_challenge", string(b))
}

func (s *Service) readStoredChallenge(ctx context.Context) *Challenge {
	raw := strings.TrimSpace(s.Settings.GetValue(ctx, "ssl_pending_challenge", ""))
	if raw == "" {
		return nil
	}
	var data map[string]string
	if json.Unmarshal([]byte(raw), &data) != nil {
		return nil
	}
	domain := strings.ToLower(strings.TrimSpace(data["domain"]))
	txtValue := strings.TrimSpace(data["txt_value"])
	txtName := strings.TrimSpace(data["txt_name"])
	if domain == "" || txtValue == "" {
		return nil
	}
	if txtName == "" {
		txtName = "_acme-challenge." + domain
	}
	return &Challenge{Domain: domain, TXTName: txtName, TXTValue: txtValue}
}

func (s *Service) clearStoredChallenge(ctx context.Context) {
	_ = s.Settings.Set(ctx, "ssl_pending_challenge", "")
}

func (s *Service) buildSSLCaddyfile(ctx context.Context) string {
	domain := s.AWG.ResolvePanelDomain(ctx)
	httpsPort := s.Settings.GetValue(ctx, "panel_https_port", s.Cfg.PanelHTTPSPort)
	httpRedirect := ""
	httpsRedirect := ""
	if domain != "" {
		httpRedirect = "\t@panel host " + domain + "\n\tredir @panel https://" + domain + ":" + httpsPort + "{uri} temporary\n\n"
		if s.AWG.ShouldRedirectIPToDomain(ctx) {
			force := "\t@not_panel not host " + domain + "\n\tredir @not_panel https://" + domain + ":" + httpsPort + "{uri} temporary\n\n"
			httpRedirect += force
			httpsRedirect = force
		}
	}
	return "{\n\tauto_https off\n}\n\n" + s.siteBlock(":443", true, httpsRedirect) + "\n" + s.siteBlock(":80", false, httpRedirect)
}

func (s *Service) siteBlock(listen string, tls bool, extra string) string {
	tlsLine := ""
	if tls {
		tlsLine = "\ttls /certs/fullchain.pem /certs/privkey.pem\n"
	}
	return listen + " {\n" + tlsLine + extra + "\tencode gzip\n\n" +
		"\thandle /ws* {\n\t\treverse_proxy awggui-app:8081\n\t}\n\n" +
		"\thandle /api/* {\n\t\treverse_proxy awggui-app:8000 {\n" +
		"\t\t\theader_up Host {host}\n\t\t\theader_up X-Real-IP {remote}\n" +
		"\t\t\theader_up X-Forwarded-For {remote}\n\t\t\theader_up X-Forwarded-Proto {scheme}\n\t\t}\n\t}\n\n" +
		"\thandle /sanctum/* {\n\t\treverse_proxy awggui-app:8000 {\n" +
		"\t\t\theader_up Host {host}\n\t\t\theader_up X-Real-IP {remote}\n" +
		"\t\t\theader_up X-Forwarded-For {remote}\n\t\t\theader_up X-Forwarded-Proto {scheme}\n\t\t}\n\t}\n\n" +
		"\t@sw path /sw.js /sw.js.map /workbox-*.js /workbox-*.js.map\n" +
		"\thandle @sw {\n\t\troot * /srv\n\t\theader Cache-Control \"no-cache\"\n\t\tfile_server\n\t}\n\n" +
		"\thandle {\n\t\troot * /srv\n\t\ttry_files {path} /index.html\n\t\tfile_server\n\t}\n}\n"
}

func readable(path string) bool {
	f, err := os.Open(path)
	if err != nil {
		return false
	}
	f.Close()
	return true
}

func validEmail(s string) bool {
	_, err := mail.ParseAddress(s)
	return err == nil
}

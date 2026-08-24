package api

import (
	"context"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/panelops"
	"github.com/awggui/backend/internal/settings"
	"github.com/awggui/backend/internal/ssl"
	"github.com/awggui/backend/internal/telegram"
	"github.com/awggui/backend/internal/update"
)

type SettingsController struct {
	AWG      *awg.Service
	Settings *settings.Store
	SSL      *ssl.Service
	Telegram *telegram.Service
	Updates  *update.Service
	PanelOps *panelops.Client
}

func (c *SettingsController) Show(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if provisioned, err := c.AWG.EnsureDBDefaults(ctx); err == nil && provisioned {
		_ = c.AWG.BootstrapRuntime(ctx)
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"settings":         c.settingsPayload(ctx),
		"display_endpoint": c.AWG.ResolveEndpointHost(ctx, requestHost(r)),
		"panel_url":        c.AWG.ResolvePanelURL(ctx, requestHost(r)),
		"ssl":              c.sslStatus(r),
		"webhook_schema":   c.webhookSchema(r),
		"timezones":        timezoneOptions(),
		"egress":           c.AWG.EgressStatus(ctx),
	})
}

func (c *SettingsController) DetectPublicIP(w http.ResponseWriter, r *http.Request) {
	ip := c.AWG.DetectPublicIPv4(r.Context(), requestHost(r))
	if ip == "" {
		writeMessage(w, r, http.StatusUnprocessableEntity, "settings.public_ip_detect_failed", nil)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"public_ip": ip})
}

func (c *SettingsController) Update(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}

	if v, ok := req["singbox_egress_interface"]; ok {
		iface := strings.TrimSpace(asString(v))
		if iface == "" || strings.EqualFold(iface, "auto") {
			req["singbox_egress_interface"] = "auto"
		} else if !awg.IsValidIfaceName(iface) {
			writeValidation(w, r, "singbox_egress_interface", "settings.invalid_egress_interface", nil)
			return
		} else {
			req["singbox_egress_interface"] = iface
		}
	}

	telegramChanged, proxiesChanged := c.applyTelegramSettings(ctx, req)

	serverEndpoint := strings.TrimSpace(c.Settings.GetValue(ctx, "server_endpoint", "auto"))
	if v, ok := req["server_endpoint"]; ok {
		serverEndpoint = strings.TrimSpace(asString(v))
	}
	_ = serverEndpoint

	panelDomain := strings.TrimSpace(c.Settings.GetValue(ctx, "panel_domain", ""))
	if v, ok := req["panel_domain"]; ok {
		if v == nil {
			panelDomain = ""
		} else {
			panelDomain = strings.TrimSpace(asString(v))
		}
	}
	useDomain := settings.AsBool(c.Settings.GetValue(ctx, "endpoint_use_domain", "0"))
	if v, ok := req["endpoint_use_domain"]; ok {
		if b, ok := asBool(v); ok {
			useDomain = b
		}
	}
	redirectIP := settings.AsBool(c.Settings.GetValue(ctx, "redirect_ip_to_domain", "0"))
	if v, ok := req["redirect_ip_to_domain"]; ok {
		if b, ok := asBool(v); ok {
			redirectIP = b
		}
	}

	oldHTTP := c.Settings.GetValue(ctx, "panel_port", c.AWG.Cfg.PanelPort)
	oldHTTPS := c.Settings.GetValue(ctx, "panel_https_port", c.AWG.Cfg.PanelHTTPSPort)
	oldDomain := strings.TrimSpace(c.Settings.GetValue(ctx, "panel_domain", ""))
	oldRedirectIP := settings.AsBool(c.Settings.GetValue(ctx, "redirect_ip_to_domain", "0"))
	httpPort := oldHTTP
	httpsPort := oldHTTPS
	if v, ok := req["panel_port"]; ok {
		httpPort = strings.TrimSpace(asString(v))
	}
	if v, ok := req["panel_https_port"]; ok {
		httpsPort = strings.TrimSpace(asString(v))
	}
	if err := c.AWG.AssertPanelPorts(httpPort, httpsPort); err != nil {
		if de, ok := err.(*awg.DomainError); ok {
			msg := i18n.Tf(auth.LocaleFromContext(ctx), de.Key, de.Vars)
			writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
				"message": msg,
				"errors": map[string]any{
					"panel_port":       []string{msg},
					"panel_https_port": []string{msg},
				},
			})
			return
		}
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"message": err.Error()})
		return
	}
	if _, ok := req["panel_port"]; ok {
		req["panel_port"] = httpPort
	}
	if _, ok := req["panel_https_port"]; ok {
		req["panel_https_port"] = httpsPort
	}

	if _, ok1 := req["panel_domain"]; ok1 || hasKey(req, "endpoint_use_domain") || hasKey(req, "redirect_ip_to_domain") || hasKey(req, "server_endpoint") {
		if panelDomain == "" {
			useDomain = false
			redirectIP = false
			req["endpoint_use_domain"] = false
			req["redirect_ip_to_domain"] = false
			req["panel_domain"] = ""
		} else {
			if !awg.DomainNameValid(panelDomain) {
				writeValidation(w, r, "panel_domain", "api.http_422", nil)
				return
			}
			if err := c.AWG.AssertPanelDomainDNS(ctx, panelDomain, requestHost(r)); err != nil {
				if de, ok := err.(*awg.DomainError); ok {
					writeValidation(w, r, "panel_domain", de.Key, de.Vars)
					return
				}
				writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"message": err.Error()})
				return
			}
		}
		if hasKey(req, "endpoint_use_domain") || panelDomain == "" {
			if useDomain {
				req["endpoint_use_domain"] = "1"
			} else {
				req["endpoint_use_domain"] = "0"
			}
		}
		if hasKey(req, "redirect_ip_to_domain") || panelDomain == "" {
			if redirectIP {
				req["redirect_ip_to_domain"] = "1"
			} else {
				req["redirect_ip_to_domain"] = "0"
			}
		}
	}

	for key, value := range req {
		if key == "endpoint_use_domain" || key == "redirect_ip_to_domain" {
			b, _ := asBool(value)
			v := "0"
			if b {
				v = "1"
			}
			_ = c.Settings.Set(ctx, key, v)
			continue
		}
		_ = c.Settings.Set(ctx, key, asString(value))
	}

	if v, ok := req["timezone"]; ok {
		tz := strings.TrimSpace(asString(v))
		if _, err := time.LoadLocation(tz); err == nil {
			c.AWG.SyncTimezoneToHostEnv(tz)
		}
	}

	_ = c.AWG.WriteWebhookConf(ctx)

	locale := auth.LocaleFromContext(ctx)
	domainTouched := hasKey(req, "panel_domain")
	domainClearedOrChanged := domainTouched && (panelDomain == "" || (oldDomain != "" && !strings.EqualFold(oldDomain, panelDomain)))
	if domainClearedOrChanged && c.SSL != nil {
		if c.SSL.IsSSLEnabled(ctx) {
			_, _ = c.SSL.Disable(ctx, locale)
		} else {
			c.SSL.AbortChallenge(ctx, true)
		}
	}

	portsChanged := oldHTTP != httpPort || oldHTTPS != httpsPort
	redirectChanged := oldRedirectIP != settings.AsBool(c.Settings.GetValue(ctx, "redirect_ip_to_domain", "0"))
	if portsChanged {
		_ = c.AWG.SyncPanelURLToHostEnv(ctx, nil)
		if c.SSL != nil {
			if c.SSL.IsSSLEnabled(ctx) {
				_ = c.SSL.WriteCaddyfile(ctx, true)
			}
			if err := c.SSL.RecreateCaddy(); err != nil {
				writeJSON(w, http.StatusInternalServerError, map[string]any{
					"message":          i18n.Tf(locale, "settings.caddy_ports_apply_failed", map[string]string{"error": err.Error()}),
					"settings":         c.settingsPayload(ctx),
					"display_endpoint": c.AWG.ResolveEndpointHost(ctx, requestHost(r)),
					"panel_url":        c.AWG.ResolvePanelURL(ctx, requestHost(r)),
					"ssl":              c.sslStatus(r),
					"timezones":        timezoneOptions(),
				})
				return
			}
		}
	} else {
		_ = c.AWG.SyncPanelURLToHostEnv(ctx, nil)
		if redirectChanged && c.SSL != nil {
			if err := c.SSL.RefreshSSLCaddyfileIfEnabled(ctx); err != nil {
				writeJSON(w, http.StatusInternalServerError, map[string]any{
					"message":          i18n.T(locale, "settings.caddy_reload_failed") + ": " + err.Error(),
					"settings":         c.settingsPayload(ctx),
					"display_endpoint": c.AWG.ResolveEndpointHost(ctx, requestHost(r)),
					"panel_url":        c.AWG.ResolvePanelURL(ctx, requestHost(r)),
					"ssl":              c.sslStatus(r),
					"timezones":        timezoneOptions(),
				})
				return
			}
		}
	}

	var telegramSync any
	if telegramChanged && c.Telegram != nil {
		telegramSync = c.Telegram.Sync.SyncAfterSettingsChange(ctx, proxiesChanged)
	}

	if _, ok := req["singbox_egress_interface"]; ok {
		c.AWG.ForgetEgressCache()
		if err := c.AWG.ApplyConfig(ctx, nil, true, true); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{
				"message":          i18n.Tf(locale, "settings.egress_apply_failed", map[string]string{"error": err.Error()}),
				"settings":         c.settingsPayload(ctx),
				"display_endpoint": c.AWG.ResolveEndpointHost(ctx, requestHost(r)),
				"panel_url":        c.AWG.ResolvePanelURL(ctx, requestHost(r)),
				"ssl":              c.sslStatus(r),
				"timezones":        timezoneOptions(),
				"egress":           c.AWG.EgressStatus(ctx),
				"telegram_sync":    telegramSync,
			})
			return
		}
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"settings":         c.settingsPayload(ctx),
		"display_endpoint": c.AWG.ResolveEndpointHost(ctx, requestHost(r)),
		"panel_url":        c.AWG.ResolvePanelURL(ctx, requestHost(r)),
		"ssl":              c.sslStatus(r),
		"timezones":        timezoneOptions(),
		"egress":           c.AWG.EgressStatus(ctx),
		"telegram_sync":    telegramSync,
	})
}

func (c *SettingsController) RestartAWG(w http.ResponseWriter, r *http.Request) {
	writeRestartResult(w, r, c.AWG.RestartAWG(r.Context()))
}

func (c *SettingsController) settingsPayload(ctx context.Context) map[string]any {
	all, err := c.Settings.AllKeyed(ctx)
	out := map[string]any{}
	if err == nil {
		for k, v := range all {
			out[k] = v
		}
	}
	if c.Telegram != nil {
		for k, v := range c.Telegram.Settings.ForAPI(ctx) {
			out[k] = v
		}
	}
	return out
}

func (c *SettingsController) webhookSchema(r *http.Request) map[string]any {
	locale := auth.LocaleFromContext(r.Context())
	return map[string]any{
		"schema_version": "1.0",
		"method":         "POST",
		"content_type":   "application/json",
		"example": map[string]any{
			"schema_version": "1.0",
			"event":          "awg_gui.failure",
			"severity":       "error",
			"source":         "awg-gui",
			"project":        "awggui",
			"hostname":       "vpn.example.com",
			"timestamp":      "2026-07-15T10:58:00+03:00",
			"code":           "docker_unavailable",
			"message":        "Docker daemon did not become ready within timeout",
			"panel_url":      "http://203.0.113.10:8877",
			"details":        map[string]any{"attempt": 1, "services": []string{"caddy", "app", "db", "awg"}, "stderr": "..."},
		},
		"codes": []string{"docker_unavailable", "compose_up_failed", "service_unhealthy", "awg_gui.test"},
		"fields": map[string]string{
			"schema_version": i18n.T(locale, "settings.webhook_field_schema_version"),
			"event":          i18n.T(locale, "settings.webhook_field_event"),
			"severity":       "info | warning | error | critical",
			"source":         i18n.T(locale, "settings.webhook_field_source"),
			"project":        i18n.T(locale, "settings.webhook_field_project"),
			"hostname":       i18n.T(locale, "settings.webhook_field_hostname"),
			"timestamp":      i18n.T(locale, "settings.webhook_field_timestamp"),
			"code":           i18n.T(locale, "settings.webhook_field_code"),
			"message":        i18n.T(locale, "settings.webhook_field_message"),
			"panel_url":      i18n.T(locale, "settings.webhook_field_panel_url"),
			"details":        i18n.T(locale, "settings.webhook_field_details"),
		},
	}
}

func (c *SettingsController) sslStatus(r *http.Request) map[string]any {
	if c.SSL == nil {
		return map[string]any{"enabled": false, "status": "disabled", "error": "", "expires_at": "", "challenge": nil}
	}
	return c.SSL.Status(r.Context(), auth.LocaleFromContext(r.Context()))
}

func stubSSLStatus() map[string]any {
	return map[string]any{
		"enabled":            false,
		"status":             "disabled",
		"error":              "",
		"expires_at":         "",
		"pending_challenge":  nil,
		"redirect_ip":        false,
	}
}

func timezoneOptions() []string {
	preferred := []string{
		"UTC", "Europe/Kaliningrad", "Europe/Moscow", "Europe/Samara",
		"Asia/Yekaterinburg", "Asia/Omsk", "Asia/Krasnoyarsk", "Asia/Irkutsk",
		"Asia/Yakutsk", "Asia/Vladivostok", "Asia/Magadan", "Asia/Kamchatka",
		"Europe/Kyiv", "Europe/Minsk", "Asia/Almaty", "Asia/Tashkent",
		"Europe/Berlin", "Europe/London", "America/New_York",
	}
	all := listZoneinfo()
	ordered := make([]string, 0, len(all)+len(preferred))
	seen := map[string]bool{}
	for _, tz := range preferred {
		if _, err := time.LoadLocation(tz); err == nil {
			ordered = append(ordered, tz)
			seen[tz] = true
		}
	}
	for _, tz := range all {
		if !seen[tz] {
			if _, err := time.LoadLocation(tz); err == nil {
				ordered = append(ordered, tz)
				seen[tz] = true
			}
		}
	}
	if len(ordered) == 0 {
		return preferred
	}
	return ordered
}

func listZoneinfo() []string {
	roots := []string{"/usr/share/zoneinfo", "/usr/share/zoneinfo/posix"}
	var out []string
	for _, root := range roots {
		_ = filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
			if err != nil || info.IsDir() {
				return nil
			}
			rel, err := filepath.Rel(root, path)
			if err != nil {
				return nil
			}
			if strings.HasPrefix(rel, "posix/") || strings.HasPrefix(rel, "right/") ||
				strings.Contains(rel, ".") || rel == "localtime" || rel == "leapseconds" {
				return nil
			}
			out = append(out, strings.ReplaceAll(rel, "\\", "/"))
			return nil
		})
		if len(out) > 0 {
			break
		}
	}
	return out
}

func hasKey(m map[string]any, k string) bool {
	_, ok := m[k]
	return ok
}

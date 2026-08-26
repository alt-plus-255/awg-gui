package api

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/ssl"
	"github.com/awggui/backend/internal/telegram"
	"github.com/go-chi/chi/v5"
)

func (c *SettingsController) TelegramWebhook(w http.ResponseWriter, r *http.Request) {
	if c.Telegram == nil {
		writeJSON(w, http.StatusNotFound, map[string]any{"message": "Not found"})
		return
	}
	c.Telegram.HandleWebhook(w, r, chi.URLParam(r, "secret"))
}

func (c *SettingsController) applyTelegramSettings(ctx context.Context, req map[string]any) (changed, proxiesChanged bool) {
	if c.Telegram == nil {
		if raw, ok := req["telegram_proxies"]; ok {
			if arr, ok := raw.([]any); ok {
				_ = c.Settings.Set(ctx, "telegram_proxies", arr)
			}
			delete(req, "telegram_proxies")
		}
		return false, false
	}
	tg := c.Telegram.Settings
	if raw, ok := req["telegram_proxies"]; ok {
		arr, _ := raw.([]any)
		normalized := c.normalizeTelegramProxies(ctx, arr)
		encoded := tg.EncodeProxies(normalized)
		if encoded != c.Settings.GetValue(ctx, "telegram_proxies", "[]") {
			_ = c.Settings.Set(ctx, "telegram_proxies", encoded)
			changed = true
			proxiesChanged = true
		}
		delete(req, "telegram_proxies")
	}
	if v, ok := req["telegram_bot_token"]; ok {
		token := strings.TrimSpace(asString(v))
		if token != "" && !strings.Contains(token, "*") {
			if token != c.Settings.GetValue(ctx, "telegram_bot_token", "") {
				_ = c.Settings.Set(ctx, "telegram_bot_token", token)
				changed = true
			}
		}
		delete(req, "telegram_bot_token")
	}
	if v, ok := req["telegram_admin_id"]; ok {
		adminID := strings.TrimSpace(asString(v))
		if adminID != c.Settings.GetValue(ctx, "telegram_admin_id", "") {
			_ = c.Settings.Set(ctx, "telegram_admin_id", adminID)
			changed = true
		}
		delete(req, "telegram_admin_id")
	}
	if v, ok := req["telegram_mode"]; ok {
		mode := asString(v)
		if mode != c.Settings.GetValue(ctx, "telegram_mode", telegram.ModePolling) {
			_ = c.Settings.Set(ctx, "telegram_mode", mode)
			changed = true
		}
		delete(req, "telegram_mode")
	}
	if v, ok := req["telegram_proxy_strategy"]; ok {
		strategy := asString(v)
		if strategy != c.Settings.GetValue(ctx, "telegram_proxy_strategy", telegram.StrategyFastest) {
			_ = c.Settings.Set(ctx, "telegram_proxy_strategy", strategy)
			changed = true
		}
		delete(req, "telegram_proxy_strategy")
	}
	if v, ok := req["telegram_notifications_enabled"]; ok {
		enabled := "0"
		if b, ok := asBool(v); ok && b {
			enabled = "1"
		} else if asString(v) == "1" {
			enabled = "1"
		}
		if enabled != c.Settings.GetValue(ctx, "telegram_notifications_enabled", "1") {
			_ = c.Settings.Set(ctx, "telegram_notifications_enabled", enabled)
			changed = true
		}
		delete(req, "telegram_notifications_enabled")
	}
	if v, ok := req["telegram_daily_report_enabled"]; ok {
		enabled := "0"
		if b, ok := asBool(v); ok && b {
			enabled = "1"
		} else if asString(v) == "1" {
			enabled = "1"
		}
		if enabled != c.Settings.GetValue(ctx, "telegram_daily_report_enabled", "1") {
			_ = c.Settings.Set(ctx, "telegram_daily_report_enabled", enabled)
			changed = true
		}
		delete(req, "telegram_daily_report_enabled")
	}
	if v, ok := req["telegram_language"]; ok {
		language := asString(v)
		if language != c.Settings.GetValue(ctx, "telegram_language", telegram.LangEN) {
			_ = c.Settings.Set(ctx, "telegram_language", language)
			changed = true
		}
		delete(req, "telegram_language")
	}
	if changed && c.Telegram != nil {
		c.Telegram.Sync.EnsureWebhookSecret(ctx)
	}
	return changed, proxiesChanged
}

func (c *SettingsController) normalizeTelegramProxies(ctx context.Context, rows []any) []telegram.Proxy {
	existing := []telegram.Proxy{}
	if c.Telegram != nil {
		existing = c.Telegram.Settings.Proxies(ctx)
	}
	var out []telegram.Proxy
	for _, raw := range rows {
		row, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		typ := asString(row["type"])
		enabled := true
		if b, ok := asBool(row["enabled"]); ok {
			enabled = b
		}
		id := strings.TrimSpace(asString(row["id"]))
		if id == "" && c.Telegram != nil {
			id = c.Telegram.Sync.NewProxyID()
		}
		if typ == "url" {
			u := strings.TrimSpace(asString(row["url"]))
			if u == "" || strings.Contains(u, "***") {
				for _, e := range existing {
					if e.ID == id && e.Type == "url" {
						u = e.URL
						break
					}
				}
			}
			if u == "" || !allowedTelegramProxyURL(u) {
				continue
			}
			out = append(out, telegram.Proxy{ID: id, Type: "url", URL: u, Enabled: enabled})
			continue
		}
		if typ == "connection" {
			cid, ok := asInt64(row["connection_id"])
			if !ok || cid < 1 {
				continue
			}
			out = append(out, telegram.Proxy{ID: id, Type: "connection", ConnectionID: cid, Enabled: enabled})
		}
	}
	return out
}

func allowedTelegramProxyURL(raw string) bool {
	if !strings.Contains(raw, "://") {
		return false
	}
	scheme := strings.ToLower(strings.SplitN(raw, "://", 2)[0])
	switch scheme {
	case "socks5", "socks5h", "http", "https":
		return true
	default:
		return false
	}
}

func (c *SettingsController) TestTelegram(w http.ResponseWriter, r *http.Request) {
	if c.Telegram == nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "unavailable"})
		return
	}
	var req map[string]any
	_ = decodeJSON(r, &req)
	probe, _ := asBool(req["probe_proxies"])
	result := c.Telegram.Sync.TestBot(r.Context(), auth.LocaleFromContext(r.Context()), probe)
	status := http.StatusOK
	if ok, _ := result["ok"].(bool); !ok {
		status = http.StatusUnprocessableEntity
	}
	writeJSON(w, status, result)
}

func (c *SettingsController) TestTelegramProxy(w http.ResponseWriter, r *http.Request) {
	if c.Telegram == nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"ok": false})
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	u := strings.TrimSpace(asString(req["url"]))
	if u == "" {
		write422(w, r)
		return
	}
	token := strings.TrimSpace(asString(req["token"]))
	if token == "" || strings.Contains(token, "*") {
		token = ""
	}
	result := c.Telegram.Sync.TestProxyURL(r.Context(), auth.LocaleFromContext(r.Context()), u, token)
	status := http.StatusOK
	if ok, _ := result["ok"].(bool); !ok {
		status = http.StatusUnprocessableEntity
	}
	writeJSON(w, status, result)
}

func (c *SettingsController) UpdateStatus(w http.ResponseWriter, r *http.Request) {
	if c.Updates == nil {
		writeJSON(w, http.StatusOK, map[string]any{"status": "idle", "can_update": false})
		return
	}
	writeJSON(w, http.StatusOK, c.Updates.Status(auth.LocaleFromContext(r.Context()), false))
}

func (c *SettingsController) CheckProjectUpdates(w http.ResponseWriter, r *http.Request) {
	if c.Updates == nil {
		writeJSON(w, http.StatusOK, map[string]any{"status": "idle", "can_update": false})
		return
	}
	writeJSON(w, http.StatusOK, c.Updates.CheckForUpdates(auth.LocaleFromContext(r.Context())))
}

var versionRE = regexp.MustCompile(`^v?[A-Za-z0-9._-]+$`)

func (c *SettingsController) StartProjectUpdate(w http.ResponseWriter, r *http.Request) {
	if c.Updates == nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"message": i18n.T(auth.LocaleFromContext(r.Context()), "settings.update_not_available")})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	var req map[string]any
	_ = decodeJSON(r, &req)
	version := strings.TrimSpace(asString(req["version"]))
	if version != "" && !versionRE.MatchString(version) {
		write422(w, r)
		return
	}
	status := c.Updates.Status(locale, false)
	if ok, _ := status["running"].(bool); ok {
		writeJSON(w, http.StatusConflict, status)
		return
	}
	state, err := c.Updates.Start(locale, version)
	if err != nil {
		msg := err.Error()
		code := http.StatusInternalServerError
		switch msg {
		case "update_not_available":
			msg = i18n.T(locale, "settings.update_not_available")
			code = http.StatusUnprocessableEntity
		case "update_already_running":
			msg = i18n.T(locale, "settings.update_already_running")
		}
		writeJSON(w, code, map[string]any{"message": msg})
		return
	}
	writeJSON(w, http.StatusAccepted, state)
}

func (c *SettingsController) ClearProjectUpdateLog(w http.ResponseWriter, r *http.Request) {
	if c.Updates == nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"message": i18n.T(auth.LocaleFromContext(r.Context()), "settings.update_not_available")})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	state, err := c.Updates.ClearLog(locale)
	if err != nil {
		msg := err.Error()
		code := http.StatusInternalServerError
		if msg == "update_log_clear_blocked" {
			msg = i18n.T(locale, "settings.update_log_clear_blocked")
			code = http.StatusConflict
		} else if msg == "update_log_clear_failed" {
			msg = i18n.T(locale, "settings.update_log_clear_failed")
		}
		writeJSON(w, code, map[string]any{"message": msg})
		return
	}
	writeJSON(w, http.StatusOK, state)
}

func (c *SettingsController) DownloadProjectUpdateLog(w http.ResponseWriter, r *http.Request) {
	if c.Updates == nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"message": i18n.T(auth.LocaleFromContext(r.Context()), "settings.update_not_available")})
		return
	}
	raw, err := c.Updates.ReadFullLog()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{
			"message": i18n.T(auth.LocaleFromContext(r.Context()), "settings.update_log_download_failed"),
		})
		return
	}
	writeText(w, string(raw), "text/plain; charset=utf-8", `attachment; filename="awg-gui-update.log"`)
}

func (c *SettingsController) ReinstallProjectUpdate(w http.ResponseWriter, r *http.Request) {
	if c.Updates == nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"message": i18n.T(auth.LocaleFromContext(r.Context()), "settings.update_not_available")})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	status := c.Updates.Status(locale, false)
	if ok, _ := status["running"].(bool); ok {
		writeJSON(w, http.StatusConflict, status)
		return
	}
	state, err := c.Updates.ReinstallCurrent(locale)
	if err != nil {
		msg := err.Error()
		code := http.StatusInternalServerError
		switch msg {
		case "reinstall_not_available":
			msg = i18n.T(locale, "settings.reinstall_not_available")
			code = http.StatusUnprocessableEntity
		case "update_already_running":
			msg = i18n.T(locale, "settings.update_already_running")
			code = http.StatusConflict
		}
		writeJSON(w, code, map[string]any{"message": msg})
		return
	}
	writeJSON(w, http.StatusAccepted, state)
}

func (c *SettingsController) RetryStuckProjectUpdate(w http.ResponseWriter, r *http.Request) {
	if c.Updates == nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"message": i18n.T(auth.LocaleFromContext(r.Context()), "settings.update_not_available")})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	var req map[string]any
	_ = decodeJSON(r, &req)
	version := strings.TrimSpace(asString(req["version"]))
	if version != "" && !versionRE.MatchString(version) {
		write422(w, r)
		return
	}
	state, err := c.Updates.RetryStuck(locale, version)
	if err != nil {
		msg := err.Error()
		code := http.StatusInternalServerError
		switch msg {
		case "update_not_stuck":
			msg = i18n.T(locale, "settings.update_not_stuck")
			code = http.StatusConflict
		case "update_not_available":
			msg = i18n.T(locale, "settings.update_not_available")
			code = http.StatusUnprocessableEntity
		case "update_already_running":
			msg = i18n.T(locale, "settings.update_already_running")
			code = http.StatusConflict
		}
		writeJSON(w, code, map[string]any{"message": msg})
		return
	}
	writeJSON(w, http.StatusAccepted, state)
}

func (c *SettingsController) AWGKernelStatus(w http.ResponseWriter, r *http.Request) {
	if c.PanelOps == nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"ok": false, "message": "panel-ops unavailable", "module_loaded": false, "package_installed": false,
			"module_blacklisted": false, "awg_datapath": "unknown", "os_family": "unknown", "script_present": false,
			"op": map[string]any{"status": "error", "message": "panel-ops unavailable", "running": false},
		})
		return
	}
	data, err := c.PanelOps.AWGKernelStatus()
	if err != nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]any{
			"ok": false, "message": err.Error(), "module_loaded": false, "package_installed": false,
			"module_blacklisted": false, "awg_datapath": "unknown", "os_family": "unknown", "script_present": false,
			"op": map[string]any{"status": "error", "message": err.Error(), "running": false},
		})
		return
	}
	writeJSON(w, http.StatusOK, data)
}

func (c *SettingsController) AWGKernelInstall(w http.ResponseWriter, r *http.Request) {
	c.startKernelOp(w, r, "install")
}

func (c *SettingsController) AWGKernelUninstall(w http.ResponseWriter, r *http.Request) {
	c.startKernelOp(w, r, "uninstall")
}

func (c *SettingsController) startKernelOp(w http.ResponseWriter, r *http.Request, op string) {
	locale := auth.LocaleFromContext(r.Context())
	if c.PanelOps == nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": "panel-ops unavailable"})
		return
	}
	result, err := c.PanelOps.StartAWGKernelOp(op)
	if err != nil {
		if err.Error() == "kernel_op_already_running" {
			writeJSON(w, http.StatusConflict, map[string]any{"message": i18n.T(locale, "settings.awg_kernel_already_running")})
			return
		}
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	writeJSON(w, http.StatusAccepted, result)
}

func (c *SettingsController) SSLIssueStart(w http.ResponseWriter, r *http.Request) {
	if c.SSL == nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": "ssl unavailable"})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	email := strings.TrimSpace(asString(req["email"]))
	if email == "" {
		write422(w, r)
		return
	}
	renew, _ := asBool(req["renew"])
	challenge, err := c.SSL.StartIssue(r.Context(), locale, email, renew)
	if err != nil {
		c.sslErrorWithRecover(w, r, err.Error(), statusForSSLErr(err))
		return
	}
	if _, ok := challenge["activated"]; ok {
		writeJSON(w, http.StatusOK, map[string]any{
			"ok": true, "recovered": true, "redirect": true,
			"ssl": c.sslStatus(r), "settings": c.settingsPayload(r.Context()),
			"panel_url": c.AWG.ResolvePanelURL(r.Context(), requestHost(r)),
			"message":   i18n.T(locale, "settings.ssl_already_issued"),
		})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "challenge": challenge, "ssl": c.sslStatus(r),
		"message": i18n.T(locale, "settings.ssl_add_txt_record"),
	})
}

func (c *SettingsController) SSLIssueComplete(w http.ResponseWriter, r *http.Request) {
	if c.SSL == nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": "ssl unavailable"})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	sslStatus, err := c.SSL.CompleteIssue(r.Context(), locale)
	if err != nil {
		c.sslErrorWithRecover(w, r, err.Error(), statusForSSLErr(err))
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "redirect": true, "ssl": sslStatus,
		"settings": c.settingsPayload(r.Context()),
		"panel_url": c.AWG.ResolvePanelURL(r.Context(), requestHost(r)),
		"message":   i18n.T(locale, "settings.ssl_issued"),
	})
}

func (c *SettingsController) SSLRecover(w http.ResponseWriter, r *http.Request) {
	if c.SSL == nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": "ssl unavailable"})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	sslStatus, err := c.SSL.RecoverIfCertificateExists(r.Context(), locale)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	if sslStatus == nil {
		writeJSON(w, http.StatusNotFound, map[string]any{"ok": false, "message": i18n.T(locale, "settings.ssl_cert_not_found")})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "recovered": true, "redirect": true, "ssl": sslStatus,
		"settings": c.settingsPayload(r.Context()),
		"panel_url": c.AWG.ResolvePanelURL(r.Context(), requestHost(r)),
		"message":   i18n.T(locale, "settings.ssl_cert_found_enabled"),
	})
}

func (c *SettingsController) SSLDisable(w http.ResponseWriter, r *http.Request) {
	if c.SSL == nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": "ssl unavailable"})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	sslStatus, err := c.SSL.Disable(r.Context(), locale)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "ssl": sslStatus, "settings": c.settingsPayload(r.Context()),
		"panel_url": c.AWG.ResolvePanelURL(r.Context(), requestHost(r)),
		"message":   i18n.T(locale, "settings.https_disabled"),
	})
}

func (c *SettingsController) SSLAbort(w http.ResponseWriter, r *http.Request) {
	if c.SSL == nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": "ssl unavailable"})
		return
	}
	locale := auth.LocaleFromContext(r.Context())
	c.SSL.AbortChallenge(r.Context(), false)
	recovered, _ := c.SSL.RecoverIfCertificateExists(r.Context(), locale)
	if recovered != nil {
		writeJSON(w, http.StatusOK, map[string]any{
			"ok": true, "recovered": true, "redirect": true, "ssl": recovered,
			"settings": c.settingsPayload(r.Context()),
			"panel_url": c.AWG.ResolvePanelURL(r.Context(), requestHost(r)),
			"message":   i18n.T(locale, "settings.ssl_aborted_but_cert_found"),
		})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "ssl": c.sslStatus(r), "message": i18n.T(locale, "settings.ssl_issue_aborted"),
	})
}

func (c *SettingsController) sslErrorWithRecover(w http.ResponseWriter, r *http.Request, message string, status int) {
	locale := auth.LocaleFromContext(r.Context())
	if c.SSL != nil && (strings.Contains(message, "Successfully received certificate") || c.SSL.HasLiveCertificate()) {
		if sslStatus, err := c.SSL.RecoverIfCertificateExists(r.Context(), locale); err == nil && sslStatus != nil {
			writeJSON(w, http.StatusOK, map[string]any{
				"ok": true, "recovered": true, "redirect": true, "ssl": sslStatus,
				"settings": c.settingsPayload(r.Context()),
				"panel_url": c.AWG.ResolvePanelURL(r.Context(), requestHost(r)),
				"message":   i18n.T(locale, "settings.ssl_was_already_issued"),
			})
			return
		}
	}
	writeJSON(w, status, map[string]any{"message": message})
}

func statusForSSLErr(err error) int {
	if _, ok := err.(*ssl.ArgError); ok {
		return http.StatusUnprocessableEntity
	}
	return http.StatusInternalServerError
}

func (c *SettingsController) TestWebhook(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	hookURL := c.Settings.GetValue(r.Context(), "failure_webhook_url", "")
	if hookURL == "" {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{"ok": false, "message": i18n.T(locale, "settings.webhook_url_empty")})
		return
	}
	host, _ := os.Hostname()
	if host == "" {
		host = "unknown"
	}
	payload := map[string]any{
		"schema_version": "1.0",
		"event":          "awg_gui.test",
		"severity":       "info",
		"source":         "awg-gui",
		"project":        "awggui",
		"hostname":       host,
		"timestamp":      time.Now().Format(time.RFC3339),
		"code":           "awg_gui.test",
		"message":        "Test failure webhook from AmneziaWG GUI admin",
		"panel_url":      c.AWG.ResolvePanelURL(r.Context(), requestHost(r)),
		"details":        map[string]any{"trigger": "admin_ui"},
	}
	body, _ := json.Marshal(payload)
	req, err := http.NewRequest(http.MethodPost, hookURL, bytes.NewReader(body))
	if err != nil {
		writeJSON(w, http.StatusOK, map[string]any{"ok": false, "exit_code": 1, "stderr": err.Error(), "payload": payload})
		return
	}
	req.Header.Set("Content-Type", "application/json")
	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		writeJSON(w, http.StatusOK, map[string]any{"ok": false, "exit_code": 1, "stderr": err.Error(), "payload": payload})
		return
	}
	defer resp.Body.Close()
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": resp.StatusCode >= 200 && resp.StatusCode < 300, "exit_code": 0, "stderr": "", "payload": payload,
	})
}

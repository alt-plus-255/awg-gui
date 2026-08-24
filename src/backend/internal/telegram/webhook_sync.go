package telegram

import (
	"context"
	"log"
	"net/url"
	"strings"
	"time"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/resolver"
)

type WebhookSync struct {
	Settings *Settings
	Bot      *Client
	AWG      *awg.Service
	Pool     *ProxyPool
	Resolver *resolver.Service
}

func (s *WebhookSync) SyncAfterSettingsChange(ctx context.Context, proxiesChanged bool) map[string]any {
	s.Pool.ClearCache(ctx)
	needsResolverApply := proxiesChanged
	if s.Settings.HasEnabledConnectionProxies(ctx) && s.Settings.EnsureMixedAuth(ctx) {
		needsResolverApply = true
	}
	if needsResolverApply && s.Resolver != nil {
		if err := s.Resolver.Apply(ctx, resolver.ApplyOpts{RefreshSubscriptions: false}); err != nil {
			log.Printf("telegram.resolver_apply_after_proxy_change: %v", err)
		}
	}
	if !s.Settings.IsConfigured(ctx) {
		return map[string]any{"ok": true, "message": "not_configured"}
	}
	if s.Settings.Mode(ctx) == ModeWebhook {
		secret := s.Settings.WebhookSecret(ctx)
		hookURL := strings.TrimRight(s.AWG.ResolvePanelURL(ctx, ""), "/") + "/api/telegram/webhook/" + secret
		result := s.Bot.SetWebhook(ctx, hookURL, secret)
		if !resultOK(result) {
			msg := strResult(result, "error")
			if msg == "" {
				msg = strResult(result, "description")
			}
			if msg == "" {
				msg = "setWebhook failed"
			}
			return map[string]any{"ok": false, "message": msg}
		}
		return map[string]any{"ok": true, "message": "webhook_set"}
	}
	result := s.Bot.DeleteWebhook(ctx, false)
	if !resultOK(result) {
		msg := strResult(result, "error")
		if msg == "" {
			msg = strResult(result, "description")
		}
		if msg == "" {
			msg = "deleteWebhook failed"
		}
		return map[string]any{"ok": false, "message": msg}
	}
	return map[string]any{"ok": true, "message": "webhook_deleted"}
}

func (s *WebhookSync) TestBot(ctx context.Context, locale string, probeProxies bool) map[string]any {
	if s.Settings.Token(ctx) == "" {
		return map[string]any{
			"ok": false, "error": "token_missing",
			"message": i18n.T(locale, "settings.telegram_token_missing"),
		}
	}
	me := s.Bot.GetMe(ctx)
	if !resultOK(me) {
		raw := strResult(me, "error")
		if raw == "" {
			raw = strResult(me, "description")
		}
		if raw == "" {
			raw = "getMe failed"
		}
		return map[string]any{
			"ok": false, "error": "bot_unreachable",
			"message": i18n.Tf(locale, "settings.telegram_bot_unreachable", map[string]string{"detail": raw}),
		}
	}
	bot, _ := me["result"].(map[string]any)
	if bot == nil {
		bot = map[string]any{}
	}
	out := map[string]any{
		"ok": true, "bot": bot, "message": i18n.T(locale, "settings.telegram_bot_ok"),
	}
	if probeProxies && s.Settings.Mode(ctx) == ModePolling {
		proxies := s.Pool.ProbeStatus(ctx)
		out["proxies"] = proxies
		if len(proxies) == 0 {
			out["message"] = i18n.T(locale, "settings.telegram_proxies_empty")
		} else {
			okCount := 0
			for _, row := range proxies {
				if ok, _ := row["ok"].(bool); ok {
					okCount++
				}
			}
			out["message"] = i18n.Tf(locale, "settings.telegram_proxies_probed", map[string]string{
				"ok": itoa(okCount), "total": itoa(len(proxies)),
			})
		}
	}
	return out
}

func (s *WebhookSync) TestProxyURL(ctx context.Context, locale, rawURL, tokenOverride string) map[string]any {
	rawURL = strings.TrimSpace(rawURL)
	if rawURL == "" {
		return map[string]any{"ok": false, "error": "url_empty", "message": i18n.T(locale, "settings.telegram_proxy_url_empty")}
	}
	parts, err := url.Parse(rawURL)
	if err != nil || parts.Host == "" {
		return map[string]any{"ok": false, "error": "invalid_url", "message": i18n.T(locale, "settings.telegram_proxy_invalid_url")}
	}
	scheme := strings.ToLower(parts.Scheme)
	switch scheme {
	case "socks5", "socks5h", "http", "https":
	default:
		return map[string]any{"ok": false, "error": "unsupported_scheme", "message": i18n.T(locale, "settings.telegram_proxy_unsupported_scheme")}
	}
	token := strings.TrimSpace(tokenOverride)
	if token == "" || strings.Contains(token, "*") {
		token = s.Settings.Token(ctx)
	}
	if token == "" {
		return map[string]any{"ok": false, "error": "token_missing", "message": i18n.T(locale, "settings.telegram_token_missing_for_proxy")}
	}
	u := rawURL
	detail := s.Bot.ProbeLatencyDetailed(ctx, &u, 12*time.Second, token)
	if ok, _ := detail["ok"].(bool); !ok {
		errCode := asStr(detail["error"])
		if errCode == "" {
			errCode = "proxy_unreachable"
		}
		var message string
		switch errCode {
		case "token_missing":
			message = i18n.T(locale, "settings.telegram_token_missing_for_proxy")
		case "telegram_rejected":
			message = i18n.Tf(locale, "settings.telegram_proxy_telegram_rejected", map[string]string{"detail": asStr(detail["description"])})
		default:
			message = i18n.T(locale, "settings.telegram_proxy_unreachable")
		}
		return map[string]any{"ok": false, "error": errCode, "message": message}
	}
	ms := 0
	if n, ok := detail["latency_ms"].(int); ok {
		ms = n
	}
	return map[string]any{
		"ok": true, "latency_ms": ms,
		"url_masked": s.Settings.MaskProxyURL(rawURL),
		"message":    i18n.Tf(locale, "settings.telegram_proxy_ok", map[string]string{"ms": itoa(ms)}),
	}
}

func (s *WebhookSync) EnsureWebhookSecret(ctx context.Context) string {
	return s.Settings.WebhookSecret(ctx)
}

func (s *WebhookSync) NewProxyID() string {
	return strings.ToLower(randomString(8))
}

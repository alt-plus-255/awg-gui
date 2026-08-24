package telegram

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/url"
	"strconv"
	"strings"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/settings"
)

const (
	ModePolling  = "polling"
	ModeWebhook  = "webhook"
	LangEN       = "en"
	LangRU       = "ru"
	StrategyFastest = "fastest"
	StrategyFirstOK = "first_ok"
	MixedInboundPort = 18088
)

type Proxy struct {
	ID           string `json:"id"`
	Type         string `json:"type"`
	URL          string `json:"url,omitempty"`
	URLMasked    string `json:"url_masked,omitempty"`
	ConnectionID int64  `json:"connection_id,omitempty"`
	Enabled      bool   `json:"enabled"`
}

type Settings struct {
	Store *settings.Store
	Cfg   config.Config
}

func NewSettings(st *settings.Store, cfg config.Config) *Settings {
	return &Settings{Store: st, Cfg: cfg}
}

func (s *Settings) Token(ctx context.Context) string {
	return strings.TrimSpace(s.Store.GetValue(ctx, "telegram_bot_token", ""))
}

func (s *Settings) AdminID(ctx context.Context) string {
	return strings.TrimSpace(s.Store.GetValue(ctx, "telegram_admin_id", ""))
}

func (s *Settings) Mode(ctx context.Context) string {
	mode := strings.TrimSpace(s.Store.GetValue(ctx, "telegram_mode", ModePolling))
	if mode == ModePolling || mode == ModeWebhook {
		return mode
	}
	return ModePolling
}

func (s *Settings) Language(ctx context.Context) string {
	lang := strings.TrimSpace(s.Store.GetValue(ctx, "telegram_language", LangEN))
	if lang == LangEN || lang == LangRU {
		return lang
	}
	return LangEN
}

func (s *Settings) ProxyStrategy(ctx context.Context) string {
	st := strings.TrimSpace(s.Store.GetValue(ctx, "telegram_proxy_strategy", StrategyFastest))
	if st == StrategyFastest || st == StrategyFirstOK {
		return st
	}
	return StrategyFastest
}

func (s *Settings) NotificationsEnabled(ctx context.Context) bool {
	return settings.AsBool(s.Store.GetValue(ctx, "telegram_notifications_enabled", "1"))
}

func (s *Settings) DailyReportEnabled(ctx context.Context) bool {
	return settings.AsBool(s.Store.GetValue(ctx, "telegram_daily_report_enabled", "1"))
}

func (s *Settings) WebhookSecret(ctx context.Context) string {
	secret := strings.TrimSpace(s.Store.GetValue(ctx, "telegram_webhook_secret", ""))
	if secret != "" {
		return secret
	}
	secret = randomString(32)
	_ = s.Store.Set(ctx, "telegram_webhook_secret", secret)
	return secret
}

func (s *Settings) Proxies(ctx context.Context) []Proxy {
	raw := s.Store.GetValue(ctx, "telegram_proxies", "[]")
	var decoded []map[string]any
	if json.Unmarshal([]byte(raw), &decoded) != nil {
		return nil
	}
	var out []Proxy
	for _, row := range decoded {
		typ, _ := row["type"].(string)
		enabled := true
		switch v := row["enabled"].(type) {
		case bool:
			enabled = v
		case string:
			enabled = settings.AsBool(v)
		case float64:
			enabled = v != 0
		}
		id := strings.TrimSpace(asStr(row["id"]))
		if id == "" {
			id = strings.ToLower(randomString(8))
		}
		if typ == "url" {
			u := strings.TrimSpace(asStr(row["url"]))
			if u == "" {
				continue
			}
			out = append(out, Proxy{ID: id, Type: "url", URL: u, Enabled: enabled})
			continue
		}
		if typ == "connection" {
			cid := asInt64(row["connection_id"])
			if cid < 1 {
				continue
			}
			out = append(out, Proxy{ID: id, Type: "connection", ConnectionID: cid, Enabled: enabled})
		}
	}
	return out
}

func (s *Settings) IsConfigured(ctx context.Context) bool {
	return s.Token(ctx) != "" && s.AdminID(ctx) != ""
}

func (s *Settings) IsAdmin(ctx context.Context, telegramUserID any) bool {
	admin := s.AdminID(ctx)
	if admin == "" || telegramUserID == nil {
		return false
	}
	return asStr(telegramUserID) == admin
}

func (s *Settings) EnabledConnectionIDs(ctx context.Context) []int64 {
	seen := map[int64]bool{}
	var ids []int64
	for _, p := range s.Proxies(ctx) {
		if p.Type != "connection" || !p.Enabled {
			continue
		}
		if p.ConnectionID < 1 || seen[p.ConnectionID] {
			continue
		}
		seen[p.ConnectionID] = true
		ids = append(ids, p.ConnectionID)
	}
	return ids
}

func (s *Settings) HasEnabledConnectionProxies(ctx context.Context) bool {
	return len(s.EnabledConnectionIDs(ctx)) > 0
}

func (s *Settings) MixedAuth(ctx context.Context) (user, pass string) {
	s.EnsureMixedAuth(ctx)
	return strings.TrimSpace(s.Store.GetValue(ctx, "telegram_mixed_auth_user", "")),
		strings.TrimSpace(s.Store.GetValue(ctx, "telegram_mixed_auth_pass", ""))
}

func (s *Settings) EnsureMixedAuth(ctx context.Context) bool {
	user := strings.TrimSpace(s.Store.GetValue(ctx, "telegram_mixed_auth_user", ""))
	pass := strings.TrimSpace(s.Store.GetValue(ctx, "telegram_mixed_auth_pass", ""))
	if user != "" && pass != "" {
		return false
	}
	_ = s.Store.Set(ctx, "telegram_mixed_auth_user", "tg_"+strings.ToLower(randomString(10)))
	_ = s.Store.Set(ctx, "telegram_mixed_auth_pass", randomString(32))
	return true
}

func (s *Settings) MixedProxyURL(ctx context.Context) string {
	host := strings.TrimSpace(s.Cfg.AWGProxyHost)
	if host == "" {
		host = "awg"
	}
	user, pass := s.MixedAuth(ctx)
	return "socks5h://" + url.QueryEscape(user) + ":" + url.QueryEscape(pass) + "@" + host + ":" + itoa(MixedInboundPort)
}

func (s *Settings) MaskToken(token string) string {
	if token == "" {
		token = ""
	}
	if token == "" {
		return ""
	}
	if len(token) <= 10 {
		return "********"
	}
	n := len(token) - 8
	if n < 4 {
		n = 4
	}
	return token[:4] + strings.Repeat("*", n) + token[len(token)-4:]
}

func (s *Settings) EncodeProxies(proxies []Proxy) string {
	b, err := json.Marshal(proxies)
	if err != nil || len(proxies) == 0 {
		if err != nil {
			return "[]"
		}
	}
	if b == nil {
		return "[]"
	}
	return string(b)
}

func (s *Settings) ForAPI(ctx context.Context) map[string]any {
	proxies := s.Proxies(ctx)
	out := make([]map[string]any, 0, len(proxies))
	for _, p := range proxies {
		row := map[string]any{"id": p.ID, "type": p.Type, "enabled": p.Enabled}
		if p.Type == "url" {
			masked := s.MaskProxyURL(p.URL)
			row["url"] = masked
			row["url_masked"] = masked
		}
		if p.Type == "connection" {
			row["connection_id"] = p.ConnectionID
		}
		out = append(out, row)
	}
	webhookSet := strings.TrimSpace(s.Store.GetValue(ctx, "telegram_webhook_secret", "")) != ""
	secret := ""
	if webhookSet {
		secret = "********"
	}
	notifs, daily := "0", "0"
	if s.NotificationsEnabled(ctx) {
		notifs = "1"
	}
	if s.DailyReportEnabled(ctx) {
		daily = "1"
	}
	return map[string]any{
		"telegram_bot_token":              s.MaskToken(s.Token(ctx)),
		"telegram_bot_token_set":          s.Token(ctx) != "",
		"telegram_admin_id":               s.AdminID(ctx),
		"telegram_language":               s.Language(ctx),
		"telegram_mode":                   s.Mode(ctx),
		"telegram_proxies":                out,
		"telegram_proxy_strategy":         s.ProxyStrategy(ctx),
		"telegram_notifications_enabled":  notifs,
		"telegram_daily_report_enabled":   daily,
		"telegram_webhook_secret":         secret,
		"telegram_webhook_secret_set":     webhookSet,
	}
}

func (s *Settings) MaskProxyURL(raw string) string {
	u, err := url.Parse(raw)
	if err != nil {
		return "***"
	}
	scheme := u.Scheme
	if scheme == "" {
		scheme = "socks5"
	}
	host := u.Hostname()
	port := ""
	if u.Port() != "" {
		port = ":" + u.Port()
	}
	user := ""
	if u.User != nil {
		user = "***@"
	}
	return scheme + "://" + user + host + port
}

func randomString(n int) string {
	b := make([]byte, (n+1)/2)
	_, _ = rand.Read(b)
	s := hex.EncodeToString(b)
	if len(s) > n {
		return s[:n]
	}
	return s
}

func asStr(v any) string {
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return strconv.FormatInt(int64(t), 10)
	case json.Number:
		return t.String()
	case int:
		return strconv.Itoa(t)
	case int64:
		return strconv.FormatInt(t, 10)
	default:
		if v == nil {
			return ""
		}
		return fmt.Sprint(v)
	}
}

func asInt64(v any) int64 {
	switch t := v.(type) {
	case float64:
		return int64(t)
	case int64:
		return t
	case int:
		return int64(t)
	case json.Number:
		n, _ := t.Int64()
		return n
	case string:
		n, _ := strconv.ParseInt(strings.TrimSpace(t), 10, 64)
		return n
	}
	return 0
}

func itoa(n int) string {
	return strconv.Itoa(n)
}

package telegram

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

type APIResult map[string]any

type Client struct {
	Settings *Settings
	Pool     *ProxyPool
}

func (c *Client) IsReady(ctx context.Context) bool {
	return c.Settings.Token(ctx) != ""
}

func (c *Client) GetMe(ctx context.Context) APIResult {
	return c.Call(ctx, "getMe", nil, 30*time.Second, true)
}

func (c *Client) SendMessage(ctx context.Context, chatID, text string, extra map[string]any) APIResult {
	payload := map[string]any{
		"chat_id":                  chatID,
		"text":                     text,
		"parse_mode":               "HTML",
		"disable_web_page_preview": true,
	}
	for k, v := range extra {
		payload[k] = v
	}
	return c.Call(ctx, "sendMessage", payload, 30*time.Second, true)
}

func (c *Client) EditMessageText(ctx context.Context, chatID string, messageID int, text string, extra map[string]any) APIResult {
	payload := map[string]any{
		"chat_id":                  chatID,
		"message_id":               messageID,
		"text":                     text,
		"parse_mode":               "HTML",
		"disable_web_page_preview": true,
	}
	for k, v := range extra {
		payload[k] = v
	}
	return c.Call(ctx, "editMessageText", payload, 30*time.Second, true)
}

func (c *Client) AnswerCallbackQuery(ctx context.Context, callbackQueryID, text string, showAlert bool) APIResult {
	payload := map[string]any{"callback_query_id": callbackQueryID}
	if text != "" {
		payload["text"] = text
	}
	if showAlert {
		payload["show_alert"] = true
	}
	return c.Call(ctx, "answerCallbackQuery", payload, 30*time.Second, true)
}

func (c *Client) GetUpdates(ctx context.Context, offset int, timeoutSec int) APIResult {
	return c.Call(ctx, "getUpdates", map[string]any{
		"offset":          offset,
		"timeout":         timeoutSec,
		"allowed_updates": []string{"message", "callback_query"},
	}, time.Duration(timeoutSec+10)*time.Second, true)
}

func (c *Client) SetWebhook(ctx context.Context, hookURL, secretToken string) APIResult {
	payload := map[string]any{
		"url":                  hookURL,
		"allowed_updates":      []string{"message", "callback_query"},
		"drop_pending_updates": false,
	}
	if secretToken != "" {
		payload["secret_token"] = secretToken
	}
	return c.Call(ctx, "setWebhook", payload, 30*time.Second, true)
}

func (c *Client) DeleteWebhook(ctx context.Context, dropPending bool) APIResult {
	return c.Call(ctx, "deleteWebhook", map[string]any{"drop_pending_updates": dropPending}, 30*time.Second, true)
}

func (c *Client) Call(ctx context.Context, method string, params map[string]any, timeout time.Duration, usePool bool) APIResult {
	token := c.Settings.Token(ctx)
	if token == "" {
		return APIResult{"ok": false, "error": "telegram_token_missing"}
	}
	apiURL := "https://api.telegram.org/bot" + token + "/" + method
	var proxy *string
	if usePool && c.Pool != nil {
		proxy = c.Pool.ResolveProxyURL(ctx, nil)
	}
	parsed, err := c.postJSON(ctx, apiURL, params, proxy, timeout)
	if err != nil {
		if proxy != nil && c.Pool != nil {
			c.Pool.MarkFailed(ctx, *proxy)
		}
		return APIResult{"ok": false, "error": err.Error()}
	}
	ok, _ := parsed["ok"].(bool)
	if !ok && proxy != nil && c.Pool != nil {
		c.Pool.MarkFailed(ctx, *proxy)
		fallback := c.Pool.ResolveProxyURL(ctx, []string{*proxy})
		if fallback != nil && *fallback != *proxy {
			parsed2, err2 := c.postJSON(ctx, apiURL, params, fallback, timeout)
			if err2 == nil {
				if ok2, _ := parsed2["ok"].(bool); ok2 {
					c.Pool.MarkSuccess(ctx, *fallback)
					return parsed2
				}
			}
		}
	} else if ok && proxy != nil && c.Pool != nil {
		c.Pool.MarkSuccess(ctx, *proxy)
	}
	return parsed
}

func (c *Client) ProbeLatency(ctx context.Context, proxyURL *string, timeout time.Duration, tokenOverride string) *int {
	d := c.ProbeLatencyDetailed(ctx, proxyURL, timeout, tokenOverride)
	if ok, _ := d["ok"].(bool); ok {
		if n, ok := d["latency_ms"].(int); ok {
			return &n
		}
	}
	return nil
}

func (c *Client) ProbeLatencyDetailed(ctx context.Context, proxyURL *string, timeout time.Duration, tokenOverride string) map[string]any {
	token := strings.TrimSpace(tokenOverride)
	if token == "" || strings.Contains(token, "*") {
		token = c.Settings.Token(ctx)
	}
	if token == "" {
		return map[string]any{"ok": false, "error": "token_missing"}
	}
	apiURL := "https://api.telegram.org/bot" + token + "/getMe"
	started := time.Now()
	parsed, err := c.postJSON(ctx, apiURL, map[string]any{}, proxyURL, timeout)
	if err != nil {
		return map[string]any{"ok": false, "error": "proxy_unreachable", "description": err.Error()}
	}
	if ok, _ := parsed["ok"].(bool); !ok {
		desc := strResult(parsed, "description")
		if desc == "" {
			desc = strResult(parsed, "error")
		}
		if desc == "" {
			desc = "telegram_error"
		}
		return map[string]any{"ok": false, "error": "telegram_rejected", "description": desc}
	}
	ms := int(time.Since(started).Milliseconds())
	if ms < 1 {
		ms = 1
	}
	return map[string]any{"ok": true, "latency_ms": ms}
}

func (c *Client) postJSON(ctx context.Context, apiURL string, params map[string]any, proxyURL *string, timeout time.Duration) (APIResult, error) {
	if params == nil {
		params = map[string]any{}
	}
	body, _ := json.Marshal(params)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, apiURL, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	client := &http.Client{Timeout: timeout, Transport: proxyTransport(proxyURL, timeout)}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	var parsed APIResult
	if json.Unmarshal(raw, &parsed) != nil {
		return APIResult{"ok": false, "error": "invalid_json", "description": fmt.Sprintf("HTTP %d", resp.StatusCode)}, nil
	}
	if ok, _ := parsed["ok"].(bool); !ok {
		desc := strResult(parsed, "description")
		if desc == "" {
			desc = "telegram_error"
		}
		parsed["error"] = desc
	}
	return parsed, nil
}

func proxyTransport(proxyURL *string, timeout time.Duration) http.RoundTripper {
	dialer := &net.Dialer{Timeout: minDuration(10*time.Second, timeout)}
	tr := &http.Transport{DialContext: dialer.DialContext}
	if proxyURL == nil || strings.TrimSpace(*proxyURL) == "" {
		return tr
	}
	u, err := url.Parse(strings.TrimSpace(*proxyURL))
	if err != nil {
		return tr
	}
	scheme := strings.ToLower(u.Scheme)
	switch scheme {
	case "http", "https":
		tr.Proxy = http.ProxyURL(u)
		return tr
	case "socks5", "socks5h":
		host := u.Hostname()
		port := u.Port()
		if port == "" {
			port = "1080"
		}
		user, pass := "", ""
		if u.User != nil {
			user = u.User.Username()
			pass, _ = u.User.Password()
			if unesc, err := url.QueryUnescape(user); err == nil {
				user = unesc
			}
			if unesc, err := url.QueryUnescape(pass); err == nil {
				pass = unesc
			}
		}
		proxyAddr := net.JoinHostPort(host, port)
		tr.DialContext = func(ctx context.Context, network, addr string) (net.Conn, error) {
			h, p, err := net.SplitHostPort(addr)
			if err != nil {
				return nil, err
			}
			pn, _ := strconv.Atoi(p)
			return dialSOCKS5(proxyAddr, user, pass, h, pn, timeout)
		}
		return tr
	default:
		return tr
	}
}

func minDuration(a, b time.Duration) time.Duration {
	if a < b {
		return a
	}
	return b
}

func strResult(m map[string]any, key string) string {
	if m == nil {
		return ""
	}
	s, _ := m[key].(string)
	return s
}

func resultOK(m APIResult) bool {
	ok, _ := m["ok"].(bool)
	return ok
}

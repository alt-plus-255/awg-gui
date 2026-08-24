package panelops

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/awggui/backend/internal/config"
)

type Client struct {
	BaseURL string
	Token   string
	HTTP    *http.Client
}

func New(cfg config.Config) *Client {
	base := strings.TrimRight(cfg.PanelOpsURL, "/")
	if base == "" {
		base = "http://panel-ops:8090"
	}
	return &Client{
		BaseURL: base,
		Token:   strings.TrimSpace(cfg.PanelOpsToken),
		HTTP:    &http.Client{Timeout: 180 * time.Second},
	}
}

func (c *Client) RecreateCaddy() error {
	body, status, err := c.do(http.MethodPost, "/ops/caddy/recreate", nil, 180*time.Second)
	if err != nil {
		return err
	}
	if status < 200 || status >= 300 {
		return fmt.Errorf("%s", errorFromBody(body, "panel-ops recreate failed"))
	}
	if !jsonOK(body) {
		return fmt.Errorf("%s", errorFromBody(body, "panel-ops recreate failed"))
	}
	return nil
}

func (c *Client) AWGKernelStatus() (map[string]any, error) {
	body, status, err := c.do(http.MethodGet, "/ops/awg-kernel/status", nil, 120*time.Second)
	if err != nil {
		return nil, err
	}
	if status < 200 || status >= 300 {
		return nil, fmt.Errorf("%s", errorFromBody(body, "panel-ops awg-kernel status failed"))
	}
	parsed := decodeMap(body)
	if parsed == nil {
		return map[string]any{"ok": false}, nil
	}
	return parsed, nil
}

func (c *Client) StartAWGKernelOp(op string) (map[string]any, error) {
	if op != "install" && op != "uninstall" {
		return nil, fmt.Errorf("Invalid awg-kernel op")
	}
	body, status, err := c.do(http.MethodPost, "/ops/awg-kernel/"+op, map[string]any{}, 15*time.Second)
	if err != nil {
		return nil, err
	}
	if status == http.StatusConflict {
		return nil, fmt.Errorf("kernel_op_already_running")
	}
	if status < 200 || status >= 300 {
		return nil, fmt.Errorf("%s", errorFromBody(body, "panel-ops awg-kernel op failed"))
	}
	parsed := decodeMap(body)
	if parsed == nil {
		return map[string]any{"ok": true}, nil
	}
	return parsed, nil
}

func (c *Client) StartUpdate(version string) (map[string]any, error) {
	payload := map[string]any{}
	if strings.TrimSpace(version) != "" {
		payload["version"] = version
	}
	body, status, err := c.do(http.MethodPost, "/ops/update/start", payload, 15*time.Second)
	if err != nil {
		return nil, err
	}
	if status < 200 || status >= 300 {
		return nil, fmt.Errorf("%s", errorFromBody(body, "panel-ops update start failed"))
	}
	parsed := decodeMap(body)
	if parsed == nil {
		return map[string]any{"ok": true}, nil
	}
	return parsed, nil
}

func (c *Client) ClearUpdateLog() (map[string]any, error) {
	body, status, err := c.do(http.MethodPost, "/ops/update/clear-log", nil, 15*time.Second)
	if err != nil {
		return nil, err
	}
	if status < 200 || status >= 300 || !jsonOK(body) {
		return nil, fmt.Errorf("%s", errorFromBody(body, "panel-ops clear update log failed"))
	}
	parsed := decodeMap(body)
	if parsed == nil {
		return map[string]any{"ok": true}, nil
	}
	return parsed, nil
}

func (c *Client) do(method, path string, payload any, timeout time.Duration) ([]byte, int, error) {
	if c == nil || strings.TrimSpace(c.Token) == "" {
		return nil, 0, fmt.Errorf("PANEL_OPS_TOKEN is not configured")
	}
	var reader io.Reader
	if payload != nil {
		b, err := json.Marshal(payload)
		if err != nil {
			return nil, 0, err
		}
		reader = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, c.BaseURL+path, reader)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Authorization", "Bearer "+c.Token)
	req.Header.Set("Accept", "application/json")
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	client := c.HTTP
	if client == nil {
		client = &http.Client{Timeout: timeout}
	} else if timeout > 0 {
		clone := *client
		clone.Timeout = timeout
		client = &clone
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(resp.Body)
	return b, resp.StatusCode, nil
}

func decodeMap(body []byte) map[string]any {
	var m map[string]any
	if json.Unmarshal(body, &m) != nil {
		return nil
	}
	return m
}

func jsonOK(body []byte) bool {
	m := decodeMap(body)
	if m == nil {
		return false
	}
	ok, _ := m["ok"].(bool)
	return ok
}

func errorFromBody(body []byte, fallback string) string {
	m := decodeMap(body)
	if m != nil {
		if s, ok := m["error"].(string); ok && strings.TrimSpace(s) != "" {
			return strings.TrimSpace(s)
		}
	}
	if s := strings.TrimSpace(string(body)); s != "" {
		return s
	}
	return fallback
}

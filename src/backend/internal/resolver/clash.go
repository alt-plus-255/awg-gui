package resolver

import (
	"context"
	"encoding/json"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

type Clash struct {
	Docker    Docker
	Container string
}

func (c *Clash) Request(ctx context.Context, path string, query url.Values, timeout time.Duration, probe bool) ClashResp {
	locale := Locale(ctx)
	qs := ""
	if len(query) > 0 {
		qs = "?" + query.Encode()
	}
	addr := ClashAPIAddr
	if probe {
		addr = ClashProbeAPIAddr
	}
	u := "http://" + addr + path + qs
	sec := int(timeout.Seconds())
	if sec < 1 {
		sec = 1
	}
	r, err := c.Docker.Exec(ctx, c.Container, []string{
		"curl", "-sS", "-m", strconv.Itoa(sec),
		"-w", "___HTTP_STATUS___%{http_code}",
		u,
	}, timeout+5*time.Second)
	if err != nil || strings.TrimSpace(r.Stdout) == "" {
		msg := i18n.T(locale, "resolver.clash_api_unavailable")
		if probe {
			msg = i18n.T(locale, "resolver.clash_api_probe_unavailable")
		}
		if err != nil {
			msg = err.Error()
		}
		return ClashResp{Error: &msg}
	}
	out := r.Stdout
	marker := "___HTTP_STATUS___"
	pos := strings.LastIndex(out, marker)
	if pos < 0 {
		msg := i18n.T(locale, "resolver.clash_api_invalid_response")
		return ClashResp{Raw: strings.TrimSpace(out), Error: &msg}
	}
	rawBody := out[:pos]
	status, _ := strconv.Atoi(out[pos+len(marker):])
	var decoded map[string]any
	_ = json.Unmarshal([]byte(rawBody), &decoded)
	if status == 0 {
		msg := i18n.T(locale, "resolver.clash_api_not_ready")
		return ClashResp{Raw: rawBody, Error: &msg}
	}
	ok := status >= 200 && status < 300
	var errStr *string
	if !ok {
		msg := strings.TrimSpace(rawBody)
		if decoded != nil {
			if m := strVal(decoded["message"]); m != "" {
				msg = m
			}
		}
		if msg == "" {
			msg = "HTTP " + strconv.Itoa(status)
		}
		errStr = &msg
	}
	return ClashResp{OK: ok, Status: status, Body: decoded, Raw: rawBody, Error: errStr}
}

func (c *Clash) WaitForAPI(ctx context.Context, attempts int, sleep time.Duration) bool {
	for i := 0; i < attempts; i++ {
		resp := c.Request(ctx, "/version", nil, 3*time.Second, false)
		if resp.OK {
			return true
		}
		time.Sleep(sleep)
	}
	return false
}

func (c *Clash) WaitForProbeAPI(ctx context.Context, attempts int, sleep time.Duration) bool {
	for i := 0; i < attempts; i++ {
		resp := c.Request(ctx, "/version", nil, 3*time.Second, true)
		if resp.OK {
			return true
		}
		time.Sleep(sleep)
	}
	return false
}

func (c *Clash) TrafficByOutboundTag(ctx context.Context) map[string]map[string]any {
	resp := c.Request(ctx, "/connections", nil, 8*time.Second, false)
	out := map[string]map[string]any{}
	if !resp.OK || resp.Body == nil {
		return out
	}
	conns, _ := resp.Body["connections"].([]any)
	for _, raw := range conns {
		conn, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		download := atoiDef(strVal(conn["download"]), 0)
		upload := atoiDef(strVal(conn["upload"]), 0)
		chains, _ := conn["chains"].([]any)
		for _, t := range chains {
			tag, _ := t.(string)
			if !strings.HasPrefix(tag, "conn_") {
				continue
			}
			rollup := tag
			if i := strings.LastIndex(tag, "_"); i > 5 {
				if _, err := strconv.Atoi(tag[i+1:]); err == nil && strings.HasPrefix(tag, "conn_") {
					// conn_N_M → conn_N
					parts := strings.Split(tag, "_")
					if len(parts) == 3 {
						rollup = parts[0] + "_" + parts[1]
					}
				}
			}
			if _, ok := out[rollup]; !ok {
				out[rollup] = map[string]any{"rx": 0, "tx": 0, "active": false}
			}
			out[rollup]["rx"] = atoiDef(strVal(out[rollup]["rx"]), 0) + download
			out[rollup]["tx"] = atoiDef(strVal(out[rollup]["tx"]), 0) + upload
			out[rollup]["active"] = true
		}
	}
	return out
}

func (c *Clash) TestOutboundDelay(ctx context.Context, tag string, timeoutMS int, probe bool) DelayResult {
	locale := Locale(ctx)
	q := url.Values{}
	q.Set("url", DelayTestURL)
	q.Set("timeout", strconv.Itoa(timeoutMS))
	path := "/proxies/" + url.PathEscape(tag) + "/delay"
	to := time.Duration(timeoutMS/1000+5) * time.Second
	resp := c.Request(ctx, path, q, to, probe)
	return parseDelay(locale, resp)
}

func parseDelay(locale string, resp ClashResp) DelayResult {
	if resp.OK && resp.Body != nil {
		if d, ok := resp.Body["delay"]; ok {
			delay := atoiDef(strVal(d), 0)
			if delay > 0 {
				return DelayResult{OK: true, LatencyMS: &delay}
			}
			msg := i18n.T(locale, "resolver.zero_delay")
			return DelayResult{Error: &msg}
		}
	}
	err := i18n.T(locale, "resolver.check_failed")
	if resp.Error != nil {
		err = *resp.Error
	}
	if resp.Raw != "" && strings.Contains(resp.Raw, "{") {
		var j map[string]any
		if json.Unmarshal([]byte(resp.Raw), &j) == nil {
			if m := strVal(j["message"]); m != "" {
				err = m
			}
		}
	}
	err = localizeDelay(locale, err)
	return DelayResult{Error: &err}
}

func (c *Clash) TestOutboundDelaysStreaming(ctx context.Context, keyToTag map[string]string, timeoutMS int, probe bool, onResult func(key string, d DelayResult), shouldCancel func() bool) {
	if len(keyToTag) == 0 {
		return
	}
	type pair struct{ key, tag string }
	var items []pair
	for k, t := range keyToTag {
		items = append(items, pair{k, t})
	}
	sem := make(chan struct{}, 20)
	var wg sync.WaitGroup
	for _, it := range items {
		if shouldCancel != nil && shouldCancel() {
			break
		}
		it := it
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			if shouldCancel != nil && shouldCancel() {
				return
			}
			d := c.TestOutboundDelay(ctx, it.tag, timeoutMS, probe)
			if onResult != nil {
				onResult(it.key, d)
			}
		}()
	}
	wg.Wait()
}

func localizeDelay(locale, err string) string {
	lower := strings.ToLower(err)
	if lower == "timeout" || strings.Contains(lower, "timeout") {
		return i18n.T(locale, "resolver.timeout")
	}
	if strings.Contains(lower, "an error occurred in the delay test") {
		return i18n.T(locale, "resolver.node_unavailable")
	}
	if strings.Contains(lower, "context deadline exceeded") {
		return i18n.T(locale, "resolver.connection_timeout")
	}
	return err
}

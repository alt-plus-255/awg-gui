package resolver

import (
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/awggui/backend/internal/i18n"
)

type SubscriptionFetcher struct {
	Parser OutboundParser
	Clash  ClashSubParser
	Client *http.Client
}

var shareScheme = regexp.MustCompile(`(?i)^(vless|vmess|ss|trojan|hysteria2|hy2|socks5?|socks)://`)

func (f *SubscriptionFetcher) client() *http.Client {
	if f.Client != nil {
		return f.Client
	}
	return &http.Client{Timeout: 20 * time.Second}
}

func (f *SubscriptionFetcher) FetchMerged(rawURL, body string) ([]map[string]any, error) {
	rawURL = strings.TrimSpace(rawURL)
	body = strings.TrimSpace(body)
	if rawURL != "" {
		return f.Fetch(rawURL)
	}
	if body != "" {
		return f.ParseBody(body)
	}
	return nil, FieldErr("subscription_url", "resolver.subscription_parse_failed", nil)
}

func (f *SubscriptionFetcher) Fetch(rawURL string) ([]map[string]any, error) {
	rawURL = strings.TrimSpace(rawURL)
	if rawURL == "" {
		return nil, FieldErr("subscription_url", "resolver.subscription_url_invalid", nil)
	}
	if u, err := url.Parse(rawURL); err != nil || u.Scheme == "" || u.Host == "" {
		return nil, FieldErr("subscription_url", "resolver.subscription_url_invalid", nil)
	}
	body, err := f.download(rawURL)
	if err != nil {
		return nil, err
	}
	return f.ParseBody(body)
}

func (f *SubscriptionFetcher) ParseBody(body string) ([]map[string]any, error) {
	body = strings.TrimSpace(body)
	if body == "" {
		return nil, runtimeKey("resolver.subscription_body_empty")
	}
	if decoded := tryB64Decode(body); decoded != "" {
		body = decoded
	}
	if nodes := f.parseShareURILines(body); len(nodes) > 0 {
		return nodes, nil
	}
	if nodes := f.Clash.Parse(body); len(nodes) > 0 {
		return nodes, nil
	}
	if nodes := f.parseSingBoxJSON(body); len(nodes) > 0 {
		return nodes, nil
	}
	return nil, runtimeKey("resolver.subscription_no_nodes")
}

func (f *SubscriptionFetcher) parseShareURILines(body string) []map[string]any {
	var nodes []map[string]any
	seen := map[string]bool{}
	for _, line := range splitLines(body) {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") || !shareScheme.MatchString(line) {
			continue
		}
		obIn, err := f.Parser.FromShareURL(line)
		if err != nil {
			continue
		}
		ob, err := f.Parser.Normalize(obIn)
		if err != nil || strVal(ob["type"]) == "" || strVal(ob["server"]) == "" {
			continue
		}
		key := sha16(line)
		if seen[key] {
			continue
		}
		seen[key] = true
		nodes = append(nodes, map[string]any{
			"key":      key,
			"name":     nodeName(line, ob),
			"type":     strVal(ob["type"]),
			"server":   strVal(ob["server"]),
			"port":     atoiDef(strVal(ob["server_port"]), 0),
			"outbound": ob,
		})
	}
	return nodes
}

func (f *SubscriptionFetcher) parseSingBoxJSON(body string) []map[string]any {
	if !strings.Contains(body, `"outbounds"`) {
		return nil
	}
	var jsonDoc map[string]any
	if err := json.Unmarshal([]byte(body), &jsonDoc); err != nil {
		return nil
	}
	raw, _ := jsonDoc["outbounds"].([]any)
	if raw == nil {
		return nil
	}
	skip := map[string]bool{"direct": true, "block": true, "dns": true, "selector": true, "urltest": true, "fallback": true}
	var nodes []map[string]any
	for _, item := range raw {
		obIn, ok := item.(map[string]any)
		if !ok || strVal(obIn["type"]) == "" || skip[strVal(obIn["type"])] {
			continue
		}
		ob, err := f.Parser.Normalize(cloneMap(obIn))
		if err != nil || strVal(ob["server"]) == "" {
			continue
		}
		enc, _ := json.Marshal(obIn)
		tag := first(strVal(obIn["tag"]), strVal(ob["server"]), "node")
		if utf8.RuneCountInString(tag) > 120 {
			tag = string([]rune(tag)[:120])
		}
		nodes = append(nodes, map[string]any{
			"key":      sha16(string(enc)),
			"name":     tag,
			"type":     strVal(ob["type"]),
			"server":   strVal(ob["server"]),
			"port":     atoiDef(strVal(ob["server_port"]), 0),
			"outbound": ob,
		})
	}
	return nodes
}

func (f *SubscriptionFetcher) download(rawURL string) (string, error) {
	var last string
	cli := f.client()
	for attempt := 0; attempt < 3; attempt++ {
		if attempt > 0 {
			time.Sleep(time.Duration(800*attempt) * time.Millisecond)
		}
		req, err := http.NewRequest(http.MethodGet, rawURL, nil)
		if err != nil {
			last = err.Error()
			continue
		}
		req.Header.Set("User-Agent", "v2rayN/6.38")
		req.Header.Set("Accept", "*/*")
		resp, err := cli.Do(req)
		if err != nil {
			last = err.Error()
			continue
		}
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 2_000_000))
		resp.Body.Close()
		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			last = "HTTP " + itoa(resp.StatusCode)
			continue
		}
		body := strings.TrimSpace(string(b))
		if body != "" && len(body) > 8 {
			return body, nil
		}
		last = "Empty response"
	}
	return "", runtimeKeyParams("resolver.subscription_fetch_failed_with_error", map[string]string{"error": last})
}

func tryB64Decode(body string) string {
	compact := regexp.MustCompile(`\s+`).ReplaceAllString(body, "")
	if compact == "" || len(compact) < 8 {
		return ""
	}
	if shareScheme.MatchString(body) || strings.Contains(body, "://") {
		return ""
	}
	raw, err := base64.StdEncoding.DecodeString(padB64(compact))
	if err != nil {
		raw, err = base64.RawStdEncoding.DecodeString(compact)
		if err != nil {
			return ""
		}
	}
	decoded := string(raw)
	if decoded == "" {
		return ""
	}
	if !strings.Contains(decoded, "://") && !strings.Contains(decoded, "proxies:") {
		return ""
	}
	return decoded
}

func padB64(s string) string {
	for len(s)%4 != 0 {
		s += "="
	}
	return s
}

func nodeName(uri string, ob map[string]any) string {
	name := fragmentFromURI(uri)
	if name == "" {
		name = remarkFromURI(uri)
	}
	if name != "" {
		if utf8.RuneCountInString(name) > 120 {
			name = string([]rune(name)[:120])
		}
		return name
	}
	return strVal(ob["type"]) + "://" + strVal(ob["server"]) + ":" + itoa(atoiDef(strVal(ob["server_port"]), 0))
}

func fragmentFromURI(uri string) string {
	i := strings.LastIndex(uri, "#")
	if i < 0 {
		return ""
	}
	name, _ := url.QueryUnescape(uri[i+1:])
	return strings.Join(strings.Fields(strings.TrimSpace(name)), " ")
}

func remarkFromURI(uri string) string {
	u, err := url.Parse(uri)
	if err != nil || u.RawQuery == "" {
		return ""
	}
	q := u.Query()
	for _, f := range []string{"remarks", "remark", "ps", "note"} {
		if v := q.Get(f); v != "" {
			name, _ := url.QueryUnescape(v)
			return strings.Join(strings.Fields(strings.TrimSpace(name)), " ")
		}
	}
	return ""
}

func splitLines(s string) []string {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	s = strings.ReplaceAll(s, "\r", "\n")
	return strings.Split(s, "\n")
}

func itoa(n int) string { return strconv.Itoa(n) }

type runtimeErr struct{ key string; params map[string]string }

func (e *runtimeErr) Error() string { return e.key }

func runtimeKey(key string) error { return &runtimeErr{key: key} }
func runtimeKeyParams(key string, p map[string]string) error {
	return &runtimeErr{key: key, params: p}
}

func TranslateErr(locale string, err error) string {
	if err == nil {
		return ""
	}
	if ve, ok := err.(*ValidationError); ok {
		return ve.Translate(locale)
	}
	if re, ok := err.(*runtimeErr); ok {
		return i18n.Tf(locale, re.key, re.params)
	}
	if he, ok := err.(*HTTPError); ok {
		return he.Message
	}
	return err.Error()
}

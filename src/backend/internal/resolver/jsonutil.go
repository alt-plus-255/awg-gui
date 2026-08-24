package resolver

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"strconv"
	"strings"
	"time"
)

func strVal(v any) string {
	if v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	case json.Number:
		return t.String()
	case fmt.Stringer:
		return t.String()
	default:
		return fmt.Sprint(t)
	}
}

func atoiDef(s string, def int) int {
	n, err := strconv.Atoi(strings.TrimSpace(s))
	if err != nil {
		return def
	}
	return n
}

func asList(v any) []string {
	if v == nil {
		return nil
	}
	switch t := v.(type) {
	case []string:
		return t
	case []any:
		out := make([]string, 0, len(t))
		for _, x := range t {
			s := strings.TrimSpace(strVal(x))
			if s != "" {
				out = append(out, s)
			}
		}
		return out
	case string:
		if strings.TrimSpace(t) == "" {
			return nil
		}
		return []string{t}
	default:
		return nil
	}
}

func unmarshalJSON(raw []byte, dest any) {
	raw = bytesTrim(raw)
	if len(raw) == 0 || string(raw) == "null" {
		return
	}
	_ = json.Unmarshal(raw, dest)
}

func bytesTrim(b []byte) []byte {
	return []byte(strings.TrimSpace(string(b)))
}

func marshalJSON(v any) string {
	if v == nil {
		return "null"
	}
	b, err := json.Marshal(v)
	if err != nil {
		return "null"
	}
	return string(b)
}

func marshalPretty(v any) (string, error) {
	b, err := json.MarshalIndent(v, "", "    ")
	if err != nil {
		return "", err
	}
	return string(b) + "\n", nil
}

func isoTime(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.UTC().Format(time.RFC3339)
}

func isoNow() string {
	return time.Now().UTC().Format(time.RFC3339)
}

func ptrStr(s string) *string { return &s }

func strPtrVal(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

func boolPtr(v *bool) any {
	if v == nil {
		return nil
	}
	return *v
}

func intPtr(v *int) any {
	if v == nil {
		return nil
	}
	return *v
}

func sha16(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])[:16]
}

func sha256Hex(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}

func isIPv4(s string) bool {
	ip := net.ParseIP(s)
	return ip != nil && ip.To4() != nil
}

func isIPv6(s string) bool {
	ip := net.ParseIP(s)
	return ip != nil && ip.To4() == nil
}

func isIP(s string) bool {
	return net.ParseIP(s) != nil
}

func uniqueStrings(in []string) []string {
	seen := map[string]bool{}
	out := make([]string, 0, len(in))
	for _, s := range in {
		if s == "" || seen[s] {
			continue
		}
		seen[s] = true
		out = append(out, s)
	}
	return out
}

func cloneMap(m map[string]any) map[string]any {
	if m == nil {
		return map[string]any{}
	}
	out := make(map[string]any, len(m))
	for k, v := range m {
		out[k] = v
	}
	return out
}

func mapGet(m map[string]any, path ...string) any {
	cur := any(m)
	for _, p := range path {
		mm, ok := cur.(map[string]any)
		if !ok {
			return nil
		}
		cur = mm[p]
	}
	return cur
}

func domainOK(s string) bool {
	if s == "" {
		return false
	}
	// (?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}
	parts := strings.Split(s, ".")
	if len(parts) < 2 {
		return false
	}
	tld := parts[len(parts)-1]
	if len(tld) < 2 {
		return false
	}
	for _, c := range tld {
		if c < 'a' || c > 'z' {
			return false
		}
	}
	for _, p := range parts[:len(parts)-1] {
		if len(p) < 1 || len(p) > 63 {
			return false
		}
		if p[0] == '-' || p[len(p)-1] == '-' {
			return false
		}
		for _, c := range p {
			if !((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '-') {
				return false
			}
		}
	}
	return true
}

func hostnameOK(s string) bool {
	if isIP(s) {
		return true
	}
	return domainOK(strings.ToLower(s))
}

func validCIDR(cidr string) bool {
	if !strings.Contains(cidr, "/") {
		return false
	}
	host, mask, ok := strings.Cut(cidr, "/")
	if !ok {
		return false
	}
	n, err := strconv.Atoi(mask)
	if err != nil {
		return false
	}
	if isIPv4(host) {
		return n >= 0 && n <= 32
	}
	if isIPv6(host) {
		return n >= 0 && n <= 128
	}
	return false
}

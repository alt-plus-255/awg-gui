package resolver

import (
	"encoding/base64"
	"encoding/json"
	"net/url"
	"strconv"
	"strings"
)

type OutboundParser struct{}

func (p OutboundParser) FromRequest(configType, shareURL, outboundJSON string) (map[string]any, error) {
	switch configType {
	case "json":
		ob, err := p.FromJSON(outboundJSON)
		if err != nil {
			return nil, err
		}
		return p.Normalize(ob)
	case "url":
		ob, err := p.FromShareURL(shareURL)
		if err != nil {
			return nil, err
		}
		return p.Normalize(ob)
	default:
		return nil, FieldErr("config_type", "resolver.config_type_url_or_json", nil)
	}
}

func (p OutboundParser) Normalize(outbound map[string]any) (map[string]any, error) {
	ob := cloneMap(outbound)
	for _, k := range []string{"tag", "sniff", "sniff_override_destination", "sniff_timeout", "domain_strategy", "udp_disable_domain_unmapping"} {
		delete(ob, k)
	}
	typ := strings.ToLower(strings.TrimSpace(strVal(ob["type"])))
	aliases := map[string]string{"ss": "shadowsocks", "hy2": "hysteria2", "hysteria": "hysteria2", "socks5": "socks"}
	if a, ok := aliases[typ]; ok {
		typ = a
		ob["type"] = typ
	}
	if typ == "" {
		return ob, nil
	}
	if typ == "wireguard" {
		return nil, FieldErr("outbound_json", "resolver.outbound_wireguard_removed", nil)
	}
	if typ == "block" || typ == "dns" {
		return nil, FieldErr("outbound_json", "resolver.outbound_special_removed", map[string]string{"type": typ})
	}
	if typ == "direct" {
		delete(ob, "override_address")
		delete(ob, "override_port")
	}
	if typ == "vless" {
		pe := strVal(ob["packet_encoding"])
		if pe == "" {
			ob["packet_encoding"] = "xudp"
			pe = "xudp"
		}
		if pe == "xudp" {
			delete(ob, "network")
		}
		if tls, ok := ob["tls"].(map[string]any); ok {
			tls["enabled"] = true
			reality, _ := tls["reality"].(map[string]any)
			if reality != nil && (truthy(reality["enabled"]) || strVal(reality["public_key"]) != "") {
				tls["reality"] = map[string]any{
					"enabled":    true,
					"public_key": strVal(reality["public_key"]),
					"short_id":   strVal(reality["short_id"]),
				}
				fp := strVal(mapGet(ob, "tls", "utls", "fingerprint"))
				if fp == "" {
					fp = "chrome"
				}
				tls["utls"] = map[string]any{"enabled": true, "fingerprint": fp}
			}
			ob["tls"] = tls
		}
	}
	if p.needsDomainResolver(ob) {
		ob["domain_resolver"] = "bootstrap"
	}
	return ob, nil
}

func (p OutboundParser) needsDomainResolver(ob map[string]any) bool {
	if _, ok := ob["domain_resolver"]; ok {
		return false
	}
	server, _ := ob["server"].(string)
	if server == "" {
		return false
	}
	return !isIP(server)
}

func (p OutboundParser) FromJSON(raw string) (map[string]any, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil, FieldErr("outbound_json", "resolver.outbound_json_required", nil)
	}
	var decoded any
	if err := json.Unmarshal([]byte(raw), &decoded); err != nil {
		return nil, FieldErr("outbound_json", "resolver.outbound_json_object_expected", nil)
	}
	m, ok := decoded.(map[string]any)
	if !ok {
		return nil, FieldErr("outbound_json", "resolver.outbound_json_object_expected", nil)
	}
	typ, _ := m["type"].(string)
	if strings.TrimSpace(typ) == "" {
		return nil, FieldErr("outbound_json", "resolver.outbound_type_required", nil)
	}
	delete(m, "tag")
	return m, nil
}

func (p OutboundParser) FromShareURL(raw string) (map[string]any, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil, FieldErr("share_url", "resolver.share_url_required", nil)
	}
	u, err := url.Parse(raw)
	scheme := ""
	if err == nil {
		scheme = strings.ToLower(u.Scheme)
	} else {
		if i := strings.Index(raw, "://"); i > 0 {
			scheme = strings.ToLower(raw[:i])
		}
	}
	switch scheme {
	case "vless":
		return p.parseVless(raw)
	case "ss":
		return p.parseShadowsocks(raw)
	case "trojan":
		return p.parseTrojan(raw)
	case "hysteria2", "hy2":
		return p.parseHysteria2(raw)
	case "socks", "socks5":
		return p.parseSocks(raw)
	default:
		return nil, FieldErr("share_url", "resolver.unsupported_scheme", map[string]string{"scheme": scheme})
	}
}

func (p OutboundParser) parseVless(raw string) (map[string]any, error) {
	u, err := url.Parse(raw)
	if err != nil {
		return nil, FieldErr("share_url", "resolver.invalid_vless_url", nil)
	}
	uuid, _ := url.QueryUnescape(u.User.Username())
	host := u.Hostname()
	port, _ := strconv.Atoi(u.Port())
	if port == 0 {
		port = 443
	}
	if uuid == "" || host == "" {
		return nil, FieldErr("share_url", "resolver.invalid_vless_url", nil)
	}
	q := u.Query()
	security := strings.ToLower(first(q.Get("security"), "none"))
	network := strings.ToLower(first(q.Get("type"), "tcp"))
	sni := first(q.Get("sni"), q.Get("host"), host)
	fp := first(q.Get("fp"), "chrome")
	if fp == "" {
		fp = "chrome"
	}
	var alpn []string
	if a := q.Get("alpn"); a != "" {
		for _, p := range strings.Split(a, ",") {
			p = strings.TrimSpace(p)
			if p != "" {
				alpn = append(alpn, p)
			}
		}
	}
	flow := q.Get("flow")
	path, _ := url.QueryUnescape(first(q.Get("path"), "/"))
	hostHeader := q.Get("host")
	serviceName := first(q.Get("serviceName"), q.Get("service_name"))
	pbk := q.Get("pbk")
	sid := q.Get("sid")

	ob := map[string]any{
		"type":            "vless",
		"server":          host,
		"server_port":     port,
		"uuid":            uuid,
		"packet_encoding": "xudp",
	}
	if flow != "" {
		ob["flow"] = flow
	} else if network == "udp" {
		ob["network"] = "udp"
	}
	if security == "reality" {
		if pbk == "" {
			return nil, FieldErr("share_url", "resolver.reality_pbk_missing", nil)
		}
		ob["tls"] = map[string]any{
			"enabled":     true,
			"server_name": sni,
			"utls":        map[string]any{"enabled": true, "fingerprint": fp},
			"reality":     map[string]any{"enabled": true, "public_key": pbk, "short_id": sid},
		}
	} else if security == "tls" {
		tls := map[string]any{
			"enabled":     true,
			"server_name": sni,
			"utls":        map[string]any{"enabled": true, "fingerprint": fp},
		}
		if len(alpn) > 0 {
			tls["alpn"] = alpn
		}
		ob["tls"] = tls
	}
	switch network {
	case "ws":
		tr := map[string]any{"type": "ws", "path": first(path, "/")}
		if hostHeader != "" {
			tr["headers"] = map[string]any{"Host": hostHeader}
		}
		ob["transport"] = tr
	case "grpc":
		ob["transport"] = map[string]any{"type": "grpc", "service_name": first(serviceName, "GunService")}
	case "httpupgrade":
		tr := map[string]any{"type": "httpupgrade", "path": first(path, "/")}
		if hostHeader != "" {
			tr["host"] = hostHeader
		}
		ob["transport"] = tr
	case "http", "h2":
		tr := map[string]any{"type": "http", "path": first(path, "/")}
		if hostHeader != "" {
			tr["host"] = []string{hostHeader}
		}
		ob["transport"] = tr
	}
	return ob, nil
}

func (p OutboundParser) parseShadowsocks(raw string) (map[string]any, error) {
	raw = strings.TrimPrefix(raw, "ss://")
	if i := strings.Index(raw, "#"); i >= 0 {
		raw = raw[:i]
	}
	var method, password, host string
	var port int
	if strings.Contains(raw, "@") {
		userinfo, hostport, _ := strings.Cut(raw, "@")
		if !strings.Contains(userinfo, ":") {
			decoded, err := b64(userinfo)
			if err != nil || !strings.Contains(decoded, ":") {
				return nil, FieldErr("share_url", "resolver.invalid_ss_url", nil)
			}
			method, password, _ = strings.Cut(decoded, ":")
		} else {
			method, password, _ = strings.Cut(userinfo, ":")
		}
		if strings.Contains(hostport, ":") {
			h, ps, _ := strings.Cut(hostport, ":")
			host = h
			port, _ = strconv.Atoi(ps)
		}
	} else {
		decoded, err := b64(raw)
		if err != nil {
			return nil, FieldErr("share_url", "resolver.invalid_ss_url", nil)
		}
		// method:password@host:port
		at := strings.LastIndex(decoded, "@")
		if at < 0 {
			return nil, FieldErr("share_url", "resolver.invalid_ss_url", nil)
		}
		user := decoded[:at]
		hostport := decoded[at+1:]
		method, password, _ = strings.Cut(user, ":")
		h, ps, ok := strings.Cut(hostport, ":")
		if !ok {
			return nil, FieldErr("share_url", "resolver.invalid_ss_url", nil)
		}
		host = h
		port, _ = strconv.Atoi(ps)
	}
	if method == "" || password == "" || host == "" || port < 1 {
		return nil, FieldErr("share_url", "resolver.invalid_ss_url", nil)
	}
	return map[string]any{
		"type":        "shadowsocks",
		"server":      host,
		"server_port": port,
		"method":      method,
		"password":    password,
	}, nil
}

func (p OutboundParser) parseTrojan(raw string) (map[string]any, error) {
	u, err := url.Parse(raw)
	if err != nil {
		return nil, FieldErr("share_url", "resolver.invalid_trojan_url", nil)
	}
	password := u.User.Username()
	host := u.Hostname()
	port, _ := strconv.Atoi(u.Port())
	if port == 0 {
		port = 443
	}
	if password == "" || host == "" {
		return nil, FieldErr("share_url", "resolver.invalid_trojan_url", nil)
	}
	q := u.Query()
	sni := first(q.Get("sni"), host)
	allow := strings.ToLower(q.Get("allowInsecure"))
	return map[string]any{
		"type":        "trojan",
		"server":      host,
		"server_port": port,
		"password":    unescape(password),
		"tls": map[string]any{
			"enabled":     true,
			"server_name": sni,
			"insecure":    allow == "1" || allow == "true",
		},
	}, nil
}

func (p OutboundParser) parseHysteria2(raw string) (map[string]any, error) {
	u, err := url.Parse(raw)
	if err != nil {
		return nil, FieldErr("share_url", "resolver.invalid_hysteria2_url", nil)
	}
	password := ""
	if u.User != nil {
		password, _ = url.QueryUnescape(u.User.Username())
	}
	host := u.Hostname()
	port, _ := strconv.Atoi(u.Port())
	if port == 0 {
		port = 443
	}
	if password == "" || host == "" {
		return nil, FieldErr("share_url", "resolver.invalid_hysteria2_url", nil)
	}
	q := u.Query()
	sni := first(q.Get("sni"), host)
	insec := strings.ToLower(q.Get("insecure"))
	ob := map[string]any{
		"type":        "hysteria2",
		"server":      host,
		"server_port": port,
		"password":    password,
		"tls": map[string]any{
			"enabled":     true,
			"server_name": sni,
			"insecure":    insec == "1" || insec == "true",
		},
	}
	if q.Get("obfs") != "" {
		ob["obfs"] = map[string]any{
			"type":     q.Get("obfs"),
			"password": first(q.Get("obfs-password"), q.Get("obfs_password")),
		}
	}
	return ob, nil
}

func (p OutboundParser) parseSocks(raw string) (map[string]any, error) {
	u, err := url.Parse(raw)
	if err != nil {
		return nil, FieldErr("share_url", "resolver.invalid_socks_url", nil)
	}
	host := u.Hostname()
	port, _ := strconv.Atoi(u.Port())
	if port == 0 {
		port = 1080
	}
	if host == "" || port < 1 {
		return nil, FieldErr("share_url", "resolver.invalid_socks_url", nil)
	}
	ob := map[string]any{"type": "socks", "server": host, "server_port": port}
	if u.User != nil {
		if u.User.Username() != "" {
			ob["username"], _ = url.QueryUnescape(u.User.Username())
		}
		if pw, ok := u.User.Password(); ok {
			ob["password"], _ = url.QueryUnescape(pw)
		}
	}
	return ob, nil
}

func first(vals ...string) string {
	for _, v := range vals {
		if v != "" {
			return v
		}
	}
	return ""
}

func truthy(v any) bool {
	switch t := v.(type) {
	case bool:
		return t
	case string:
		return t == "1" || strings.EqualFold(t, "true")
	case float64:
		return t != 0
	case int:
		return t != 0
	default:
		return false
	}
}

func b64(s string) (string, error) {
	s = strings.ReplaceAll(strings.ReplaceAll(s, "-", "+"), "_", "/")
	for len(s)%4 != 0 {
		s += "="
	}
	b, err := base64.StdEncoding.DecodeString(s)
	if err != nil {
		return "", err
	}
	return string(b), nil
}

func unescape(s string) string {
	out, err := url.QueryUnescape(s)
	if err != nil {
		return s
	}
	return out
}

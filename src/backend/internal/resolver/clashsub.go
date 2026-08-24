package resolver

import (
	"encoding/json"
	"strings"

	"gopkg.in/yaml.v3"
)

type ClashSubParser struct {
	Parser OutboundParser
}

func (p ClashSubParser) Parse(body string) []map[string]any {
	if !strings.Contains(body, "proxies:") {
		return nil
	}
	var data map[string]any
	if err := yaml.Unmarshal([]byte(body), &data); err != nil {
		return nil
	}
	proxies, _ := data["proxies"].([]any)
	if proxies == nil {
		return nil
	}
	var nodes []map[string]any
	seen := map[string]bool{}
	for _, raw := range proxies {
		proxy, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		obIn, err := p.outboundFromClash(proxy)
		if err != nil {
			continue
		}
		ob, err := p.Parser.Normalize(obIn)
		if err != nil || strVal(ob["type"]) == "" || strVal(ob["server"]) == "" {
			continue
		}
		name := strings.TrimSpace(strVal(proxy["name"]))
		if name == "" {
			name = strVal(ob["server"])
		}
		uri, _ := json.Marshal(proxy)
		key := sha16(string(uri))
		if seen[key] {
			continue
		}
		seen[key] = true
		if len(name) > 120 {
			name = name[:120]
		}
		nodes = append(nodes, map[string]any{
			"key":      key,
			"name":     name,
			"type":     strVal(ob["type"]),
			"server":   strVal(ob["server"]),
			"port":     atoiDef(strVal(ob["server_port"]), 0),
			"outbound": ob,
		})
	}
	if len(nodes) == 0 {
		return nil
	}
	return nodes
}

func (p ClashSubParser) outboundFromClash(pr map[string]any) (map[string]any, error) {
	typ := strings.ToLower(strVal(pr["type"]))
	server := strVal(pr["server"])
	port := atoiDef(strVal(pr["port"]), 443)
	if server == "" {
		return nil, FieldErr("subscription_url", "resolver.subscription_parse_failed", nil)
	}
	switch typ {
	case "vless":
		return p.vlessFromClash(pr, server, port), nil
	case "vmess":
		return p.vmessFromClash(pr, server, port), nil
	case "trojan":
		ob := map[string]any{
			"type": "trojan", "server": server, "server_port": port,
			"password": strVal(pr["password"]),
		}
		if tls := p.clashTLS(pr); tls != nil {
			ob["tls"] = tls["tls"]
		}
		return ob, nil
	case "ss":
		return map[string]any{
			"type": "shadowsocks", "server": server, "server_port": port,
			"method": first(strVal(pr["cipher"]), "aes-256-gcm"), "password": strVal(pr["password"]),
		}, nil
	case "socks5", "socks":
		return map[string]any{
			"type": "socks", "server": server, "server_port": port,
			"username": strVal(pr["username"]), "password": strVal(pr["password"]),
		}, nil
	case "hysteria", "hysteria2", "hy2":
		return map[string]any{
			"type": "hysteria2", "server": server, "server_port": port,
			"password": first(strVal(pr["password"]), strVal(pr["auth"])),
		}, nil
	default:
		return nil, FieldErr("subscription_url", "resolver.subscription_parse_failed", nil)
	}
}

func (p ClashSubParser) vmessFromClash(pr map[string]any, server string, port int) map[string]any {
	ob := map[string]any{
		"type": "vmess", "server": server, "server_port": port,
		"uuid": strVal(pr["uuid"]), "security": first(strVal(pr["cipher"]), "auto"),
		"alter_id": atoiDef(first(strVal(pr["alterId"]), strVal(pr["alter_id"])), 0),
	}
	if tls := p.clashTLS(pr); tls != nil {
		ob["tls"] = tls["tls"]
	}
	if tr := p.clashTransport(pr, strings.ToLower(first(strVal(pr["network"]), "tcp"))); tr != nil {
		ob["transport"] = tr
	}
	return ob
}

func (p ClashSubParser) vlessFromClash(pr map[string]any, server string, port int) map[string]any {
	ob := map[string]any{
		"type": "vless", "server": server, "server_port": port,
		"uuid": strVal(pr["uuid"]), "packet_encoding": "xudp",
	}
	if flow := strVal(pr["flow"]); flow != "" {
		ob["flow"] = flow
	}
	if tls := p.clashTLS(pr); tls != nil {
		ob["tls"] = tls["tls"]
	}
	if tr := p.clashTransport(pr, strings.ToLower(first(strVal(pr["network"]), "tcp"))); tr != nil {
		ob["transport"] = tr
	}
	return ob
}

func (p ClashSubParser) clashTLS(pr map[string]any) map[string]any {
	sni := first(strVal(pr["servername"]), strVal(pr["sni"]), strVal(pr["server"]))
	fp := first(strVal(pr["client-fingerprint"]), strVal(pr["fp"]), "chrome")
	var reality map[string]any
	if r, ok := pr["reality-opts"].(map[string]any); ok {
		reality = r
	} else if r, ok := pr["reality_opts"].(map[string]any); ok {
		reality = r
	}
	if reality != nil && strVal(reality["public-key"]) != "" {
		return map[string]any{"tls": map[string]any{
			"enabled": true, "server_name": sni,
			"utls":    map[string]any{"enabled": true, "fingerprint": first(fp, "chrome")},
			"reality": map[string]any{
				"enabled": true, "public_key": strVal(reality["public-key"]),
				"short_id": first(strVal(reality["short-id"]), strVal(reality["short_id"])),
			},
		}}
	}
	if truthy(pr["tls"]) {
		return map[string]any{"tls": map[string]any{
			"enabled": true, "server_name": sni,
			"utls": map[string]any{"enabled": true, "fingerprint": first(fp, "chrome")},
		}}
	}
	return nil
}

func (p ClashSubParser) clashTransport(pr map[string]any, network string) map[string]any {
	switch network {
	case "ws":
		ws, _ := pr["ws-opts"].(map[string]any)
		if ws == nil {
			ws = map[string]any{}
		}
		ob := map[string]any{"type": "ws", "path": first(strVal(ws["path"]), "/")}
		if h, ok := ws["headers"].(map[string]any); ok && len(h) > 0 {
			ob["headers"] = h
		}
		return ob
	case "grpc":
		grpc, _ := pr["grpc-opts"].(map[string]any)
		if grpc == nil {
			grpc = map[string]any{}
		}
		return map[string]any{"type": "grpc", "service_name": first(strVal(grpc["grpc-service-name"]), "GunService")}
	case "http", "h2":
		return map[string]any{"type": "http", "path": first(strVal(pr["path"]), "/")}
	}
	return nil
}

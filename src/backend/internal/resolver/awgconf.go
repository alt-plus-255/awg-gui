package resolver

import (
	"fmt"
	"strings"
)

var junkKeys = []string{
	"jc", "jmin", "jmax", "s1", "s2", "s3", "s4",
	"h1", "h2", "h3", "h4", "i1", "i2", "i3", "i4", "i5",
}

type ParsedAWG struct {
	PrivateKey string
	Address    string
	DNS        *string
	MTU        *string
	Junk       map[string]string
	Peer       AWGPeer
}

type AWGPeer struct {
	PublicKey           string
	Endpoint            string
	AllowedIPs          string
	PresharedKey        *string
	PersistentKeepalive *string
}

type AWGConfParser struct{}

func (p AWGConfParser) Parse(raw string) (ParsedAWG, error) {
	raw = strings.TrimSpace(strings.ReplaceAll(raw, "\r\n", "\n"))
	if raw == "" {
		return ParsedAWG{}, FieldErr("awg_conf", "resolver.awg_conf_required", nil)
	}
	iface, peers := parseINISections(raw)
	if len(iface) == 0 {
		return ParsedAWG{}, FieldErr("awg_conf", "resolver.awg_conf_missing_interface", nil)
	}
	if len(peers) == 0 {
		return ParsedAWG{}, FieldErr("awg_conf", "resolver.awg_conf_missing_peer", nil)
	}
	pk, err := requireKey(iface, "PrivateKey")
	if err != nil {
		return ParsedAWG{}, err
	}
	addr, err := requireKey(iface, "Address")
	if err != nil {
		return ParsedAWG{}, err
	}
	peer := peers[0]
	pub, err := requireKey(peer, "PublicKey")
	if err != nil {
		return ParsedAWG{}, err
	}
	ep, err := requireKey(peer, "Endpoint")
	if err != nil {
		return ParsedAWG{}, err
	}
	allowed := strings.TrimSpace(peer["AllowedIPs"])
	if allowed == "" {
		allowed = "0.0.0.0/0, ::/0"
	}
	out := ParsedAWG{
		PrivateKey: pk,
		Address:    addr,
		DNS:        optionalKey(iface, "DNS"),
		MTU:        optionalKey(iface, "MTU"),
		Junk:       map[string]string{},
		Peer: AWGPeer{
			PublicKey:           pub,
			Endpoint:            ep,
			AllowedIPs:          allowed,
			PresharedKey:        optionalKey(peer, "PresharedKey"),
			PersistentKeepalive: optionalKey(peer, "PersistentKeepalive"),
		},
	}
	for _, key := range junkKeys {
		confKey := junkConfKey(key)
		if v, ok := iface[confKey]; ok {
			out.Junk[key] = strings.TrimSpace(v)
			continue
		}
		for ik, iv := range iface {
			if strings.EqualFold(ik, confKey) {
				out.Junk[key] = strings.TrimSpace(iv)
				break
			}
		}
	}
	return out, nil
}

func junkConfKey(field string) string {
	switch field {
	case "jc":
		return "Jc"
	case "jmin":
		return "Jmin"
	case "jmax":
		return "Jmax"
	default:
		return strings.ToUpper(field)
	}
}

func parseINISections(raw string) (map[string]string, []map[string]string) {
	var iface map[string]string
	var peers []map[string]string
	var current string
	bucket := map[string]string{}
	flush := func() {
		if current == "" {
			return
		}
		if current == "interface" {
			iface = bucket
		} else if current == "peer" {
			peers = append(peers, bucket)
		}
		bucket = map[string]string{}
		current = ""
	}
	for _, line := range strings.Split(raw, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
			flush()
			name := strings.ToLower(strings.TrimSpace(line[1 : len(line)-1]))
			switch name {
			case "interface":
				current = "interface"
			case "peer":
				current = "peer"
			default:
				current = "other"
			}
			bucket = map[string]string{}
			continue
		}
		if current == "" || current == "other" {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		if k == "" {
			continue
		}
		bucket[k] = strings.TrimSpace(v)
	}
	flush()
	if iface == nil {
		iface = map[string]string{}
	}
	return iface, peers
}

func requireKey(section map[string]string, key string) (string, error) {
	v := strings.TrimSpace(section[key])
	if v == "" {
		return "", FieldErr("awg_conf", "resolver.awg_conf_missing_field", map[string]string{"field": key})
	}
	return v, nil
}

func optionalKey(section map[string]string, key string) *string {
	v := strings.TrimSpace(section[key])
	if v == "" {
		return nil
	}
	return &v
}

type AWGClientConfBuilder struct{}

func (AWGClientConfBuilder) IfaceName(id int64) string { return fmt.Sprintf("awgc%d", id) }

func (b AWGClientConfBuilder) OutboundFor(id int64) map[string]any {
	return map[string]any{"type": "direct", "bind_interface": b.IfaceName(id)}
}

func (b AWGClientConfBuilder) Build(parsed ParsedAWG, version string) string {
	junk := normalizeJunk(parsed.Junk, version)
	lines := []string{
		"[Interface]",
		"PrivateKey = " + parsed.PrivateKey,
		"Address = " + parsed.Address,
		"Table = off",
	}
	if parsed.DNS != nil && *parsed.DNS != "" {
		lines = append(lines, "DNS = "+*parsed.DNS)
	}
	if parsed.MTU != nil && *parsed.MTU != "" {
		lines = append(lines, "MTU = "+*parsed.MTU)
	}
	lines = append(lines, obfuscationLines(junk, version)...)
	lines = append(lines, "", "[Peer]", "PublicKey = "+parsed.Peer.PublicKey)
	if parsed.Peer.PresharedKey != nil && *parsed.Peer.PresharedKey != "" {
		lines = append(lines, "PresharedKey = "+*parsed.Peer.PresharedKey)
	}
	lines = append(lines, "Endpoint = "+parsed.Peer.Endpoint)
	allowed := parsed.Peer.AllowedIPs
	if allowed == "" {
		allowed = "0.0.0.0/0, ::/0"
	}
	lines = append(lines, "AllowedIPs = "+allowed)
	if parsed.Peer.PersistentKeepalive != nil && *parsed.Peer.PersistentKeepalive != "" {
		lines = append(lines, "PersistentKeepalive = "+*parsed.Peer.PersistentKeepalive)
	}
	return strings.Join(lines, "\n") + "\n"
}

func supportedJunk(version string) []string {
	base := []string{"jc", "jmin", "jmax", "s1", "s2", "h1", "h2", "h3", "h4"}
	iParams := []string{"i1", "i2", "i3", "i4", "i5"}
	s34 := []string{"s3", "s4"}
	switch version {
	case "1.0":
		return base
	case "1.5":
		return append(append([]string{}, base...), iParams...)
	case "2.0", "3.1":
		return append(append(append([]string{}, base...), s34...), iParams...)
	default:
		// Unknown / empty → latest (3.1) param set
		return append(append(append([]string{}, base...), s34...), iParams...)
	}
}

func normalizeJunk(in map[string]string, version string) map[string]string {
	sup := map[string]bool{}
	for _, k := range supportedJunk(version) {
		sup[k] = true
	}
	out := map[string]string{}
	for k, v := range in {
		if !sup[k] {
			continue
		}
		out[k] = v
	}
	iParams := map[string]bool{"i1": true, "i2": true, "i3": true, "i4": true, "i5": true}
	for _, k := range junkKeys {
		if sup[k] {
			continue
		}
		if iParams[k] {
			out[k] = ""
		} else {
			out[k] = "0"
		}
	}
	return out
}

func obfuscationLines(params map[string]string, version string) []string {
	sup := map[string]bool{}
	for _, k := range supportedJunk(version) {
		sup[k] = true
	}
	var lines []string
	order := []struct{ field, conf string }{
		{"jc", "Jc"}, {"jmin", "Jmin"}, {"jmax", "Jmax"},
		{"s1", "S1"}, {"s2", "S2"}, {"s3", "S3"}, {"s4", "S4"},
		{"h1", "H1"}, {"h2", "H2"}, {"h3", "H3"}, {"h4", "H4"},
	}
	for _, it := range order {
		if !sup[it.field] {
			continue
		}
		lines = append(lines, it.conf+" = "+params[it.field])
	}
	for _, ik := range []string{"i1", "i2", "i3", "i4", "i5"} {
		if !sup[ik] {
			continue
		}
		v := strings.TrimSpace(params[ik])
		if v != "" {
			lines = append(lines, strings.ToUpper(ik)+" = "+v)
		}
	}
	return lines
}

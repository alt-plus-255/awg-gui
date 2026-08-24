package resolver

import (
	"crypto/rand"
	"crypto/tls"
	"encoding/binary"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

type Tspu struct{}

func (Tspu) Probe(locale string, outbound map[string]any, proxyOK bool) map[string]any {
	server := strVal(outbound["server"])
	port := atoiDef(strVal(outbound["server_port"]), 0)
	sni := strVal(mapGet(outbound, "tls", "server_name"))
	if sni == "" {
		sni = server
	}
	reality := false
	if v, ok := mapGet(outbound, "tls", "reality", "enabled").(bool); ok && v {
		reality = true
	}
	if strVal(mapGet(outbound, "tls", "reality", "public_key")) != "" {
		reality = true
	}

	if server == "" || port < 1 {
		return tspuPack(locale, "skipped", false, i18n.T(locale, "resolver.tspu_no_server_port"),
			false, false, false, false, ptrStr("config"), nil, nil, nil, nil)
	}
	if proxyOK {
		sniOut := sni
		if sniOut == "" {
			sniOut = server
		}
		return tspuPack(locale, "ok", false, i18n.T(locale, "resolver.tspu_proxy_ok"),
			true, true, true, true, nil, &server, nil, &port, &sniOut)
	}

	controlOK := controlInternetOK()
	ip := resolveIPv4(server)
	if ip == "" && isIP(server) {
		ip = server
	}
	sniOut := sni
	if sniOut == "" {
		sniOut = server
	}
	if ip == "" {
		return tspuPack(locale, "uncertain", false,
			i18n.Tf(locale, "resolver.tspu_resolve_failed", map[string]string{"server": server}),
			controlOK, false, false, false, ptrStr("dns"), &server, nil, &port, &sniOut)
	}

	tcpOK := tcpConnect(ip, port, 3*time.Second)
	if !tcpOK {
		detail := i18n.Tf(locale, "resolver.tspu_tcp_fail_host", map[string]string{
			"server": server, "ip": ip, "port": itoa(port),
		})
		if !controlOK {
			detail = i18n.Tf(locale, "resolver.tspu_tcp_fail_egress", map[string]string{
				"server": server, "ip": ip, "port": itoa(port),
			})
		}
		return tspuPack(locale, "tcp_fail", false, detail, controlOK, false, false, false, ptrStr("tcp"), &server, &ip, &port, &sniOut)
	}

	tlsResp := tlsClientHelloProbe(ip, port, sniOut, 4*time.Second)
	if tlsResp {
		detail := i18n.Tf(locale, "resolver.tspu_proxy_fail", map[string]string{"ip": ip, "port": itoa(port)})
		if reality {
			detail = i18n.Tf(locale, "resolver.tspu_vless_fail", map[string]string{"ip": ip, "port": itoa(port)})
		}
		return tspuPack(locale, "tls_ok_proxy_fail", false, detail, controlOK, true, true, false, ptrStr("proxy"), &server, &ip, &port, &sniOut)
	}
	if controlOK {
		detail := i18n.Tf(locale, "resolver.tspu_tls_dpi_generic", map[string]string{"ip": ip, "port": itoa(port)})
		if reality {
			detail = i18n.Tf(locale, "resolver.tspu_tls_dpi", map[string]string{"ip": ip, "port": itoa(port)})
		}
		return tspuPack(locale, "tspu_likely", true, detail, true, true, false, false, ptrStr("tls"), &server, &ip, &port, &sniOut)
	}
	return tspuPack(locale, "uncertain", false,
		i18n.Tf(locale, "resolver.tspu_tls_silent_egress_bad", map[string]string{"ip": ip, "port": itoa(port)}),
		false, true, false, false, ptrStr("tls"), &server, &ip, &port, &sniOut)
}

func tspuPack(locale, status string, likely bool, detail string, controlOK, tcpOK, tlsResp, proxyOK bool, blockStep, server, ip *string, port *int, sni *string) map[string]any {
	endpoint := "?"
	if ip != nil && *ip != "" {
		endpoint = *ip
	} else if server != nil && *server != "" {
		endpoint = *server
	}
	if port != nil && *port > 0 {
		endpoint += ":" + itoa(*port)
	}

	controlNote := i18n.T(locale, "resolver.tspu_control_fail")
	if controlOK {
		controlNote = i18n.T(locale, "resolver.tspu_control_ok")
	}
	var controlOKAny any
	if status != "skipped" {
		controlOKAny = controlOK
	}

	dnsOK := any(nil)
	dnsNote := i18n.T(locale, "resolver.tspu_resolve_failed_short")
	if status != "skipped" {
		dnsOK = ip != nil && *ip != ""
	}
	if ip != nil && *ip != "" {
		if server != nil && *server != "" && *server != *ip {
			dnsNote = *server + " → " + *ip
		} else {
			dnsNote = *ip
		}
	}

	var tcpOKAny any
	if status != "skipped" && ip != nil && *ip != "" {
		tcpOKAny = tcpOK
	}
	tcpNote := i18n.Tf(locale, "resolver.tspu_no_tcp", map[string]string{"endpoint": endpoint})
	if tcpOK {
		tcpNote = "SYN/ACK " + endpoint
	}

	var tlsOKAny any
	if tcpOK && status != "skipped" {
		tlsOKAny = tlsResp
	}
	tlsNote := i18n.T(locale, "resolver.tspu_not_checked")
	if tlsResp {
		tlsNote = i18n.T(locale, "resolver.tspu_clienthello_ok")
	} else if tcpOK {
		if likely {
			tlsNote = i18n.T(locale, "resolver.tspu_clienthello_no_reply_tspu")
		} else {
			tlsNote = i18n.T(locale, "resolver.tspu_clienthello_no_reply")
		}
	}

	var proxyOKAny any
	if status == "ok" {
		proxyOKAny = true
	} else if (tcpOK && tlsResp) || status == "tls_ok_proxy_fail" {
		proxyOKAny = proxyOK
	}
	proxyNote := i18n.T(locale, "resolver.tspu_not_checked_fail")
	if proxyOK {
		proxyNote = "delay OK"
	} else if tlsResp {
		proxyNote = i18n.T(locale, "resolver.tspu_proxy_no_reply")
	} else if likely {
		proxyNote = i18n.T(locale, "resolver.tspu_did_not_reach_tls")
	}

	var block any
	if blockStep != nil {
		block = *blockStep
	}
	return map[string]any{
		"status": status, "tspu_likely": likely, "detail": detail,
		"control_ok": controlOK, "tcp_ok": tcpOK, "tls_response": tlsResp, "proxy_ok": proxyOK,
		"block_step": block,
		"chain": []map[string]any{
			{"id": "control", "label": i18n.T(locale, "resolver.tspu_label_internet"), "ok": controlOKAny, "note": controlNote},
			{"id": "dns", "label": "DNS", "ok": dnsOK, "note": dnsNote},
			{"id": "tcp", "label": "TCP", "ok": tcpOKAny, "note": tcpNote},
			{"id": "tls", "label": "TLS", "ok": tlsOKAny, "note": tlsNote},
			{"id": "proxy", "label": "VLESS", "ok": proxyOKAny, "note": proxyNote},
		},
		"server": server, "ip": ip, "port": port, "sni": sni,
	}
}

func controlInternetOK() bool {
	cli := &http.Client{
		Timeout: 3 * time.Second,
		Transport: &http.Transport{
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true}, //nolint:gosec
		},
	}
	resp, err := cli.Get("https://1.1.1.1/cdn-cgi/trace")
	if err != nil {
		return false
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	return strings.Contains(string(b), "ip=")
}

func tlsClientHelloProbe(ip string, port int, sni string, timeout time.Duration) bool {
	addr := net.JoinHostPort(ip, strconv.Itoa(port))
	c, err := net.DialTimeout("tcp", addr, timeout)
	if err != nil {
		return false
	}
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(timeout))
	hello := buildClientHello(sni)
	if _, err := c.Write(hello); err != nil {
		return false
	}
	buf := make([]byte, 2048)
	n, err := c.Read(buf)
	return err == nil && n > 0
}

func buildClientHello(sni string) []byte {
	if sni == "" {
		sni = "example.com"
	}
	random := make([]byte, 32)
	_, _ = rand.Read(random)
	cipherSuites, _ := parseHex("0004130113021303c02bc02fc02cc030cca9cca8c013c014009c009d002f0035")

	sniHost := []byte(sni)
	sniListEntry := append([]byte{0x00}, u16(len(sniHost))...)
	sniListEntry = append(sniListEntry, sniHost...)
	sniList := append(u16(len(sniListEntry)), sniListEntry...)
	sniExt := append([]byte{0x00, 0x00}, u16(len(sniList))...)
	sniExt = append(sniExt, sniList...)

	ecPoint := []byte{0x00, 0x0b, 0x00, 0x02, 0x01, 0x00}
	supportedGroups := []byte{0x00, 0x0a, 0x00, 0x08, 0x00, 0x06, 0x00, 0x1d, 0x00, 0x17, 0x00, 0x18}
	signatureAlgs := []byte{0x00, 0x0d, 0x00, 0x12, 0x00, 0x10, 0x04, 0x03, 0x08, 0x04, 0x04, 0x01, 0x05, 0x03, 0x08, 0x05, 0x05, 0x01, 0x08, 0x06, 0x06, 0x01}
	supportedVersions := []byte{0x00, 0x2b, 0x00, 0x03, 0x02, 0x03, 0x03}
	extensions := concat(sniExt, ecPoint, supportedGroups, signatureAlgs, supportedVersions)

	body := concat([]byte{0x03, 0x03}, random, []byte{0x00}, u16(len(cipherSuites)), cipherSuites, []byte{0x01, 0x00}, u16(len(extensions)), extensions)
	hsLen := make([]byte, 4)
	binary.BigEndian.PutUint32(hsLen, uint32(len(body)))
	handshake := concat([]byte{0x01}, hsLen[1:], body)
	return concat([]byte{0x16, 0x03, 0x01}, u16(len(handshake)), handshake)
}

func u16(n int) []byte { return []byte{byte(n >> 8), byte(n)} }

func concat(parts ...[]byte) []byte {
	var out []byte
	for _, p := range parts {
		out = append(out, p...)
	}
	return out
}

func parseHex(s string) ([]byte, error) {
	b := make([]byte, len(s)/2)
	for i := 0; i < len(b); i++ {
		v, err := strconv.ParseUint(s[i*2:i*2+2], 16, 8)
		if err != nil {
			return nil, err
		}
		b[i] = byte(v)
	}
	return b, nil
}

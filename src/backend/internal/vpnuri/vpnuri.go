package vpnuri

import (
	"bytes"
	"compress/zlib"
	"context"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"strconv"
	"strings"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/models"
	qrcodesvc "github.com/awggui/backend/internal/qrcode"
)

const amneziaQRMagic uint32 = 0x07C00100

var (
	ErrInvalidPack = errors.New("invalid amnezia payload")
	ErrInvalidURI  = errors.New("invalid vpn:// URI")
)

type Service struct {
	AWG *awg.Service
	QR  *qrcodesvc.Service
}

func New(a *awg.Service, qr *qrcodesvc.Service) *Service {
	return &Service{AWG: a, QR: qr}
}

func (s *Service) BuildAmneziaQRPackFromMembership(ctx context.Context, membership *models.AwgConfigPeer) (string, error) {
	js, err := s.EncodeOuterJSON(ctx, membership)
	if err != nil {
		return "", err
	}
	return s.BuildAmneziaQRPackFromOuterJSON(js)
}

func (s *Service) BuildAmneziaQRPackFromOuterJSON(js string) (string, error) {
	compressed, err := zlibCompress([]byte(js))
	if err != nil {
		return "", err
	}
	header := make([]byte, 12)
	binary.BigEndian.PutUint32(header[0:4], amneziaQRMagic)
	binary.BigEndian.PutUint32(header[4:8], uint32(len(compressed)+4))
	binary.BigEndian.PutUint32(header[8:12], uint32(len(js)))
	packed := append(header, compressed...)
	return base64.RawURLEncoding.EncodeToString(packed), nil
}

func (s *Service) BuildFromMembership(ctx context.Context, membership *models.AwgConfigPeer) (string, error) {
	js, err := s.EncodeOuterJSON(ctx, membership)
	if err != nil {
		return "", err
	}
	compressed, err := zlibCompress([]byte(js))
	if err != nil {
		return "", err
	}
	payload := make([]byte, 4+len(compressed))
	binary.BigEndian.PutUint32(payload[0:4], uint32(len(js)))
	copy(payload[4:], compressed)
	return "vpn://" + base64.RawURLEncoding.EncodeToString(payload), nil
}

func (s *Service) EncodeOuterJSON(ctx context.Context, membership *models.AwgConfigPeer) (string, error) {
	outer, err := s.BuildOuterFromMembership(ctx, membership)
	if err != nil {
		return "", err
	}
	return jsonNoEscape(outer)
}

func (s *Service) BuildOuterFromMembership(ctx context.Context, membership *models.AwgConfigPeer) (map[string]any, error) {
	cfg := membership.Config
	if cfg == nil {
		return nil, awg.ErrNoConfig
	}
	profile := s.AWG.Versions.ProfileForConfig(cfg.ProtocolVersion)
	conf, err := s.AWG.BuildClientConfig(ctx, membership)
	if err != nil {
		return nil, err
	}
	conf = strings.TrimRight(s.QR.NormalizeConfigText(conf), "\n")

	privateKey := matchConf(conf, "PrivateKey")
	address := matchConf(conf, "Address")
	allowedRaw := matchConf(conf, "AllowedIPs")
	if allowedRaw == "" {
		allowedRaw = "0.0.0.0/0"
	}
	endpoint := matchConf(conf, "Endpoint")
	keepalive := matchConf(conf, "PersistentKeepalive")
	if keepalive == "" {
		if membership.Keepalive != nil {
			keepalive = strconv.Itoa(*membership.Keepalive)
		} else if cfg.PersistentKeepalive > 0 {
			keepalive = strconv.Itoa(cfg.PersistentKeepalive)
		} else {
			keepalive = "25"
		}
	}
	psk := matchConf(conf, "PresharedKey")
	hostName := parseEndpointHost(endpoint)
	var allowedIPs []string
	for _, p := range strings.Split(allowedRaw, ",") {
		if t := strings.TrimSpace(p); t != "" {
			allowedIPs = append(allowedIPs, t)
		}
	}
	dnsRaw := matchConf(conf, "DNS")
	if dnsRaw == "" {
		dnsRaw = "1.1.1.1"
	}
	var dnsParts []string
	for _, p := range strings.Split(dnsRaw, ",") {
		if t := strings.TrimSpace(p); t != "" {
			dnsParts = append(dnsParts, t)
		}
	}
	dns1 := "1.1.1.1"
	if len(dnsParts) > 0 {
		dns1 = dnsParts[0]
	}
	dns2 := dns1
	if len(dnsParts) > 1 {
		dns2 = dnsParts[1]
	}

	inner := map[string]any{}
	for k, v := range profile.VpnURIInnerParams(cfg) {
		inner[k] = v
	}
	inner["allowed_ips"] = allowedIPs
	inner["client_ip"] = address
	inner["client_priv_key"] = privateKey
	inner["config"] = conf
	inner["hostName"] = hostName
	inner["mtu"] = "1420"
	inner["persistent_keep_alive"] = keepalive
	inner["port"] = cfg.ListenPort
	inner["server_pub_key"] = cfg.ServerPublicKey
	if psk != "" {
		inner["psk_key"] = psk
	}

	innerJSON, err := jsonNoEscape(inner)
	if err != nil {
		return nil, err
	}
	description, err := s.AWG.ClientImportLabel(ctx, membership, "", "")
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"containers": []any{
			map[string]any{
				"awg": map[string]any{
					"isThirdPartyConfig": true,
					"last_config":        innerJSON,
					"port":               strconv.Itoa(cfg.ListenPort),
					"protocol_version":   profile.VpnURIProtocolVersion(),
					"transport_proto":    "udp",
				},
				"container": "amnezia-awg",
			},
		},
		"defaultContainer": "amnezia-awg",
		"description":      description,
		"dns1":             dns1,
		"dns2":             dns2,
		"hostName":         hostName,
	}, nil
}

func (s *Service) Decode(vpnURI string) (map[string]any, error) {
	encoded := strings.TrimPrefix(strings.TrimSpace(vpnURI), "vpn://")
	if encoded == "" {
		return nil, ErrInvalidURI
	}
	payload, err := base64.RawURLEncoding.DecodeString(encoded)
	if err != nil || len(payload) < 5 {
		return nil, ErrInvalidURI
	}
	jsonLen := binary.BigEndian.Uint32(payload[0:4])
	jsonBytes, err := zlibDecompress(payload[4:])
	if err != nil || uint32(len(jsonBytes)) != jsonLen {
		return nil, ErrInvalidURI
	}
	var outer map[string]any
	if err := json.Unmarshal(jsonBytes, &outer); err != nil {
		return nil, ErrInvalidURI
	}
	return outer, nil
}

func zlibCompress(in []byte) ([]byte, error) {
	var buf bytes.Buffer
	w := zlib.NewWriter(&buf)
	if _, err := w.Write(in); err != nil {
		w.Close()
		return nil, err
	}
	if err := w.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func zlibDecompress(in []byte) ([]byte, error) {
	r, err := zlib.NewReader(bytes.NewReader(in))
	if err != nil {
		return nil, err
	}
	defer r.Close()
	return io.ReadAll(r)
}

func matchConf(conf, key string) string {
	re := regexp.MustCompile(`(?m)^` + regexp.QuoteMeta(key) + `\s*=\s*(.+)$`)
	m := re.FindStringSubmatch(conf)
	if m == nil {
		return ""
	}
	return strings.TrimSpace(m[1])
}

func parseEndpointHost(endpoint string) string {
	endpoint = strings.TrimSpace(endpoint)
	if endpoint == "" {
		return ""
	}
	if strings.HasPrefix(endpoint, "[") {
		if close := strings.Index(endpoint, "]"); close >= 0 {
			return endpoint[1:close]
		}
	}
	if strings.Count(endpoint, ":") == 1 {
		if i := strings.LastIndex(endpoint, ":"); i >= 0 {
			return endpoint[:i]
		}
	}
	parts := strings.Split(endpoint, ":")
	if len(parts) > 0 {
		return parts[0]
	}
	return endpoint
}

func jsonNoEscape(v any) (string, error) {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	if err := enc.Encode(v); err != nil {
		return "", err
	}
	return strings.TrimRight(buf.String(), "\n"), nil
}

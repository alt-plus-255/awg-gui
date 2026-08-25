package api

import (
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/cps"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/models"
	qrcodesvc "github.com/awggui/backend/internal/qrcode"
	"github.com/awggui/backend/internal/store"
	"github.com/awggui/backend/internal/vpnuri"
)

type ConfigController struct {
	AWG    *awg.Service
	QR     *qrcodesvc.Service
	VpnURI *vpnuri.Service
	Configs *store.Configs
	Peers   *store.Peers
	Clients *store.Clients
}

func (c *ConfigController) ProtocolVersions(w http.ResponseWriter, r *http.Request) {
	var versions []map[string]any
	for _, p := range c.AWG.Versions.All() {
		versions = append(versions, map[string]any{
			"id":                      p.ID(),
			"label":                   p.Label(),
			"vpn_uri_protocol_version": p.VpnURIProtocolVersion(),
			"supported_params":        p.SupportedParams(),
		})
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"versions": versions,
		"default":  c.AWG.Versions.Latest(),
	})
}

func (c *ConfigController) Index(w http.ResponseWriter, r *http.Request) {
	list, err := c.Configs.List(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	out := make([]map[string]any, 0, len(list))
	for i := range list {
		out = append(out, c.serializeConfig(r, &list[i]))
	}
	writeJSON(w, http.StatusOK, map[string]any{"configs": out})
}

func (c *ConfigController) Store(w http.ResponseWriter, r *http.Request) {
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	name := strings.TrimSpace(asString(req["name"]))
	if name == "" || utf8.RuneCountInString(name) > 64 {
		writeValidation(w, r, "name", "api.http_422", nil)
		return
	}
	typ := asString(req["type"])
	if typ != "server" && typ != "virtual_network" {
		writeValidation(w, r, "type", "api.http_422", nil)
		return
	}
	protocolVersion := c.AWG.Versions.Latest()
	if v := asString(req["protocol_version"]); v != "" {
		if !c.AWG.Versions.Has(v) {
			writeValidation(w, r, "protocol_version", "api.http_422", nil)
			return
		}
		protocolVersion = v
	}
	cpsProtocol := cps.DefaultProtocol()
	if v := asString(req["cps_protocol"]); v != "" {
		if !cps.HasProtocol(v) {
			writeValidation(w, r, "cps_protocol", "api.http_422", nil)
			return
		}
		cpsProtocol = v
	}
	defaults := c.AWG.DefaultConfigAttributes()
	subnet := asString(req["internal_subnet"])
	if subnet == "" {
		subnet = defaults["internal_subnet"].(string)
	}
	if err := c.assertSubnetAvailable(w, r, subnet, 0); err != nil {
		return
	}
	serverAddress := defaults["server_address"].(string)
	if m := subnetPrefix.FindStringSubmatch(subnet); m != nil {
		serverAddress = m[1] + ".1/" + m[3]
	}
	iface, err := c.AWG.AllocateIface(r.Context())
	if err != nil {
		writeValidation(w, r, "config", "configs.config_limit_reached", map[string]string{"count": strconv.Itoa(awg.PortMax - awg.PortMin + 1)})
		return
	}
	port := 0
	if _, ok := req["listen_port"]; ok {
		p, ok := asInt(req["listen_port"])
		if !ok || p < awg.PortMin || p > awg.PortMax {
			writeValidation(w, r, "listen_port", "api.http_422", nil)
			return
		}
		port = p
	} else {
		port, err = c.AWG.NextFreeListenPort(r.Context())
		if err != nil {
			writeValidation(w, r, "config", "configs.config_limit_reached", map[string]string{"count": strconv.Itoa(awg.PortMax - awg.PortMin + 1)})
			return
		}
	}
	if taken, _ := c.Configs.PortTaken(r.Context(), port, 0); taken {
		writeValidation(w, r, "listen_port", "configs.port_taken", nil)
		return
	}
	keys, err := c.AWG.GenerateKeyPair(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	junk := c.AWG.Versions.ProfileForConfig(protocolVersion).GenerateJunkParamsWithCPS(cpsProtocol)
	vnPolicy := "allow_all"
	if v := asString(req["vn_policy"]); v == "allow_all" || v == "deny_all" {
		vnPolicy = v
	}
	peerDNS := defaults["peer_dns"].(string)
	if v := asString(req["peer_dns"]); v != "" {
		peerDNS = v
	}
	allowed := defaults["client_allowed_ips"].(string)
	if v := asString(req["client_allowed_ips"]); v != "" {
		allowed = v
	}
	keepalive := defaults["persistent_keepalive"].(int)
	if n, ok := asInt(req["persistent_keepalive"]); ok {
		keepalive = n
	}
	enabled := true
	if b, ok := asBool(req["enabled"]); ok {
		enabled = b
	}
	cfg := &models.AwgConfig{
		Name:                  name,
		Type:                  typ,
		ProtocolVersion:       protocolVersion,
		ClientImportNameStyle: c.AWG.ResolveClientImportNameStyle(nil, asString(req["client_import_name_style"])),
		VnPolicy:              vnPolicy,
		Iface:                 iface,
		ListenPort:            port,
		InternalSubnet:        subnet,
		ServerAddress:         serverAddress,
		ServerPrivateKey:      keys.Private,
		ServerPublicKey:       keys.Public,
		PeerDNS:               peerDNS,
		ClientAllowedIPs:      allowed,
		PersistentKeepalive:   keepalive,
		Enabled:               enabled,
	}
	cfg.ApplyJunk(junk)
	if err := c.Configs.Create(r.Context(), cfg); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_ = c.AWG.ApplyConfig(r.Context(), nil, true, true)
	fresh, _ := c.Configs.Find(r.Context(), cfg.ID)
	if fresh == nil {
		fresh = cfg
	}
	writeJSON(w, http.StatusCreated, map[string]any{"config": c.serializeConfig(r, fresh)})
}

func (c *ConfigController) Show(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"config": c.serializeConfig(r, cfg)})
}

func (c *ConfigController) Update(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	delete(req, "type")

	versionChanged := false
	cpsProtocol := "quic"
	if v := asString(req["cps_protocol"]); v != "" {
		cpsProtocol = v
	}
	if v, ok := req["protocol_version"]; ok {
		newVer := strings.TrimSpace(asString(v))
		if newVer == "" {
			newVer = c.AWG.Versions.Latest()
		}
		if !c.AWG.Versions.Has(newVer) {
			writeValidation(w, r, "protocol_version", "api.http_422", nil)
			return
		}
		if newVer != cfg.ProtocolVersion {
			versionChanged = true
			cfg.ProtocolVersion = newVer
		}
	}
	delete(req, "protocol_version")
	delete(req, "cps_protocol")

	if name, ok := req["name"]; ok {
		n := strings.TrimSpace(asString(name))
		if n == "" || utf8.RuneCountInString(n) > 64 {
			writeValidation(w, r, "name", "api.http_422", nil)
			return
		}
		cfg.Name = n
	}
	if v, ok := req["vn_policy"]; ok {
		p := asString(v)
		if p != "allow_all" && p != "deny_all" {
			writeValidation(w, r, "vn_policy", "api.http_422", nil)
			return
		}
		cfg.VnPolicy = p
	}
	if v, ok := req["internal_subnet"]; ok {
		subnet := asString(v)
		if err := c.assertSubnetAvailable(w, r, subnet, cfg.ID); err != nil {
			return
		}
		cfg.InternalSubnet = subnet
	}
	if v, ok := req["server_address"]; ok {
		cfg.ServerAddress = asString(v)
	}
	if v, ok := req["listen_port"]; ok {
		p, ok := asInt(v)
		if !ok || p < awg.PortMin || p > awg.PortMax {
			writeValidation(w, r, "listen_port", "api.http_422", nil)
			return
		}
		if taken, _ := c.Configs.PortTaken(r.Context(), p, cfg.ID); taken {
			writeValidation(w, r, "listen_port", "configs.port_taken", nil)
			return
		}
		cfg.ListenPort = p
	}
	if v, ok := req["peer_dns"]; ok {
		cfg.PeerDNS = asString(v)
	}
	if v, ok := req["client_allowed_ips"]; ok {
		cfg.ClientAllowedIPs = asString(v)
	}
	if v, ok := req["persistent_keepalive"]; ok {
		if n, ok := asInt(v); ok && n >= 0 && n <= 65535 {
			cfg.PersistentKeepalive = n
		}
	}
	if v, ok := req["enabled"]; ok {
		if b, ok := asBool(v); ok {
			cfg.Enabled = b
		}
	}
	if v, ok := req["handshake_logging_enabled"]; ok {
		if b, ok := asBool(v); ok {
			cfg.HandshakeLoggingEnabled = b
		}
	}
	if v, ok := req["client_import_name_style"]; ok {
		cfg.ClientImportNameStyle = c.AWG.ResolveClientImportNameStyle(cfg, asString(v))
	}

	junkKeys := []string{"jc", "jmin", "jmax", "s1", "s2", "s3", "s4", "h1", "h2", "h3", "h4", "i1", "i2", "i3", "i4", "i5"}
	profile := c.AWG.Versions.ProfileForConfig(cfg.ProtocolVersion)

	if versionChanged {
		junkPatch := map[string]string{}
		hasClientJunk := false
		for _, k := range junkKeys {
			if v, ok := req[k]; ok {
				hasClientJunk = true
				junkPatch[k] = strings.TrimSpace(asString(v))
			}
		}
		if hasClientJunk {
			normalized := profile.NormalizeForPersist(junkPatch)
			if !c.validateCPSFields(w, r, profile, normalized) {
				return
			}
			cfg.ApplyJunk(normalized)
		} else {
			cfg.ApplyJunk(profile.GenerateJunkParamsWithCPS(cpsProtocol))
		}
	} else {
		junkPatch := map[string]string{}
		for _, k := range junkKeys {
			if v, ok := req[k]; ok {
				val := strings.TrimSpace(asString(v))
				if strings.HasPrefix(k, "i") && val == "" {
					junkPatch[k] = ""
				} else {
					junkPatch[k] = val
				}
			}
		}
		if len(junkPatch) > 0 {
			// Merge existing junk so NormalizeForPersist keeps unspecified fields.
			merged := map[string]string{}
			for _, k := range junkKeys {
				merged[k] = cfg.JunkField(k)
			}
			for k, v := range junkPatch {
				merged[k] = v
			}
			normalized := profile.NormalizeForPersist(merged)
			if !c.validateCPSFields(w, r, profile, normalized) {
				return
			}
			cfg.ApplyJunk(normalized)
		}
	}

	if cfg.Type == "virtual_network" && cfg.ResolverEnabled {
		cfg.ResolverEnabled = false
		cfg.ConnectionID = nil
		cfg.ResolverLastError = nil
	}

	if _, subnetSet := req["internal_subnet"]; subnetSet {
		if _, addrSet := req["server_address"]; !addrSet {
			_ = c.AWG.SyncServerAddressFromSubnet(r.Context(), cfg)
		} else if err := c.Configs.Update(r.Context(), cfg); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
			return
		}
	} else if err := c.Configs.Update(r.Context(), cfg); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}

	_ = c.AWG.ApplyConfig(r.Context(), nil, true, true)
	fresh, _ := c.Configs.Find(r.Context(), cfg.ID)
	if fresh == nil {
		fresh = cfg
	}
	writeJSON(w, http.StatusOK, map[string]any{"config": c.serializeConfig(r, fresh)})
}

func (c *ConfigController) Destroy(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	n, _ := c.Configs.Count(r.Context())
	if n <= 1 {
		writeValidation(w, r, "config", "configs.cannot_delete_last", nil)
		return
	}
	if err := c.Configs.Delete(r.Context(), cfg.ID); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_ = c.AWG.ApplyConfig(r.Context(), nil, true, true)
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (c *ConfigController) ListPeers(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	c.AWG.PrimeConfigPeerCache(r.Context(), cfg)
	peers, err := c.Peers.ListByConfig(r.Context(), cfg.ID)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	out := make([]map[string]any, 0, len(peers))
	for i := range peers {
		if cl, err := c.Clients.Find(r.Context(), peers[i].VpnClientID); err == nil {
			peers[i].Client = cl
		}
		peers[i].Config = cfg
		out = append(out, c.AWG.SerializePeer(r.Context(), cfg, &peers[i]))
	}
	writeJSON(w, http.StatusOK, map[string]any{"peers": out})
}

func (c *ConfigController) Links(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"links": c.AWG.PeerLinks(r.Context(), cfg.ID)})
}

func (c *ConfigController) AttachPeer(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	clientID, ok := asInt64(req["vpn_client_id"])
	if !ok || clientID < 1 {
		writeValidation(w, r, "vpn_client_id", "api.http_422", nil)
		return
	}
	client, err := c.Clients.Find(r.Context(), clientID)
	if err != nil || client == nil {
		writeValidation(w, r, "vpn_client_id", "api.http_422", nil)
		return
	}
	if exists, _ := c.Peers.ExistsMembership(r.Context(), cfg.ID, client.ID); exists {
		writeValidation(w, r, "vpn_client_id", "configs.peer_already_bound", nil)
		return
	}
	extraIPs, ok := c.normalizeExtraIPs(w, r, req["extra_allowed_ips"])
	if !ok {
		return
	}
	if !c.assertVNSubnet(w, r, cfg, extraIPs) {
		return
	}
	excluded, ok := c.normalizeExcluded(w, r, cfg, client.ID, req["excluded_client_ids"])
	if !ok {
		return
	}
	keys, err := c.AWG.GenerateKeyPair(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	usePSK := true
	if b, ok := asBool(req["use_preshared_key"]); ok {
		usePSK = b
	}
	var psk *string
	if usePSK {
		v, err := awg.GeneratePresharedKey()
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
			return
		}
		psk = &v
	}
	addr, err := c.AWG.NextClientAddress(r.Context(), cfg)
	if err != nil {
		writeValidation(w, r, "address", "configs.no_free_addresses", nil)
		return
	}
	enabled := true
	if b, ok := asBool(req["enabled"]); ok {
		enabled = b
	}
	var keepalive *int
	if _, present := req["keepalive"]; present && req["keepalive"] != nil {
		if n, ok := asInt(req["keepalive"]); ok {
			keepalive = &n
		}
	}
	mutual := false
	if b, ok := asBool(req["exclusions_mutual"]); ok {
		mutual = b
	}
	m := &models.AwgConfigPeer{
		AwgConfigID:       cfg.ID,
		VpnClientID:       client.ID,
		Enabled:           enabled,
		PrivateKey:        keys.Private,
		PublicKey:         keys.Public,
		PresharedKey:      psk,
		Address:           addr,
		ExtraAllowedIPs:   extraIPs,
		ExcludedClientIDs: excluded,
		ExclusionsMutual:  mutual,
		Keepalive:         keepalive,
	}
	if err := c.Peers.Create(r.Context(), m); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_, _ = c.AWG.EnsurePeerKeys(r.Context(), m)
	fresh, _ := c.Peers.Find(r.Context(), m.ID)
	if fresh == nil {
		fresh = m
	}
	fresh.Client = client
	fresh.Config = cfg
	_ = c.AWG.ApplyConfig(r.Context(), cfg, false, false)
	writeJSON(w, http.StatusCreated, map[string]any{"membership": c.AWG.SerializePeer(r.Context(), cfg, fresh)})
}

func (c *ConfigController) UpdatePeer(w http.ResponseWriter, r *http.Request) {
	cfg, client, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	if v, ok := req["enabled"]; ok {
		if b, ok := asBool(v); ok {
			m.Enabled = b
		}
	}
	if _, ok := req["keepalive"]; ok {
		if req["keepalive"] == nil {
			m.Keepalive = nil
		} else if n, ok := asInt(req["keepalive"]); ok {
			m.Keepalive = &n
		}
	}
	if _, ok := req["extra_allowed_ips"]; ok {
		extra, ok := c.normalizeExtraIPs(w, r, req["extra_allowed_ips"])
		if !ok {
			return
		}
		if !c.assertVNSubnet(w, r, cfg, extra) {
			return
		}
		m.ExtraAllowedIPs = extra
	}
	if _, ok := req["excluded_client_ids"]; ok {
		excluded, ok := c.normalizeExcluded(w, r, cfg, client.ID, req["excluded_client_ids"])
		if !ok {
			return
		}
		m.ExcludedClientIDs = excluded
	}
	if v, ok := req["exclusions_mutual"]; ok {
		if b, ok := asBool(v); ok {
			m.ExclusionsMutual = b
		}
	}
	if v, ok := req["use_preshared_key"]; ok {
		if b, ok := asBool(v); ok {
			if b {
				if m.PresharedKey == nil || *m.PresharedKey == "" {
					psk, err := awg.GeneratePresharedKey()
					if err != nil {
						writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
						return
					}
					m.PresharedKey = &psk
				}
			} else {
				m.PresharedKey = nil
			}
		}
	}
	if err := c.Peers.Update(r.Context(), m); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_ = c.AWG.ApplyConfig(r.Context(), cfg, false, false)
	fresh, _ := c.Peers.Find(r.Context(), m.ID)
	if fresh == nil {
		fresh = m
	}
	fresh.Client = client
	fresh.Config = cfg
	writeJSON(w, http.StatusOK, map[string]any{"membership": c.AWG.SerializePeer(r.Context(), cfg, fresh)})
}

func (c *ConfigController) DetachPeer(w http.ResponseWriter, r *http.Request) {
	cfg, client, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	_ = c.Peers.DeleteMembership(r.Context(), cfg.ID, client.ID)
	c.pruneExcludedClientID(r, cfg, client.ID)
	c.pruneClientFromZones(r, cfg, client.ID)
	_ = c.AWG.ApplyConfig(r.Context(), cfg, false, false)
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (c *ConfigController) UpdateZones(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	if cfg.Type != "virtual_network" {
		writeValidation(w, r, "config", "configs.access_rules_vn_only", nil)
		return
	}
	var req struct {
		Rules []models.VnRule `json:"rules"`
	}
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	normalized, ok := c.normalizeRules(w, r, cfg, req.Rules)
	if !ok {
		return
	}
	cfg.SetVnZones(normalized)
	if err := c.Configs.Update(r.Context(), cfg); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_ = c.AWG.ApplyConfig(r.Context(), nil, true, true)
	fresh, _ := c.Configs.Find(r.Context(), cfg.ID)
	if fresh == nil {
		fresh = cfg
	}
	writeJSON(w, http.StatusOK, map[string]any{"config": c.serializeConfig(r, fresh)})
}

func (c *ConfigController) ServerConfig(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	body, err := c.AWG.BuildServerConfig(r.Context(), cfg)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	name := cfg.Iface
	if name == "" {
		name = "awg"
	}
	writeText(w, body, "text/plain; charset=UTF-8", `inline; filename="`+name+`.conf"`)
}

func (c *ConfigController) PeerConfig(w http.ResponseWriter, r *http.Request) {
	_, _, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	body, err := c.AWG.BuildClientConfig(r.Context(), m)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	body = c.QR.NormalizeConfigText(body)
	filename := c.AWG.ClientImportFilename(r.Context(), m, "", "")
	writeText(w, body, "text/plain; charset=UTF-8", `attachment; filename="`+filename+`"`)
}

func (c *ConfigController) PeerVpnURI(w http.ResponseWriter, r *http.Request) {
	_, _, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	uri, err := c.VpnURI.BuildFromMembership(r.Context(), m)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	writeText(w, uri, "text/plain; charset=UTF-8", "")
}

func (c *ConfigController) PeerQR(w http.ResponseWriter, r *http.Request) {
	_, _, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	encoding := r.URL.Query().Get("encoding")
	if encoding == "" {
		encoding = "amnezia"
	}
	format := r.URL.Query().Get("format")
	if format == "" {
		format = "png"
	}
	var payload string
	var err error
	switch encoding {
	case "conf":
		var conf string
		conf, err = c.AWG.BuildClientConfig(r.Context(), m)
		if err == nil {
			payload = c.QR.NormalizeConfigText(conf)
		}
	case "vpn-uri":
		payload, err = c.VpnURI.BuildFromMembership(r.Context(), m)
	default:
		payload, err = c.VpnURI.BuildAmneziaQRPackFromMembership(r.Context(), m)
	}
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	if format == "svg" {
		body, err := c.QR.BuildSVG(payload)
		if err != nil {
			writeMessage(w, r, http.StatusInternalServerError, "configs.qr_too_large", nil)
			return
		}
		writeBytes(w, body, "image/svg+xml")
		return
	}
	body, err := c.QR.BuildPNG(payload)
	if err != nil {
		writeMessage(w, r, http.StatusInternalServerError, "configs.qr_too_large", nil)
		return
	}
	writeBytes(w, body, "image/png")
}

func (c *ConfigController) RegeneratePeerKeys(w http.ResponseWriter, r *http.Request) {
	cfg, client, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	keys, err := c.AWG.GenerateKeyPair(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	m.PrivateKey = keys.Private
	m.PublicKey = keys.Public
	if err := c.Peers.Update(r.Context(), m); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_ = c.AWG.ApplyConfig(r.Context(), nil, true, true)
	m.Client = client
	m.Config = cfg
	writeJSON(w, http.StatusOK, map[string]any{"membership": c.AWG.SerializePeer(r.Context(), cfg, m)})
}

func (c *ConfigController) RegeneratePeerPSK(w http.ResponseWriter, r *http.Request) {
	cfg, client, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	if m.PresharedKey == nil || *m.PresharedKey == "" {
		writeValidation(w, r, "use_preshared_key", "configs.preshared_key_disabled", nil)
		return
	}
	psk, err := awg.GeneratePresharedKey()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	m.PresharedKey = &psk
	if err := c.Peers.Update(r.Context(), m); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	_ = c.AWG.ApplyConfig(r.Context(), nil, true, true)
	m.Client = client
	m.Config = cfg
	writeJSON(w, http.StatusOK, map[string]any{"membership": c.AWG.SerializePeer(r.Context(), cfg, m)})
}

func (c *ConfigController) RevealPeerKeys(w http.ResponseWriter, r *http.Request) {
	if !c.assertAdminPassword(w, r) {
		return
	}
	_, _, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"private_key":       m.PrivateKey,
		"public_key":        m.PublicKey,
		"preshared_key":     m.PresharedKey,
		"use_preshared_key": m.PresharedKey != nil && *m.PresharedKey != "",
	})
}

func (c *ConfigController) HandshakeLogs(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	writeJSON(w, http.StatusOK, c.AWG.ListHandshakeLogs(r.Context(), cfg, nil, queryInt64(r, "before_id"), queryInt(r, "per_page", 50)))
}

func (c *ConfigController) PeerHandshakeLogs(w http.ResponseWriter, r *http.Request) {
	_, client, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	cfg, _ := c.Configs.Find(r.Context(), m.AwgConfigID)
	if cfg == nil {
		writeNotFound(w, r)
		return
	}
	id := client.ID
	writeJSON(w, http.StatusOK, c.AWG.ListHandshakeLogs(r.Context(), cfg, &id, queryInt64(r, "before_id"), queryInt(r, "per_page", 50)))
}

func (c *ConfigController) ClearHandshakeLogs(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	_ = c.AWG.ClearHandshakeLogs(r.Context(), cfg)
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":              true,
		"log_bytes":       0,
		"log_bytes_limit": awg.HandshakeByteLimit,
		"message":         i18n.T(auth.LocaleFromContext(r.Context()), "configs.handshake_logs_cleared"),
	})
}

func (c *ConfigController) ResetPeerTraffic(w http.ResponseWriter, r *http.Request) {
	cfg, client, m := c.loadMembership(w, r)
	if m == nil {
		return
	}
	_ = c.AWG.ResetPeerTraffic(r.Context(), m)
	fresh, _ := c.Peers.Find(r.Context(), m.ID)
	if fresh == nil {
		fresh = m
	}
	fresh.Client = client
	fresh.Config = cfg
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":         true,
		"membership": c.AWG.SerializePeer(r.Context(), cfg, fresh),
		"message":    i18n.T(auth.LocaleFromContext(r.Context()), "configs.peer_traffic_reset"),
	})
}

func (c *ConfigController) ResetConfigTraffic(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	n, _ := c.AWG.ResetConfigTraffic(r.Context(), cfg)
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":          true,
		"reset_count": n,
		"message":     i18n.T(auth.LocaleFromContext(r.Context()), "configs.config_traffic_reset"),
	})
}

func (c *ConfigController) RegenerateServerKeys(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	pub, err := c.AWG.RegenerateConfigKeys(r.Context(), cfg)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	fresh, _ := c.Configs.Find(r.Context(), cfg.ID)
	if fresh == nil {
		fresh = cfg
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":                true,
		"server_public_key": pub,
		"config":            c.serializeConfig(r, fresh),
		"message":           i18n.T(auth.LocaleFromContext(r.Context()), "configs.server_keys_regenerated"),
	})
}

func (c *ConfigController) RegenerateJunk(w http.ResponseWriter, r *http.Request) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	cpsProtocol := cps.DefaultProtocol()
	var req map[string]any
	if err := decodeJSON(r, &req); err == nil {
		if v := asString(req["cps_protocol"]); v != "" {
			if !cps.HasProtocol(v) {
				writeValidation(w, r, "cps_protocol", "api.http_422", nil)
				return
			}
			cpsProtocol = v
		}
	}
	junk, err := c.AWG.RegenerateConfigJunkWithCPS(r.Context(), cfg, cpsProtocol)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	fresh, _ := c.Configs.Find(r.Context(), cfg.ID)
	if fresh == nil {
		fresh = cfg
	}
	writeJSON(w, http.StatusOK, map[string]any{"junk": junk, "config": c.serializeConfig(r, fresh)})
}

func (c *ConfigController) RevealServerKey(w http.ResponseWriter, r *http.Request) {
	if !c.assertAdminPassword(w, r) {
		return
	}
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"server_private_key": cfg.ServerPrivateKey,
		"server_public_key":  cfg.ServerPublicKey,
	})
}

func (c *ConfigController) Restart(w http.ResponseWriter, r *http.Request) {
	if c.loadConfig(w, r) == nil {
		return
	}
	writeRestartResult(w, r, c.AWG.RestartAWG(r.Context()))
}

func writeRestartResult(w http.ResponseWriter, r *http.Request, result map[string]any) {
	locale := auth.LocaleFromContext(r.Context())
	if already, _ := result["already_restarting"].(bool); already {
		writeJSON(w, http.StatusConflict, map[string]any{
			"ok":                 false,
			"already_restarting": true,
			"message":            i18n.T(locale, "api.awg_restart_already_running"),
			"details":            result,
		})
		return
	}
	if ok, _ := result["ok"].(bool); !ok {
		writeJSON(w, http.StatusInternalServerError, map[string]any{
			"ok":      false,
			"message": i18n.T(locale, "api.awg_restart_failed"),
			"details": result,
		})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok":      true,
		"message": i18n.T(locale, "api.awg_restart_ok"),
		"details": result,
	})
}

func (c *ConfigController) loadConfig(w http.ResponseWriter, r *http.Request) *models.AwgConfig {
	id, ok := pathID(r, "configID")
	if !ok {
		writeNotFound(w, r)
		return nil
	}
	cfg, err := c.Configs.Find(r.Context(), id)
	if err != nil || cfg == nil {
		writeNotFound(w, r)
		return nil
	}
	return cfg
}

func (c *ConfigController) loadMembership(w http.ResponseWriter, r *http.Request) (*models.AwgConfig, *models.VpnClient, *models.AwgConfigPeer) {
	cfg := c.loadConfig(w, r)
	if cfg == nil {
		return nil, nil, nil
	}
	clientID, ok := pathID(r, "clientID")
	if !ok {
		writeNotFound(w, r)
		return nil, nil, nil
	}
	client, err := c.Clients.Find(r.Context(), clientID)
	if err != nil || client == nil {
		writeNotFound(w, r)
		return nil, nil, nil
	}
	m, err := c.Peers.FindMembership(r.Context(), cfg.ID, client.ID)
	if err != nil || m == nil {
		writeNotFound(w, r)
		return nil, nil, nil
	}
	m.Client = client
	m.Config = cfg
	return cfg, client, m
}

func (c *ConfigController) assertAdminPassword(w http.ResponseWriter, r *http.Request) bool {
	var req struct {
		Password string `json:"password"`
	}
	if err := decodeJSON(r, &req); err != nil || req.Password == "" {
		writeValidation(w, r, "password", "configs.admin_password_invalid", nil)
		return false
	}
	user := auth.UserFromContext(r.Context())
	if user == nil || !auth.CheckPassword(user.Password, req.Password) {
		writeValidation(w, r, "password", "configs.admin_password_invalid", nil)
		return false
	}
	return true
}

func (c *ConfigController) assertSubnetAvailable(w http.ResponseWriter, r *http.Request, subnet string, ignoreID int64) error {
	key := awg.NormalizeSubnetKey(subnet)
	if key == "" {
		writeValidation(w, r, "internal_subnet", "configs.invalid_internal_subnet", nil)
		return awg.ErrInvalidSubnet
	}
	existing, _ := c.Configs.Subnets(r.Context(), ignoreID)
	for _, e := range existing {
		if awg.NormalizeSubnetKey(e) == key {
			writeValidation(w, r, "internal_subnet", "configs.subnet_taken", nil)
			return awg.ErrInvalidSubnet
		}
	}
	return nil
}

func (c *ConfigController) assertVNSubnet(w http.ResponseWriter, r *http.Request, cfg *models.AwgConfig, extra []string) bool {
	if cfg.Type != "virtual_network" {
		return true
	}
	if len(extra) != 1 {
		writeValidation(w, r, "extra_allowed_ips", "configs.vn_extra_allowed_ips_one", nil)
		return false
	}
	return true
}

func (c *ConfigController) normalizeExtraIPs(w http.ResponseWriter, r *http.Request, raw any) ([]string, bool) {
	if raw == nil {
		return []string{}, true
	}
	arr, ok := raw.([]any)
	if !ok {
		writeValidation(w, r, "extra_allowed_ips", "api.http_422", nil)
		return nil, false
	}
	var out []string
	seen := map[string]bool{}
	for _, item := range arr {
		cidr := strings.TrimSpace(asString(item))
		if cidr == "" {
			continue
		}
		if !cidrRE.MatchString(cidr) {
			writeValidation(w, r, "extra_allowed_ips", "configs.invalid_cidr", map[string]string{"cidr": cidr})
			return nil, false
		}
		host := strings.SplitN(cidr, "/", 2)[0]
		if net.ParseIP(host) == nil {
			writeValidation(w, r, "extra_allowed_ips", "configs.invalid_ip_in_cidr", map[string]string{"cidr": cidr})
			return nil, false
		}
		if cidr == "0.0.0.0/0" || cidr == "::/0" {
			writeValidation(w, r, "extra_allowed_ips", "configs.full_tunnel_cidr_forbidden", map[string]string{"cidr": cidr})
			return nil, false
		}
		if !seen[cidr] {
			seen[cidr] = true
			out = append(out, cidr)
		}
	}
	return out, true
}

func (c *ConfigController) normalizeExcluded(w http.ResponseWriter, r *http.Request, cfg *models.AwgConfig, ownID int64, raw any) ([]int64, bool) {
	if cfg.Type != "virtual_network" || raw == nil {
		return []int64{}, true
	}
	arr, ok := raw.([]any)
	if !ok {
		return []int64{}, true
	}
	attached, _ := c.Peers.AttachedClientIDs(r.Context(), cfg.ID)
	attachedSet := map[int64]bool{}
	for _, id := range attached {
		attachedSet[id] = true
	}
	var out []int64
	seen := map[int64]bool{}
	for _, item := range arr {
		id, ok := asInt64(item)
		if !ok {
			continue
		}
		if id == ownID {
			writeValidation(w, r, "excluded_client_ids", "configs.cannot_exclude_self", nil)
			return nil, false
		}
		if !attachedSet[id] {
			writeValidation(w, r, "excluded_client_ids", "configs.excluded_not_bound", nil)
			return nil, false
		}
		if !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	return out, true
}

func (c *ConfigController) normalizeRules(w http.ResponseWriter, r *http.Request, cfg *models.AwgConfig, rules []models.VnRule) (models.VnZones, bool) {
	attached, _ := c.Peers.AttachedClientIDs(r.Context(), cfg.ID)
	attachedSet := map[int64]bool{}
	for _, id := range attached {
		attachedSet[id] = true
	}
	var out []models.VnRule
	seen := map[string]bool{}
	for _, rule := range rules {
		src := intersectIDs(rule.SrcClientIDs, attachedSet)
		dest := intersectIDs(rule.DestClientIDs, attachedSet)
		if len(src) == 0 || len(dest) == 0 {
			continue
		}
		for _, id := range src {
			if containsID(dest, id) {
				writeValidation(w, r, "rules", "configs.peer_cannot_be_src_and_dest", nil)
				return models.VnZones{}, false
			}
		}
		key := joinIDs(src) + "→" + joinIDs(dest)
		if seen[key] {
			continue
		}
		seen[key] = true
		out = append(out, models.VnRule{SrcClientIDs: src, DestClientIDs: dest})
	}
	return models.VnZones{Rules: out}, true
}

func (c *ConfigController) pruneClientFromZones(r *http.Request, cfg *models.AwgConfig, clientID int64) {
	z := cfg.VnZones()
	if len(z.Rules) == 0 {
		return
	}
	changed := false
	var rules []models.VnRule
	for _, rule := range z.Rules {
		src := withoutID(rule.SrcClientIDs, clientID)
		dest := withoutID(rule.DestClientIDs, clientID)
		if len(src) != len(rule.SrcClientIDs) || len(dest) != len(rule.DestClientIDs) {
			changed = true
		}
		if len(src) > 0 && len(dest) > 0 {
			rules = append(rules, models.VnRule{SrcClientIDs: src, DestClientIDs: dest})
		} else if len(rule.SrcClientIDs) > 0 && len(rule.DestClientIDs) > 0 {
			changed = true
		}
	}
	if changed {
		cfg.SetVnZones(models.VnZones{Rules: rules})
		_ = c.Configs.Update(r.Context(), cfg)
	}
}

func (c *ConfigController) pruneExcludedClientID(r *http.Request, cfg *models.AwgConfig, clientID int64) {
	peers, err := c.Peers.ListByConfig(r.Context(), cfg.ID)
	if err != nil {
		return
	}
	for i := range peers {
		if containsID(peers[i].ExcludedClientIDs, clientID) {
			peers[i].ExcludedClientIDs = withoutID(peers[i].ExcludedClientIDs, clientID)
			_ = c.Peers.Update(r.Context(), &peers[i])
		}
	}
}

func (c *ConfigController) serializeConfig(r *http.Request, cfg *models.AwgConfig) map[string]any {
	locale := auth.LocaleFromContext(r.Context())
	profile := c.AWG.Versions.ProfileForConfig(cfg.ProtocolVersion)
	typeLabel := i18n.T(locale, "api.type_server")
	if cfg.Type == "virtual_network" {
		typeLabel = i18n.T(locale, "api.type_virtual_network")
	}
	return map[string]any{
		"id":                         cfg.ID,
		"name":                       cfg.Name,
		"type":                       cfg.Type,
		"type_label":                 typeLabel,
		"protocol_version":           orDefault(cfg.ProtocolVersion, c.AWG.Versions.Latest()),
		"protocol_label":             profile.Label(),
		"client_import_name_style":   c.AWG.ResolveClientImportNameStyle(cfg, ""),
		"supported_params":           profile.SupportedParams(),
		"vn_policy":                  orDefault(cfg.VnPolicy, "allow_all"),
		"vn_zones":                   map[string]any{"rules": cfg.VnZones().Rules},
		"iface":                      cfg.Iface,
		"listen_port":                cfg.ListenPort,
		"internal_subnet":            cfg.InternalSubnet,
		"server_address":             cfg.ServerAddress,
		"server_public_key":          cfg.ServerPublicKey,
		"peer_dns":                   cfg.PeerDNS,
		"client_allowed_ips":         cfg.ClientAllowedIPs,
		"persistent_keepalive":       cfg.PersistentKeepalive,
		"enabled":                    cfg.Enabled,
		"handshake_logging_enabled":  cfg.HandshakeLoggingEnabled,
		"handshake_log_bytes":        cfg.HandshakeLogBytes,
		"handshake_log_bytes_limit":  awg.HandshakeByteLimit,
		"resolver_enabled":           cfg.ResolverEnabled,
		"connection_id":              cfg.ConnectionID,
		"community_lists":            nonNil(cfg.CommunityLists),
		"user_domains":               nonNil(cfg.UserDomains),
		"user_subnets":               nonNil(cfg.UserSubnets),
		"resolver_updated_at":        formatTimePtr(cfg.ResolverUpdatedAt),
		"resolver_last_error":        cfg.ResolverLastError,
		"config_path":                c.AWG.ConfigPath(cfg),
		"host_config_path":           c.AWG.HostConfigPath(cfg),
		"peers_count":                cfg.PeersCount,
		"jc": cfg.Jc, "jmin": cfg.Jmin, "jmax": cfg.Jmax,
		"s1": cfg.S1, "s2": cfg.S2, "s3": cfg.S3, "s4": cfg.S4,
		"h1": cfg.H1, "h2": cfg.H2, "h3": cfg.H3, "h4": cfg.H4,
		"i1": cfg.I1, "i2": cfg.I2, "i3": cfg.I3, "i4": cfg.I4, "i5": cfg.I5,
		"created_at": cfg.CreatedAt.Format(time.RFC3339),
		"updated_at": cfg.UpdatedAt.Format(time.RFC3339),
	}
}

func queryInt(r *http.Request, key string, fallback int) int {
	v := r.URL.Query().Get(key)
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func queryInt64(r *http.Request, key string) *int64 {
	v := r.URL.Query().Get(key)
	if v == "" {
		return nil
	}
	n, err := strconv.ParseInt(v, 10, 64)
	if err != nil || n < 1 {
		return nil
	}
	return &n
}

func orDefault(v, d string) string {
	if v == "" {
		return d
	}
	return v
}

func (c *ConfigController) validateCPSFields(w http.ResponseWriter, r *http.Request, profile awg.VersionProfile, junk map[string]string) bool {
	supported := map[string]bool{}
	for _, p := range profile.SupportedParams() {
		supported[p] = true
	}
	if !supported["i1"] {
		return true
	}
	fields := map[string]string{}
	anySet := false
	for _, k := range []string{"i1", "i2", "i3", "i4", "i5"} {
		v := strings.TrimSpace(junk[k])
		fields[k] = v
		if v != "" {
			anySet = true
		}
	}
	if !anySet {
		return true
	}
	allowD := profile.ID() == "2.0" || profile.ID() == "3.1"
	constraints := cps.ConstraintsFromStrings(junk["s1"], junk["s2"], junk["s3"], junk["s4"], cps.DefaultMTU, allowD)
	result := cps.ValidateAll(fields, constraints)
	if result.Valid {
		return true
	}
	for k, fr := range result.Fields {
		if !fr.OK && len(fr.Errors) > 0 {
			writeValidation(w, r, k, "api.http_422", nil)
			return false
		}
	}
	writeValidation(w, r, "i1", "api.http_422", nil)
	return false
}

func formatTimePtr(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.Format(time.RFC3339)
}

func intersectIDs(ids []int64, allowed map[int64]bool) []int64 {
	var out []int64
	seen := map[int64]bool{}
	for _, id := range ids {
		if allowed[id] && !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	return out
}

func containsID(ids []int64, id int64) bool {
	for _, x := range ids {
		if x == id {
			return true
		}
	}
	return false
}

func withoutID(ids []int64, id int64) []int64 {
	var out []int64
	for _, x := range ids {
		if x != id {
			out = append(out, x)
		}
	}
	return out
}

func joinIDs(ids []int64) string {
	parts := make([]string, len(ids))
	for i, id := range ids {
		parts[i] = strconv.FormatInt(id, 10)
	}
	return strings.Join(parts, ",")
}

var (
	subnetPrefix = regexp.MustCompile(`^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$`)
	cidrRE       = regexp.MustCompile(`^[^/\s]+/\d{1,3}$`)
)

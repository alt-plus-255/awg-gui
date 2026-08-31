package stats

import (
	"context"
	"net"
	"strings"
	"time"

	"github.com/awggui/backend/internal/models"
)

func (s *Service) PeersFromDB(ctx context.Context, configID *int64) (peers []map[string]any, err error) {
	var ids []int64
	if configID != nil {
		ids = []int64{*configID}
	} else {
		ids = nil
	}
	rows := s.memberships(ctx, ids)
	clients, _ := s.Clients.List(ctx)
	clientByID := map[int64]models.VpnClient{}
	for _, c := range clients {
		clientByID[c.ID] = c
	}
	var configs []models.AwgConfig
	if configID != nil {
		if c, e := s.Configs.Find(ctx, *configID); e == nil && c != nil {
			configs = []models.AwgConfig{*c}
		}
	} else {
		configs, _ = s.Configs.All(ctx)
	}
	cfgByID := map[int64]*models.AwgConfig{}
	for i := range configs {
		cfgByID[configs[i].ID] = &configs[i]
	}

	enabledByConfig := map[int64][]models.AwgConfigPeer{}
	for i := range rows {
		m := rows[i]
		if !m.Enabled {
			continue
		}
		enabledByConfig[m.AwgConfigID] = append(enabledByConfig[m.AwgConfigID], m)
	}

	out := make([]map[string]any, 0, len(rows))
	for i := range rows {
		m := &rows[i]
		cfg := cfgByID[m.AwgConfigID]
		if cfg == nil {
			continue
		}
		if cl, ok := clientByID[m.VpnClientID]; ok {
			cp := cl
			m.Client = &cp
		}
		m.Config = cfg
		out = append(out, s.SerializePeer(cfg, m, enabledByConfig[cfg.ID]))
	}
	return out, nil
}

func (s *Service) SerializePeer(cfg *models.AwgConfig, m *models.AwgConfigPeer, enabled []models.AwgConfigPeer) map[string]any {
	var handshake *int64
	if m.LatestHandshake != nil && *m.LatestHandshake > 0 {
		handshake = m.LatestHandshake
	}
	online := m.Online
	if online == nil && handshake != nil {
		v := *handshake > 0 && (time.Now().Unix()-*handshake) < OnlineWindowSec
		online = &v
	}
	var name, comment any
	if m.Client != nil {
		name = m.Client.Name
		comment = m.Client.Comment
	}
	extras := m.ExtraAllowedIPs
	if extras == nil {
		extras = []string{}
	}
	excluded := m.ExcludedClientIDs
	if excluded == nil {
		excluded = []int64{}
	}
	var hsHuman any
	if handshake != nil {
		hsHuman = time.Unix(*handshake, 0).Format(time.RFC3339)
	}
	usePSK := m.PresharedKey != nil && *m.PresharedKey != ""
	return map[string]any{
		"membership_id":          m.ID,
		"config_id":              cfg.ID,
		"config_name":            cfg.Name,
		"config_iface":           cfg.Iface,
		"config_type":            cfg.Type,
		"id":                     m.VpnClientID,
		"client_id":              m.VpnClientID,
		"vpn_client_id":          m.VpnClientID,
		"name":                   name,
		"comment":                comment,
		"enabled":                m.Enabled,
		"address":                m.Address,
		"extra_allowed_ips":      extras,
		"excluded_client_ids":    excluded,
		"exclusions_mutual":      m.ExclusionsMutual,
		"forward_policy":         normalizeForwardPolicy(m.ForwardPolicy),
		"forward_allowed_cidrs":  forwardAllowedCIDRsNonNil(m.ForwardAllowedCIDRs),
		"server_allowed_ips":     serverPeerAllowedIpsString(cfg, m),
		"client_allowed_ips":     clientAllowedIpsString(cfg, m, enabled),
		"public_key":             m.PublicKey,
		"use_preshared_key":      usePSK,
		"keepalive":              m.Keepalive,
		"endpoint":               m.RuntimeEndpoint,
		"latest_handshake":       handshake,
		"latest_handshake_human": hsHuman,
		"transfer_rx":            m.TransferRx,
		"transfer_tx":            m.TransferTx,
		"traffic_rx_total":       m.TrafficRxTotal,
		"traffic_tx_total":       m.TrafficTxTotal,
		"traffic_reset_at":       isoPtr(m.TrafficResetAt),
		"online":                 online,
		"stats_synced_at":        isoPtr(m.StatsSyncedAt),
		"created_at":             isoTime(m.CreatedAt),
		"updated_at":             isoTime(m.UpdatedAt),
	}
}

func serverPeerAllowedIps(cfg *models.AwgConfig, m *models.AwgConfigPeer) []string {
	ips := []string{m.Address}
	if cfg != nil && cfg.Type == "server" {
		return uniqueNonEmpty(ips)
	}
	for _, cidr := range m.ExtraAllowedIPs {
		cidr = strings.TrimSpace(cidr)
		if cidr == "" || cidr == m.Address {
			continue
		}
		ips = append(ips, cidr)
	}
	return uniqueNonEmpty(ips)
}

func serverPeerAllowedIpsString(cfg *models.AwgConfig, m *models.AwgConfigPeer) string {
	return strings.Join(serverPeerAllowedIps(cfg, m), ", ")
}

func clientAllowedIps(cfg *models.AwgConfig, m *models.AwgConfigPeer, enabled []models.AwgConfigPeer) []string {
	if cfg.Type == "virtual_network" {
		denyAll := cfg.VnPolicy == "deny_all"
		ips := []string{m.Address}
		for i := range enabled {
			other := &enabled[i]
			if other.ID == m.ID {
				continue
			}
			if denyAll {
				dir := ruleDirection(cfg, m, other)
				if dir == "forward" {
					for _, cidr := range other.ExtraAllowedIPs {
						cidr = strings.TrimSpace(cidr)
						if cidr != "" {
							ips = append(ips, cidr)
						}
					}
				} else if dir == "reply" && other.Address != "" {
					ips = append(ips, other.Address)
				}
				continue
			}
			if isPeerExcluded(m, other) {
				continue
			}
			for _, cidr := range other.ExtraAllowedIPs {
				cidr = strings.TrimSpace(cidr)
				if cidr != "" {
					ips = append(ips, cidr)
				}
			}
		}
		return uniqueNonEmpty(ips)
	}

	if cfg.IsResolverEnabled() {
		return []string{"0.0.0.0/0", "::/0"}
	}

	var split []string
	for _, cidr := range m.ExtraAllowedIPs {
		cidr = strings.TrimSpace(cidr)
		if cidr == "" || cidr == "0.0.0.0/0" || cidr == "::/0" {
			continue
		}
		canonical := canonicalNetworkCidr(cidr)
		if canonical == "" {
			canonical = cidr
		}
		if canonical == "0.0.0.0/0" || canonical == "::/0" {
			continue
		}
		split = append(split, canonical)
	}
	split = uniqueNonEmpty(split)
	if len(split) > 0 {
		ips := []string{}
		tunnel := strings.TrimSpace(cfg.InternalSubnet)
		if tunnel == "" {
			tunnel = strings.TrimSpace(cfg.ServerAddress)
		}
		if t := canonicalNetworkCidr(tunnel); t != "" && t != "0.0.0.0/0" && t != "::/0" {
			ips = append(ips, t)
		}
		for _, cidr := range split {
			ips = append(ips, cidr)
		}
		return uniqueNonEmpty(ips)
	}

	raw := cfg.ClientAllowedIPs
	if raw == "" {
		raw = "0.0.0.0/0, ::/0"
	}
	var ips []string
	for _, p := range strings.Split(raw, ",") {
		p = strings.TrimSpace(p)
		if p != "" {
			ips = append(ips, p)
		}
	}
	return ips
}

func clientAllowedIpsString(cfg *models.AwgConfig, m *models.AwgConfigPeer, enabled []models.AwgConfigPeer) string {
	return strings.Join(clientAllowedIps(cfg, m, enabled), ", ")
}

func canonicalNetworkCidr(cidr string) string {
	cidr = strings.TrimSpace(cidr)
	if cidr == "" || !strings.Contains(cidr, "/") {
		return ""
	}
	_, ipnet, err := net.ParseCIDR(cidr)
	if err != nil {
		return ""
	}
	return ipnet.String()
}

func isPeerExcluded(m, other *models.AwgConfigPeer) bool {
	for _, id := range m.ExcludedClientIDs {
		if id == other.VpnClientID {
			return true
		}
	}
	if other.ExclusionsMutual {
		for _, id := range other.ExcludedClientIDs {
			if id == m.VpnClientID {
				return true
			}
		}
	}
	return false
}

func ruleDirection(cfg *models.AwgConfig, m, other *models.AwgConfigPeer) string {
	ownID := m.VpnClientID
	otherID := other.VpnClientID
	forward, reply := false, false
	for _, rule := range cfg.VnZones().Rules {
		if containsID(rule.SrcClientIDs, ownID) && containsID(rule.DestClientIDs, otherID) {
			forward = true
		}
		if containsID(rule.DestClientIDs, ownID) && containsID(rule.SrcClientIDs, otherID) {
			reply = true
		}
	}
	if forward {
		return "forward"
	}
	if reply {
		return "reply"
	}
	return ""
}

func containsID(ids []int64, id int64) bool {
	for _, v := range ids {
		if v == id {
			return true
		}
	}
	return false
}

func uniqueNonEmpty(in []string) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, v := range in {
		v = strings.TrimSpace(v)
		if v == "" || seen[v] {
			continue
		}
		seen[v] = true
		out = append(out, v)
	}
	return out
}

func (s *Service) PeerLinks(ctx context.Context, configID *int64) []map[string]any {
	var configs []models.AwgConfig
	if configID != nil {
		c, err := s.Configs.Find(ctx, *configID)
		if err != nil || c == nil {
			return []map[string]any{}
		}
		configs = []models.AwgConfig{*c}
	} else {
		all, err := s.Configs.ListEnabled(ctx)
		if err != nil {
			return []map[string]any{}
		}
		configs = all
	}
	links := []map[string]any{}
	for i := range configs {
		cfg := &configs[i]
		if !cfg.Enabled || cfg.Type != "virtual_network" {
			continue
		}
		peers, err := s.Peers.ListEnabledByConfig(ctx, cfg.ID)
		if err != nil {
			continue
		}
		denyAll := cfg.VnPolicy == "deny_all"
		for a := 0; a < len(peers); a++ {
			for b := a + 1; b < len(peers); b++ {
				pa, pb := &peers[a], &peers[b]
				var ab, ba bool
				if denyAll {
					ab = ruleDirection(cfg, pa, pb) == "forward"
					ba = ruleDirection(cfg, pb, pa) == "forward"
				} else {
					ab = !isPeerExcluded(pa, pb)
					ba = !isPeerExcluded(pb, pa)
				}
				switch {
				case ab && ba:
					links = append(links, map[string]any{
						"config_id": cfg.ID, "from_membership_id": pa.ID, "to_membership_id": pb.ID, "bidirectional": true,
					})
				case ab:
					links = append(links, map[string]any{
						"config_id": cfg.ID, "from_membership_id": pa.ID, "to_membership_id": pb.ID, "bidirectional": false,
					})
				case ba:
					links = append(links, map[string]any{
						"config_id": cfg.ID, "from_membership_id": pb.ID, "to_membership_id": pa.ID, "bidirectional": false,
					})
				}
			}
		}
	}
	return links
}

func normalizeForwardPolicy(v string) string {
	v = strings.TrimSpace(v)
	if v == "restricted" {
		return "restricted"
	}
	return "allow_all"
}

func forwardAllowedCIDRsNonNil(in []string) []string {
	if in == nil {
		return []string{}
	}
	return in
}

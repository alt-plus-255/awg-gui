package awg

import (
	"context"
	"log"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/models"
)

type DumpStat struct {
	PublicKey            string
	Endpoint             *string
	AllowedIPs           string
	LatestHandshake      int64
	TransferRx           int64
	TransferTx           int64
	PersistentKeepalive  string
}

func parseDumpLine(line string) *DumpStat {
	line = strings.TrimSpace(line)
	if line == "" {
		return nil
	}
	parts := strings.Split(line, "\t")
	if len(parts) >= 8 {
		ep := parts[2]
		var endpoint *string
		if ep != "(none)" {
			endpoint = &ep
		}
		hs, _ := strconv.ParseInt(parts[4], 10, 64)
		rx, _ := strconv.ParseInt(parts[5], 10, 64)
		tx, _ := strconv.ParseInt(parts[6], 10, 64)
		return &DumpStat{
			PublicKey: parts[0], Endpoint: endpoint, AllowedIPs: parts[3],
			LatestHandshake: hs, TransferRx: rx, TransferTx: tx, PersistentKeepalive: parts[7],
		}
	}
	fields := strings.Fields(line)
	if len(fields) < 8 {
		return nil
	}
	n := len(fields)
	ep := fields[n-6]
	var endpoint *string
	if ep != "(none)" {
		endpoint = &ep
	}
	hs, _ := strconv.ParseInt(fields[n-5], 10, 64)
	rx, _ := strconv.ParseInt(fields[n-4], 10, 64)
	tx, _ := strconv.ParseInt(fields[n-3], 10, 64)
	return &DumpStat{
		PublicKey: fields[0], Endpoint: endpoint,
		AllowedIPs: strings.Join(fields[2:n-6], " "),
		LatestHandshake: hs, TransferRx: rx, TransferTx: tx, PersistentKeepalive: fields[n-2],
	}
}

func (s *Service) dumpStatsForIface(ctx context.Context, iface string) (bool, map[string]DumpStat) {
	byPub := map[string]DumpStat{}
	res := s.Docker.Exec(ctx, s.ContainerName(), []string{"awg", "show", iface, "dump"}, 15*time.Second, "")
	if !res.Successful() {
		log.Printf("awg stats dump failed iface=%s stderr=%s", iface, strings.TrimSpace(res.Stderr))
		return false, byPub
	}
	lines := strings.Split(strings.TrimSpace(res.Stdout), "\n")
	if len(lines) <= 1 {
		return true, byPub
	}
	for _, line := range lines[1:] {
		if parsed := parseDumpLine(line); parsed != nil {
			byPub[parsed.PublicKey] = *parsed
		}
	}
	return true, byPub
}

func (s *Service) ProbeStatsAvailable(ctx context.Context) bool {
	configs, err := s.Configs.ListEnabled(ctx)
	if err != nil || len(configs) == 0 {
		return true
	}
	ok, _ := s.dumpStatsForIface(ctx, configs[0].Iface)
	return ok
}

func (s *Service) PeerLinks(ctx context.Context, configID int64) []map[string]any {
	configs, err := s.Configs.ListEnabled(ctx)
	if err != nil {
		return nil
	}
	var links []map[string]any
	for i := range configs {
		cfg := &configs[i]
		if configID > 0 && cfg.ID != configID {
			continue
		}
		if cfg.Type != "virtual_network" {
			continue
		}
		denyAll := cfg.VnPolicy == "deny_all"
		peers, err := s.enabledPeersForConfig(ctx, cfg)
		if err != nil {
			continue
		}
		for i := 0; i < len(peers); i++ {
			for j := i + 1; j < len(peers); j++ {
				a, b := peers[i], peers[j]
				var ab, ba bool
				if denyAll {
					ab = s.ruleDirection(cfg, a, b) == "forward"
					ba = s.ruleDirection(cfg, b, a) == "forward"
				} else {
					ab = !s.isPeerExcluded(a, b)
					ba = !s.isPeerExcluded(b, a)
				}
				switch {
				case ab && ba:
					links = append(links, map[string]any{
						"config_id": cfg.ID, "from_membership_id": a.ID, "to_membership_id": b.ID, "bidirectional": true,
					})
				case ab:
					links = append(links, map[string]any{
						"config_id": cfg.ID, "from_membership_id": a.ID, "to_membership_id": b.ID, "bidirectional": false,
					})
				case ba:
					links = append(links, map[string]any{
						"config_id": cfg.ID, "from_membership_id": b.ID, "to_membership_id": a.ID, "bidirectional": false,
					})
				}
			}
		}
	}
	if links == nil {
		return []map[string]any{}
	}
	return links
}

func (s *Service) SerializePeer(ctx context.Context, cfg *models.AwgConfig, m *models.AwgConfigPeer) map[string]any {
	var handshake any
	var handshakeHuman any
	var hs int64
	if m.LatestHandshake != nil && *m.LatestHandshake > 0 {
		hs = *m.LatestHandshake
		handshake = hs
		handshakeHuman = time.Unix(hs, 0).Format(time.RFC3339)
	}
	online := m.Online
	var onlineVal any
	if online != nil {
		onlineVal = *online
	} else if hs > 0 {
		onlineVal = time.Now().Unix()-hs < OnlineWindowSec
	} else {
		onlineVal = nil
	}
	name := any(nil)
	comment := any(nil)
	if m.Client != nil {
		name = m.Client.Name
		if m.Client.Comment != nil {
			comment = *m.Client.Comment
		}
	}
	return map[string]any{
		"membership_id":        m.ID,
		"config_id":            cfg.ID,
		"config_name":          cfg.Name,
		"config_iface":         cfg.Iface,
		"config_type":          cfg.Type,
		"id":                   m.VpnClientID,
		"client_id":            m.VpnClientID,
		"vpn_client_id":        m.VpnClientID,
		"name":                 name,
		"comment":              comment,
		"enabled":              m.Enabled,
		"address":              m.Address,
		"extra_allowed_ips":    nonNilStrings(m.ExtraAllowedIPs),
		"excluded_client_ids":  nonNilInts(m.ExcludedClientIDs),
		"exclusions_mutual":    m.ExclusionsMutual,
		"forward_policy":       NormalizeForwardPolicy(m.ForwardPolicy),
		"forward_allowed_cidrs": nonNilStrings(m.ForwardAllowedCIDRs),
		"server_allowed_ips":   s.ServerPeerAllowedIPsString(m),
		"client_allowed_ips":   s.ClientAllowedIPsString(ctx, cfg, m),
		"public_key":           m.PublicKey,
		"use_preshared_key":    m.PresharedKey != nil && *m.PresharedKey != "",
		"keepalive":            m.Keepalive,
		"endpoint":             m.RuntimeEndpoint,
		"latest_handshake":     handshake,
		"latest_handshake_human": handshakeHuman,
		"transfer_rx":          m.TransferRx,
		"transfer_tx":          m.TransferTx,
		"traffic_rx_total":     m.TrafficRxTotal,
		"traffic_tx_total":     m.TrafficTxTotal,
		"traffic_reset_at":     formatTime(m.TrafficResetAt),
		"online":               onlineVal,
		"stats_synced_at":      formatTime(m.StatsSyncedAt),
		"created_at":           m.CreatedAt.Format(time.RFC3339),
		"updated_at":           m.UpdatedAt.Format(time.RFC3339),
	}
}

func (s *Service) ResetPeerTraffic(ctx context.Context, m *models.AwgConfigPeer) error {
	m.TrafficRxTotal = 0
	m.TrafficTxTotal = 0
	m.TrafficRxBaseline = m.TransferRx
	m.TrafficTxBaseline = m.TransferTx
	now := time.Now()
	m.TrafficResetAt = &now
	return s.Peers.Update(ctx, m)
}

func (s *Service) ResetConfigTraffic(ctx context.Context, cfg *models.AwgConfig) (int, error) {
	peers, err := s.Peers.ListByConfig(ctx, cfg.ID)
	if err != nil {
		return 0, err
	}
	for i := range peers {
		if err := s.ResetPeerTraffic(ctx, &peers[i]); err != nil {
			return i, err
		}
	}
	return len(peers), nil
}

const (
	HandshakeByteLimit      = 10 * 1024 * 1024
	HandshakeByteTrimTarget = 9 * 1024 * 1024
	handshakeRowOverhead    = 96
)

func (s *Service) EstimateHandshakeBytes(publicKey, endpoint string) int {
	return handshakeRowOverhead + len(publicKey) + len(endpoint)
}

func (s *Service) RecordHandshake(ctx context.Context, cfg *models.AwgConfig, m *models.AwgConfigPeer, handshakeAt int64, endpoint *string) error {
	if !cfg.HandshakeLoggingEnabled || handshakeAt <= 0 {
		return nil
	}
	ep := ""
	if endpoint != nil {
		ep = *endpoint
	}
	byteSize := s.EstimateHandshakeBytes(m.PublicKey, ep)
	peerID := m.ID
	clientID := m.VpnClientID
	logRow := &models.AwgHandshakeLog{
		AwgConfigID:     cfg.ID,
		AwgConfigPeerID: &peerID,
		VpnClientID:     &clientID,
		PublicKey:       m.PublicKey,
		Endpoint:        endpoint,
		HandshakeAt:     handshakeAt,
		ByteSize:        byteSize,
	}
	if err := s.Logs.Create(ctx, logRow); err != nil {
		return err
	}
	fresh, err := s.Configs.Find(ctx, cfg.ID)
	if err != nil || fresh == nil {
		return err
	}
	fresh.HandshakeLogBytes += int64(byteSize)
	if err := s.Configs.Update(ctx, fresh); err != nil {
		return err
	}
	*cfg = *fresh
	return s.TrimHandshakeLogs(ctx, cfg)
}

func (s *Service) TrimHandshakeLogs(ctx context.Context, cfg *models.AwgConfig) error {
	fresh, err := s.Configs.Find(ctx, cfg.ID)
	if err != nil || fresh == nil {
		return err
	}
	bytes := fresh.HandshakeLogBytes
	if bytes <= HandshakeByteLimit {
		return nil
	}
	freed := int64(0)
	for bytes-freed > HandshakeByteTrimTarget {
		batch, err := s.Logs.OldestBatch(ctx, cfg.ID, 100)
		if err != nil || len(batch) == 0 {
			break
		}
		var ids []int64
		for _, row := range batch {
			ids = append(ids, row.ID)
			freed += int64(row.ByteSize)
			if bytes-freed <= HandshakeByteTrimTarget {
				break
			}
		}
		if err := s.Logs.DeleteIDs(ctx, ids); err != nil {
			return err
		}
	}
	remaining, err := s.Logs.SumBytes(ctx, cfg.ID)
	if err != nil {
		return err
	}
	fresh.HandshakeLogBytes = remaining
	if err := s.Configs.Update(ctx, fresh); err != nil {
		return err
	}
	*cfg = *fresh
	return nil
}

func (s *Service) ClearHandshakeLogs(ctx context.Context, cfg *models.AwgConfig) error {
	if err := s.Logs.DeleteByConfig(ctx, cfg.ID); err != nil {
		return err
	}
	cfg.HandshakeLogBytes = 0
	return s.Configs.Update(ctx, cfg)
}

func (s *Service) ListHandshakeLogs(ctx context.Context, cfg *models.AwgConfig, vpnClientID, beforeID *int64, perPage int) map[string]any {
	if perPage < 1 {
		perPage = 50
	}
	if perPage > 200 {
		perPage = 200
	}
	rows, err := s.Logs.List(ctx, cfg.ID, vpnClientID, beforeID, perPage+1)
	if err != nil {
		rows = nil
	}
	hasMore := len(rows) > perPage
	if hasMore {
		rows = rows[:perPage]
	}
	logs := make([]map[string]any, 0, len(rows))
	for _, l := range rows {
		var hsHuman any
		if l.HandshakeAt > 0 {
			hsHuman = time.Unix(l.HandshakeAt, 0).Format(time.RFC3339)
		}
		logs = append(logs, map[string]any{
			"id":                 l.ID,
			"vpn_client_id":      l.VpnClientID,
			"peer_name":          l.PeerName,
			"public_key":         l.PublicKey,
			"endpoint":           l.Endpoint,
			"handshake_at":       l.HandshakeAt,
			"handshake_at_human": hsHuman,
			"created_at":         l.CreatedAt.Format(time.RFC3339),
		})
	}
	return map[string]any{
		"logs":            logs,
		"log_bytes":       cfg.HandshakeLogBytes,
		"log_bytes_limit": HandshakeByteLimit,
		"logging_enabled": cfg.HandshakeLoggingEnabled,
		"has_more":        hasMore,
	}
}

func nonNilStrings(in []string) []string {
	if in == nil {
		return []string{}
	}
	return in
}

func nonNilInts(in []int64) []int64 {
	if in == nil {
		return []int64{}
	}
	return in
}

func formatTime(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.Format(time.RFC3339)
}

package diagnostics

import (
	"context"
	"encoding/json"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/models"
)

type clashTrafficStats struct {
	TCPByTag map[string]int
	UDPByTag map[string]int
}

type fakeIPCounterStats struct {
	NatPkts int
	UDPPkts int
}

func parseClashConnections(body map[string]any) clashTrafficStats {
	out := clashTrafficStats{
		TCPByTag: map[string]int{},
		UDPByTag: map[string]int{},
	}
	if body == nil {
		return out
	}
	conns, _ := body["connections"].([]any)
	for _, raw := range conns {
		conn, ok := raw.(map[string]any)
		if !ok {
			continue
		}
		network := ""
		if meta, ok := conn["metadata"].(map[string]any); ok {
			network = strings.ToLower(strings.TrimSpace(strVal(meta["network"])))
		}
		tag := rollupConnTag(conn)
		if tag == "" {
			continue
		}
		switch network {
		case "udp":
			out.UDPByTag[tag]++
		case "tcp":
			out.TCPByTag[tag]++
		}
	}
	return out
}

func rollupConnTag(conn map[string]any) string {
	chains, _ := conn["chains"].([]any)
	for _, t := range chains {
		tag, _ := t.(string)
		if !strings.HasPrefix(tag, "conn_") {
			continue
		}
		parts := strings.Split(tag, "_")
		if len(parts) == 3 {
			if _, err := strconv.Atoi(parts[2]); err == nil {
				return parts[0] + "_" + parts[1]
			}
		}
		return tag
	}
	return ""
}

func strVal(v any) string {
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return strconv.FormatInt(int64(t), 10)
	case int:
		return strconv.Itoa(t)
	default:
		return ""
	}
}

func parseIptablesPkts(output string) (natPkts, udpPkts int) {
	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) < 2 {
			continue
		}
		n, err := strconv.Atoi(parts[0])
		if err != nil {
			continue
		}
		switch parts[1] {
		case "nat":
			natPkts += n
		case "udp":
			udpPkts += n
		}
	}
	return natPkts, udpPkts
}

func streamingRTTOK(ms int) bool {
	return ms > 0 && ms < streamingRTTThresholdMS
}

func (s *Service) clashConnections(ctx context.Context) map[string]any {
	if !s.Stats.IsContainerRunning(ctx, "") {
		return nil
	}
	curlURL := "http://127.0.0.1:9090/connections"
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{
		"curl", "-sS", "-m", "8", "-w", "___HTTP_STATUS___%{http_code}", curlURL,
	}, 12*time.Second, "")
	out := r.Stdout
	marker := "___HTTP_STATUS___"
	pos := strings.LastIndex(out, marker)
	if pos < 0 {
		return nil
	}
	rawBody := out[:pos]
	status, _ := strconv.Atoi(out[pos+len(marker):])
	if status < 200 || status >= 300 {
		return nil
	}
	var decoded map[string]any
	if json.Unmarshal([]byte(rawBody), &decoded) != nil {
		return nil
	}
	return decoded
}

func (s *Service) fakeIPCounters(ctx context.Context, ifaces []string) fakeIPCounterStats {
	stats := fakeIPCounterStats{}
	if !s.Stats.IsContainerRunning(ctx, "") || len(ifaces) == 0 {
		return stats
	}
	var parts []string
	for _, iface := range ifaces {
		if !diagIfaceRE.MatchString(iface) {
			continue
		}
		parts = append(parts, `
IFACE="`+iface+`"
NAT=$(iptables -t nat -L "RSNAT_${IFACE}" -v -x -n 2>/dev/null | awk 'NR>2 {s+=$1} END {print s+0}')
UDP=$(iptables -t mangle -L PREROUTING -v -x -n 2>/dev/null | awk -v iface="${IFACE}" '$0 ~ iface && $0 ~ /1603/ {s+=$1} END {print s+0}')
echo "${NAT} nat"
echo "${UDP} udp"`)
	}
	if len(parts) == 0 {
		return stats
	}
	script := strings.Join(parts, "\n")
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script}, 10*time.Second, "")
	stats.NatPkts, stats.UDPPkts = parseIptablesPkts(r.Stdout)
	return stats
}

func (s *Service) groupStreaming(ctx context.Context, locale string, configs []models.AwgConfig) map[string]any {
	checks := []map[string]any{}
	hints := []string{}

	var resolverConfigs []models.AwgConfig
	connIDs := map[int64]bool{}
	var resolverIfaces []string
	for _, c := range configs {
		if c.Type != "server" || !c.ResolverEnabled || !c.Enabled {
			continue
		}
		resolverConfigs = append(resolverConfigs, c)
		resolverIfaces = append(resolverIfaces, c.Iface)
		if c.ConnectionID != nil {
			connIDs[*c.ConnectionID] = true
		}
	}

	if len(resolverConfigs) == 0 {
		checks = append(checks, map[string]any{
			"id": "streaming_skipped", "ok": true,
			"label": i18n.T(locale, "system.streaming_group_title"),
			"detail": i18n.T(locale, "system.no_resolver_enabled_servers"),
		})
		return finalizeGroup("streaming", i18n.T(locale, "system.streaming_group_title"), checks, hints)
	}

	running := s.Stats.IsContainerRunning(ctx, "")
	datapath := "unknown"
	if running {
		datapath = s.awgDatapath(ctx)
	}

	datapathOK := datapath == "kernel"
	datapathDetail := datapathShortLabel(locale, datapath)
	if datapath == "userspace" {
		datapathDetail = i18n.T(locale, "system.awg_datapath_userspace")
	} else if datapath == "kernel" {
		datapathDetail = i18n.T(locale, "system.awg_datapath_kernel")
	} else {
		datapathDetail = i18n.T(locale, "system.awg_datapath_unknown")
	}
	checks = append(checks, map[string]any{
		"id": "streaming_datapath", "ok": datapathOK,
		"label": i18n.T(locale, "system.streaming_datapath_label"),
		"detail": datapathDetail,
	})
	if datapath == "userspace" {
		hints = append(hints, i18n.T(locale, "system.awg_datapath_userspace_hint"))
	}

	conns := s.loadConnections(ctx, connIDs)
	var highRTT []string
	streamingRTTOK := true
	rttDetail := "ok"
	if len(connIDs) == 0 {
		streamingRTTOK = false
		rttDetail = i18n.T(locale, "system.no_active_resolver_connections")
	} else if len(conns) == 0 {
		streamingRTTOK = false
		rttDetail = i18n.T(locale, "system.no_active_resolver_connections")
	} else {
		streamingRTTOK = true
		for _, conn := range conns {
			tag := "conn_" + strconv.FormatInt(conn.ID, 10)
			result := s.testOutboundDelay(ctx, locale, tag)
			ok, _ := result["ok"].(bool)
			if !ok {
				streamingRTTOK = false
				highRTT = append(highRTT, conn.Name+": error")
				continue
			}
			ms, _ := result["latency_ms"].(int)
			if ms >= streamingRTTThresholdMS {
				streamingRTTOK = false
				highRTT = append(highRTT, conn.Name+": "+strconv.Itoa(ms)+" ms")
				hints = append(hints, i18n.Tf(locale, "system.outbound_high_rtt_hint", map[string]string{
					"name": conn.Name,
					"ms":   strconv.Itoa(ms),
				}))
			}
		}
		if !streamingRTTOK && len(highRTT) > 0 {
			rttDetail = strings.Join(highRTT, "; ")
		}
	}
	checks = append(checks, map[string]any{
		"id": "streaming_outbound_rtt", "ok": streamingRTTOK,
		"label": i18n.T(locale, "system.streaming_rtt_label"),
		"detail": rttDetail,
	})

	blockQuicNames := []string{}
	for _, c := range resolverConfigs {
		if c.ResolverRejectQuic {
			blockQuicNames = append(blockQuicNames, c.Name)
			hints = append(hints, i18n.Tf(locale, "system.reject_quic_abr_hint", map[string]string{"name": c.Name}))
		}
	}
	blockQuicOK := len(blockQuicNames) == 0
	blockQuicDetail := "ok"
	if !blockQuicOK {
		blockQuicDetail = strings.Join(blockQuicNames, ", ")
	}
	checks = append(checks, map[string]any{
		"id": "streaming_block_quic", "ok": blockQuicOK,
		"label": i18n.T(locale, "system.streaming_block_quic_label"),
		"detail": blockQuicDetail,
	})

	upIfaces := s.listUpAwgIfaces(ctx, resolverIfaces)
	counters := s.fakeIPCounters(ctx, upIfaces)
	clashBody := s.clashConnections(ctx)
	traffic := parseClashConnections(clashBody)

	udpSessions := 0
	udpDetail := i18n.T(locale, "system.streaming_udp_clash_unavailable")
	udpOK := true
	if clashBody != nil {
		for id := range connIDs {
			tag := "conn_" + strconv.FormatInt(id, 10)
			udpSessions += traffic.UDPByTag[tag]
		}
		udpOK = udpSessions > 0
		udpDetail = i18n.Tf(locale, "system.streaming_udp_sessions_detail", map[string]string{
			"count": strconv.Itoa(udpSessions),
		})
		if !udpOK {
			udpDetail = i18n.T(locale, "system.streaming_udp_no_sessions")
			hints = append(hints, i18n.T(locale, "system.streaming_udp_no_sessions_hint"))
		}
	}
	checks = append(checks, map[string]any{
		"id": "streaming_udp_path", "ok": udpOK,
		"label": i18n.T(locale, "system.streaming_udp_path_label"),
		"detail": udpDetail,
	})

	fakeipOK := counters.NatPkts > 0 || counters.UDPPkts > 0
	fakeipDetail := i18n.Tf(locale, "system.streaming_fakeip_counters_detail", map[string]string{
		"nat": strconv.Itoa(counters.NatPkts),
		"udp": strconv.Itoa(counters.UDPPkts),
	})
	if !fakeipOK {
		fakeipDetail = i18n.T(locale, "resolver.diag_no_fakeip_traffic")
		hints = append(hints, i18n.T(locale, "resolver.diag_no_fakeip_traffic"))
	} else if counters.NatPkts > 0 && counters.UDPPkts == 0 && udpSessions == 0 {
		hints = append(hints, i18n.T(locale, "resolver.diag_rs_without_fakeip"))
	}
	checks = append(checks, map[string]any{
		"id": "streaming_fakeip_traffic", "ok": fakeipOK,
		"label": i18n.T(locale, "system.streaming_fakeip_traffic_label"),
		"detail": fakeipDetail,
	})

	return finalizeGroup("streaming", i18n.T(locale, "system.streaming_group_title"), checks, hints)
}

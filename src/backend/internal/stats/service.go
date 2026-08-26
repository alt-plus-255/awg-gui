package stats

import (
	"context"
	"log"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/docker"
	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/store"
)

const OnlineWindowSec = 180

type Service struct {
	Cfg        config.Config
	Docker     *docker.Runtime
	Configs    *store.Configs
	Peers      *store.Peers
	Clients    *store.Clients
	Handshakes *store.Handshakes
}

func New(cfg config.Config, d *docker.Runtime, configs *store.Configs, peers *store.Peers, clients *store.Clients, hs *store.Handshakes) *Service {
	return &Service{Cfg: cfg, Docker: d, Configs: configs, Peers: peers, Clients: clients, Handshakes: hs}
}

func (s *Service) ContainerName() string {
	if s != nil && s.Cfg.AWGContainer != "" {
		return s.Cfg.AWGContainer
	}
	return "awggui-awg"
}

func (s *Service) ConfigDir() string {
	if s != nil && s.Cfg.AWGConfigDir != "" {
		return s.Cfg.AWGConfigDir
	}
	return "/awg"
}

func (s *Service) IsContainerRunning(ctx context.Context, name string) bool {
	if name == "" {
		name = s.ContainerName()
	}
	if s.Docker == nil {
		return false
	}
	return s.Docker.ContainerRunning(ctx, name)
}

func (s *Service) ProbeStatsAvailable(ctx context.Context) bool {
	cfg, err := s.Configs.FirstEnabled(ctx)
	if err != nil || cfg == nil {
		return true
	}
	return s.dumpStatsForIface(ctx, cfg.Iface).Available
}

type DumpStat struct {
	PublicKey    string
	Endpoint     *string
	LatestHS     int64
	TransferRx   int64
	TransferTx   int64
}

type DumpResult struct {
	Available bool
	ByPub     map[string]DumpStat
}

type LiveResult struct {
	StatsAvailable bool
	ByPublicKey    map[string]map[string]any
}

type RefreshResult struct {
	StatsAvailable bool
	SyncedAt       string
	Peers          []map[string]any
	ByPublicKey    map[string]map[string]any
}

func (s *Service) dumpStatsForIface(ctx context.Context, iface string) DumpResult {
	out := DumpResult{Available: false, ByPub: map[string]DumpStat{}}
	if s.Docker == nil {
		return out
	}
	r := s.Docker.Exec(ctx, s.ContainerName(), []string{"awg", "show", iface, "dump"}, 15*time.Second, "")
	if !r.Successful() {
		log.Printf("awg stats dump failed iface=%s stderr=%s", iface, strings.TrimSpace(r.Stderr))
		return out
	}
	out.Available = true
	lines := splitLines(strings.TrimSpace(r.Stdout))
	if len(lines) <= 1 {
		return out
	}
	for _, line := range lines[1:] {
		parsed := parseDumpLine(line)
		if parsed == nil {
			continue
		}
		out.ByPub[parsed.PublicKey] = *parsed
	}
	return out
}

func parseDumpLine(line string) *DumpStat {
	line = strings.TrimSpace(line)
	if line == "" {
		return nil
	}
	parts := strings.Split(line, "\t")
	if len(parts) >= 8 {
		return dumpFromParts(parts[0], parts[2], parts[4], parts[5], parts[6])
	}
	fields := strings.Fields(line)
	if len(fields) < 8 {
		return nil
	}
	n := len(fields)
	return dumpFromParts(fields[0], fields[n-6], fields[n-5], fields[n-4], fields[n-3])
}

func dumpFromParts(pub, endpoint, hs, rx, tx string) *DumpStat {
	st := &DumpStat{PublicKey: pub, TransferRx: atoi64(rx), TransferTx: atoi64(tx), LatestHS: atoi64(hs)}
	if endpoint != "" && endpoint != "(none)" {
		e := endpoint
		st.Endpoint = &e
	}
	return st
}

func (s *Service) LivePeerStats(ctx context.Context, configIDs []int64) LiveResult {
	configs := s.enabledConfigs(ctx, configIDs)
	statsAvailable := true
	byPublicKey := map[string]map[string]any{}
	now := time.Now().Unix()

	for _, cfg := range configs {
		dump := s.dumpStatsForIface(ctx, cfg.Iface)
		if !dump.Available {
			statsAvailable = false
		}
		for pub, st := range dump.ByPub {
			hs := st.LatestHS
			online := hs > 0 && (now-hs) < OnlineWindowSec
			var hsVal any
			var hsHuman any
			if hs > 0 {
				hsVal = hs
				hsHuman = time.Unix(hs, 0).Format(time.RFC3339)
			}
			byPublicKey[pub] = map[string]any{
				"endpoint":               st.Endpoint,
				"latest_handshake":       hsVal,
				"latest_handshake_human": hsHuman,
				"transfer_rx":            st.TransferRx,
				"transfer_tx":            st.TransferTx,
				"online":                 online,
			}
		}
	}
	return LiveResult{StatsAvailable: statsAvailable, ByPublicKey: byPublicKey}
}

func (s *Service) enabledConfigs(ctx context.Context, configIDs []int64) []models.AwgConfig {
	all, err := s.Configs.ListEnabled(ctx)
	if err != nil {
		return nil
	}
	if configIDs == nil {
		return all
	}
	want := map[int64]bool{}
	for _, id := range configIDs {
		if id > 0 {
			want[id] = true
		}
	}
	var out []models.AwgConfig
	for _, c := range all {
		if want[c.ID] {
			out = append(out, c)
		}
	}
	return out
}

func (s *Service) memberships(ctx context.Context, configIDs []int64) []models.AwgConfigPeer {
	if s.Peers == nil {
		return nil
	}
	if configIDs == nil {
		rows, err := s.Peers.ListAll(ctx)
		if err != nil {
			return nil
		}
		return rows
	}
	rows, err := s.Peers.ListByConfigIDs(ctx, configIDs)
	if err != nil {
		return nil
	}
	return rows
}

func (s *Service) EnrichLiveWithTotals(ctx context.Context, live LiveResult, configIDs []int64) LiveResult {
	rows := s.memberships(ctx, configIDs)
	for i := range rows {
		m := &rows[i]
		if m.PublicKey == "" {
			continue
		}
		entry, ok := live.ByPublicKey[m.PublicKey]
		if !ok {
			continue
		}
		entry["traffic_rx_total"] = m.TrafficRxTotal
		entry["traffic_tx_total"] = m.TrafficTxTotal
		entry["traffic_reset_at"] = isoPtr(m.TrafficResetAt)
	}
	return live
}

func (s *Service) RefreshFromDocker(ctx context.Context, configIDs []int64) RefreshResult {
	live := s.LivePeerStats(ctx, configIDs)
	now := time.Now()
	rows := s.memberships(ctx, configIDs)

	byPublicKey := map[string]map[string]any{}
	peersLite := make([]map[string]any, 0, len(rows))

	for i := range rows {
		m := &rows[i]
		var stat map[string]any
		if live.ByPublicKey != nil {
			stat = live.ByPublicKey[m.PublicKey]
		}
		s.applyStatsToMembership(ctx, m, stat, now)
		if m.PublicKey == "" {
			continue
		}
		entry := s.liteStatsEntry(m)
		byPublicKey[m.PublicKey] = entry
		peersLite = append(peersLite, entry)
	}

	return RefreshResult{
		StatsAvailable: live.StatsAvailable,
		SyncedAt:       now.Format(time.RFC3339),
		Peers:          peersLite,
		ByPublicKey:    byPublicKey,
	}
}

func AccumulateDelta(current, baseline int64) (delta, newBaseline int64) {
	if current >= baseline {
		return current - baseline, current
	}
	return current, current
}

func (s *Service) applyStatsToMembership(ctx context.Context, m *models.AwgConfigPeer, stat map[string]any, now time.Time) {
	if stat == nil {
		m.RuntimeEndpoint = nil
		m.LatestHandshake = nil
		m.TransferRx = 0
		m.TransferTx = 0
		off := false
		m.Online = &off
		m.StatsSyncedAt = &now
		_ = s.Peers.Update(ctx, m)
		return
	}

	var previous int64
	if m.LatestHandshake != nil {
		previous = *m.LatestHandshake
	}
	handshake := toInt64(stat["latest_handshake"])
	online := toBool(stat["online"])
	if _, ok := stat["online"]; !ok {
		online = handshake > 0 && (time.Now().Unix()-handshake) < OnlineWindowSec
	}
	rx := toInt64(stat["transfer_rx"])
	tx := toInt64(stat["transfer_tx"])
	rxDelta, rxBase := AccumulateDelta(rx, m.TrafficRxBaseline)
	txDelta, txBase := AccumulateDelta(tx, m.TrafficTxBaseline)
	m.TrafficRxTotal += rxDelta
	m.TrafficTxTotal += txDelta
	m.TrafficRxBaseline = rxBase
	m.TrafficTxBaseline = txBase

	m.RuntimeEndpoint = toStringPtr(stat["endpoint"])
	if handshake > 0 {
		hs := handshake
		m.LatestHandshake = &hs
	} else {
		m.LatestHandshake = nil
	}
	m.TransferRx = rx
	m.TransferTx = tx
	m.Online = &online
	m.StatsSyncedAt = &now
	_ = s.Peers.Update(ctx, m)

	if handshake > previous {
		cfg := s.configByID(ctx, m.AwgConfigID)
		if cfg != nil {
			s.recordHandshake(ctx, cfg, m, handshake, m.RuntimeEndpoint)
		}
	}
}

func (s *Service) liteStatsEntry(m *models.AwgConfigPeer) map[string]any {
	var hs any
	var hsHuman any
	if m.LatestHandshake != nil && *m.LatestHandshake > 0 {
		hs = *m.LatestHandshake
		hsHuman = time.Unix(*m.LatestHandshake, 0).Format(time.RFC3339)
	}
	online := false
	if m.Online != nil {
		online = *m.Online
	}
	return map[string]any{
		"config_id":              m.AwgConfigID,
		"public_key":             m.PublicKey,
		"endpoint":               m.RuntimeEndpoint,
		"latest_handshake":       hs,
		"latest_handshake_human": hsHuman,
		"transfer_rx":            m.TransferRx,
		"transfer_tx":            m.TransferTx,
		"traffic_rx_total":       m.TrafficRxTotal,
		"traffic_tx_total":       m.TrafficTxTotal,
		"traffic_reset_at":       isoPtr(m.TrafficResetAt),
		"online":                 online,
	}
}

func (s *Service) configByID(ctx context.Context, id int64) *models.AwgConfig {
	c, err := s.Configs.Find(ctx, id)
	if err != nil {
		return nil
	}
	return c
}

func (s *Service) RunEveryMinute(ctx context.Context) {
	ticker := time.NewTicker(time.Minute)
	defer ticker.Stop()
	s.RefreshFromDocker(ctx, nil)
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			s.RefreshFromDocker(ctx, nil)
		}
	}
}

func (s *Service) AwgShowAvailable(ctx context.Context, iface string) bool {
	ok, _ := s.AwgShowProbe(ctx, iface)
	return ok
}

// AwgShowProbe runs awg show, falling back to awg show dump (same as stats).
// detail is a short stderr/exit summary when unsuccessful.
func (s *Service) AwgShowProbe(ctx context.Context, iface string) (ok bool, detail string) {
	if s.Docker == nil {
		return false, "no docker runtime"
	}
	if !ifaceRE.MatchString(iface) {
		return false, "invalid iface"
	}
	r := s.Docker.Exec(ctx, s.ContainerName(), []string{"awg", "show", iface}, 8*time.Second, "")
	if r.Successful() {
		return true, ""
	}
	// Some builds / datapaths accept dump more reliably than plain show.
	r2 := s.Docker.Exec(ctx, s.ContainerName(), []string{"awg", "show", iface, "dump"}, 8*time.Second, "")
	if r2.Successful() {
		return true, "via dump"
	}
	errMsg := strings.TrimSpace(r.Stderr)
	if errMsg == "" {
		errMsg = strings.TrimSpace(r2.Stderr)
	}
	if errMsg == "" {
		errMsg = "exit " + strconv.Itoa(r.ExitCode)
	}
	if len(errMsg) > 120 {
		errMsg = errMsg[:120] + "…"
	}
	return false, errMsg
}

func (s *Service) IfaceIsUp(ctx context.Context, iface string) bool {
	if !ifaceRE.MatchString(iface) || s.Docker == nil {
		return false
	}
	r := s.Docker.Exec(ctx, s.ContainerName(), []string{"sh", "-c", "ip link show " + iface + " >/dev/null 2>&1 && echo yes || echo no"}, 8*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

// KernelModuleLoaded reports whether amneziawg is visible inside the AWG container.
func (s *Service) KernelModuleLoaded(ctx context.Context) bool {
	if s.Docker == nil || !s.IsContainerRunning(ctx, "") {
		return false
	}
	r := s.Docker.Exec(ctx, s.ContainerName(), []string{"sh", "-c", "test -d /sys/module/amneziawg && echo yes || echo no"}, 5*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

// ConfFileExists checks the shared AWG config volume for iface.conf (app mount).
func (s *Service) ConfFileExists(iface string) bool {
	if !ifaceRE.MatchString(iface) {
		return false
	}
	path := s.ConfigDir() + "/" + iface + ".conf"
	_, err := os.Stat(path)
	return err == nil
}

var ifaceRE = regexp.MustCompile(`^[A-Za-z0-9_]+$`)

func splitLines(s string) []string {
	s = strings.ReplaceAll(s, "\r\n", "\n")
	s = strings.ReplaceAll(s, "\r", "\n")
	return strings.Split(s, "\n")
}

func atoi64(s string) int64 {
	n, _ := strconv.ParseInt(strings.TrimSpace(s), 10, 64)
	return n
}

func toInt64(v any) int64 {
	switch t := v.(type) {
	case int:
		return int64(t)
	case int64:
		return t
	case float64:
		return int64(t)
	case string:
		return atoi64(t)
	default:
		return 0
	}
}

func toBool(v any) bool {
	switch t := v.(type) {
	case bool:
		return t
	default:
		return false
	}
}

func toStringPtr(v any) *string {
	switch t := v.(type) {
	case nil:
		return nil
	case string:
		if t == "" {
			return nil
		}
		return &t
	case *string:
		return t
	default:
		return nil
	}
}

func isoPtr(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.Format(time.RFC3339)
}

func isoTime(t time.Time) string {
	if t.IsZero() {
		return ""
	}
	return t.Format(time.RFC3339)
}

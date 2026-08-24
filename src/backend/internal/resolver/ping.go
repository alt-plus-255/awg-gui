package resolver

import (
	"context"
	"encoding/json"
	"log"
	"strings"
	"sync"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

const (
	sessionLockKey    = "ping_probe:session_lock"
	sessionActiveKey  = "ping_probe:session_active"
	sessionCancelKey  = "ping_probe:session_cancel"
	sessionMetaKey    = "ping_probe:session_meta"
	lastActivityKey   = "ping_probe:last_activity"
	pendingReloadKey  = "ping_probe:pending_reload"
	pingDefaultTOMS   = 6000
	pingFastTCPSec    = 2 * time.Second
	pingCacheTTL      = 12 * time.Minute
	pingSessionTTL    = 600 * time.Second
)

type ProbeManager struct {
	Svc *Service
}

func (p *ProbeManager) cache() *MemCache { return p.Svc.Cache }

func (p *ProbeManager) Touch() {
	p.cache().Put(lastActivityKey, time.Now().Unix(), time.Duration(PingIdleTimeoutSec+120)*time.Second)
}

func (p *ProbeManager) lastActivityAt() *int64 {
	v, ok := p.cache().Get(lastActivityKey)
	if !ok {
		return nil
	}
	switch t := v.(type) {
	case int64:
		return &t
	case int:
		n := int64(t)
		return &n
	}
	return nil
}

func (p *ProbeManager) IsRunning(ctx context.Context) bool {
	r, err := p.Svc.Docker.Exec(ctx, p.Svc.Cfg.AWGContainer, []string{"sh", "-c",
		`test -f /run/sing-box-ping.pid && kill -0 "$(cat /run/sing-box-ping.pid)" 2>/dev/null`}, 5*time.Second)
	if err != nil {
		return false
	}
	return r.Successful()
}

func (p *ProbeManager) EnsureStarted(ctx context.Context) error {
	if !fileExists(p.Svc.Paths.SingBoxPingConfigPath()) {
		return runtimeKey("resolver.singbox_ping_json_missing")
	}
	if p.IsRunning(ctx) && p.Svc.Clash.WaitForProbeAPI(ctx, 3, 150*time.Millisecond) {
		p.Touch()
		return nil
	}
	if err := p.runScript(ctx, "start"); err != nil {
		return err
	}
	if !p.Svc.Clash.WaitForProbeAPI(ctx, 40, 200*time.Millisecond) {
		return runtimeKey("resolver.singbox_probe_start_failed")
	}
	p.Touch()
	return nil
}

func (p *ProbeManager) ReloadIfRunning(ctx context.Context) {
	if !p.IsRunning(ctx) {
		return
	}
	if p.cache().Has(sessionActiveKey) {
		p.cache().Put(pendingReloadKey, true, 600*time.Second)
		return
	}
	_ = p.runScript(ctx, "reload")
	p.Svc.Clash.WaitForProbeAPI(ctx, 25, 200*time.Millisecond)
}

func (p *ProbeManager) ApplyPendingReload(ctx context.Context) {
	if _, ok := p.cache().Pull(pendingReloadKey); !ok {
		return
	}
	p.ReloadIfRunning(ctx)
}

func (p *ProbeManager) Stop(ctx context.Context) {
	if !p.IsRunning(ctx) {
		return
	}
	_ = p.runScript(ctx, "stop")
}

func (p *ProbeManager) ForceRestart(ctx context.Context) error {
	p.Stop(ctx)
	return p.EnsureStarted(ctx)
}

func (p *ProbeManager) StopIfIdle(ctx context.Context) {
	if !p.IsRunning(ctx) {
		return
	}
	if p.cache().Has(sessionActiveKey) {
		return
	}
	last := p.lastActivityAt()
	if last == nil {
		p.Touch()
		return
	}
	if time.Now().Unix()-*last >= PingIdleTimeoutSec {
		log.Printf("sing-box-ping: idle timeout, stopping probe")
		p.Stop(ctx)
	}
}

func (p *ProbeManager) RebuildAndMaybeReload(ctx context.Context) map[string]any {
	p.Svc.Scripts.EnsurePingProbeScript()
	conns, _ := p.Svc.Store.EnabledConnections(ctx)
	built := p.Svc.Builder.BuildForConnections(conns)
	cfg := map[string]any{
		"log": map[string]any{"level": "warn", "timestamp": true},
		"dns": map[string]any{
			"servers": []map[string]any{{"type": "udp", "tag": "bootstrap", "server": "8.8.8.8", "server_port": 53}},
			"final":   "bootstrap", "strategy": "ipv4_only",
		},
		"outbounds": built.Outbounds,
		"route": map[string]any{
			"auto_detect_interface":   false,
			"default_interface":       p.Svc.Egress.Resolve(ctx),
			"default_domain_resolver": "bootstrap",
		},
		"experimental": map[string]any{
			"clash_api": map[string]any{"external_controller": ClashProbeAPIAddr, "default_mode": "rule"},
		},
	}
	js, err := json.Marshal(cfg)
	if err != nil {
		log.Printf("sing-box-ping serialize: %v", err)
		return map[string]any{"written": false, "outbound_count": len(built.Outbounds), "bytes": 0}
	}
	contents := string(js) + "\n"
	written, _ := p.Svc.Files.AtomicWriteIfChanged(p.Svc.Paths.SingBoxPingConfigPath(), contents)
	if written {
		p.ReloadIfRunning(ctx)
	}
	return map[string]any{"written": written, "outbound_count": len(built.Outbounds), "bytes": len(contents)}
}

func (p *ProbeManager) runScript(ctx context.Context, action string) error {
	r, err := p.Svc.Docker.Exec(ctx, p.Svc.Cfg.AWGContainer, []string{"/config/reload-singbox-ping.sh", action}, 30*time.Second)
	if err != nil {
		return err
	}
	if !r.Successful() {
		msg := strings.TrimSpace(r.Stderr + "\n" + r.Stdout)
		if msg == "" {
			msg = "sing-box-ping script " + action + " failed"
		}
		return runtimeKeyParams("resolver.check_error", map[string]string{"errors": msg})
	}
	return nil
}

type PingService struct {
	Svc *Service
}

func (p *PingService) cache() *MemCache { return p.Svc.Cache }
func (p *PingService) probe() *ProbeManager { return p.Svc.Probe }

func (p *PingService) PingNodes(ctx context.Context, conn *Connection, onResult func(PingResult), fastOnly bool) ([]PingResult, error) {
	pairs := p.Svc.Builder.PingableNodes(conn)
	if len(pairs) == 0 {
		return nil, runtimeKey("resolver.no_nodes_for_ping")
	}
	keyToTag := map[string]string{}
	for _, pair := range pairs {
		keyToTag[pair["key"]] = pair["tag"]
	}
	locale := Locale(ctx)
	phase := i18n.T(locale, "resolver.phase_ping")
	if fastOnly {
		phase = i18n.T(locale, "resolver.phase_fast_ping")
	}
	return p.runSession(ctx, conn, map[string]any{
		"kind": "subscription", "total": len(pairs), "phase": phase,
	}, func() ([]PingResult, error) {
		return p.pingByTags(ctx, conn, keyToTag, onResult, fastOnly)
	})
}

func (p *PingService) PingNode(ctx context.Context, conn *Connection, nodeKey string, fastOnly bool) (PingResult, error) {
	tag := p.Svc.Builder.ResolveNodeTag(conn, nodeKey)
	if tag == nil {
		return PingResult{Key: nodeKey, Error: ptrStr(i18n.T(Locale(ctx), "resolver.node_not_in_probe"))}, nil
	}
	results, err := p.runSession(ctx, conn, map[string]any{
		"kind": "node", "total": 1, "phase": i18n.T(Locale(ctx), "resolver.phase_ping_node"),
	}, func() ([]PingResult, error) {
		return p.pingByTags(ctx, conn, map[string]string{nodeKey: *tag}, nil, fastOnly)
	})
	if err != nil {
		return PingResult{}, err
	}
	if len(results) == 0 {
		return PingResult{Key: nodeKey, Error: ptrStr(i18n.T(Locale(ctx), "resolver.not_checked"))}, nil
	}
	return results[0], nil
}

func (p *PingService) PingConnection(ctx context.Context, conn *Connection, fastOnly bool) (map[string]any, error) {
	tag := conn.OutboundTag()
	kind := "proxy"
	if conn.IsSubscription() {
		kind = "subscription"
	}
	results, err := p.runSession(ctx, conn, map[string]any{
		"kind": kind, "total": 1, "phase": i18n.T(Locale(ctx), "resolver.phase_checking"),
	}, func() ([]PingResult, error) {
		return p.pingByTags(ctx, conn, map[string]string{"__conn": tag}, nil, fastOnly)
	})
	if err != nil {
		return nil, err
	}
	if len(results) == 0 {
		return map[string]any{"ok": false, "latency_ms": nil, "error": i18n.T(Locale(ctx), "resolver.not_checked")}, nil
	}
	r := results[0]
	src := r.Source
	if src == "" {
		src = "proxy"
	}
	return map[string]any{"ok": r.OK, "latency_ms": intPtr(r.LatencyMS), "error": r.Error, "source": src}, nil
}

func (p *PingService) runSession(ctx context.Context, conn *Connection, meta map[string]any, fn func() ([]PingResult, error)) ([]PingResult, error) {
	if !p.cache().TryPut(sessionLockKey, true, pingSessionTTL) {
		return nil, BusyErr(Locale(ctx))
	}
	defer p.cache().Forget(sessionLockKey)
	p.cache().Put(sessionActiveKey, true, pingSessionTTL)
	defer p.cache().Forget(sessionActiveKey)
	defer p.cache().Forget(sessionMetaKey)
	defer p.cache().Forget(sessionCancelKey)

	kind := strVal(meta["kind"])
	if kind == "" && conn != nil {
		if conn.IsSubscription() {
			kind = "subscription"
		} else {
			kind = "proxy"
		}
	}
	var connID any
	var connName any
	if conn != nil {
		connID = conn.ID
		connName = conn.Name
	}
	p.writeMeta(map[string]any{
		"connection_id": connID, "connection_name": connName, "kind": kind,
		"total": meta["total"], "tested": 0,
		"phase": first(strVal(meta["phase"]), i18n.T(Locale(ctx), "resolver.phase_preparing")),
		"started_at": isoNow(), "source": "ui",
	})
	p.probe().RebuildAndMaybeReload(ctx)
	if err := p.probe().EnsureStarted(ctx); err != nil {
		return nil, err
	}
	p.probe().Touch()
	out, err := fn()
	p.probe().Touch()
	p.probe().ApplyPendingReload(ctx)
	if conn != nil {
		p.TouchPingCheckedAt(ctx, conn)
	}
	return out, err
}

func (p *PingService) writeMeta(patch map[string]any) {
	cur := map[string]any{}
	if v, ok := p.cache().Get(sessionMetaKey); ok {
		if m, ok := v.(map[string]any); ok {
			for k, val := range m {
				cur[k] = val
			}
		}
	}
	for k, v := range patch {
		cur[k] = v
	}
	p.cache().Put(sessionMetaKey, cur, pingSessionTTL)
}

func (p *PingService) bumpProgress() {
	cur := map[string]any{}
	if v, ok := p.cache().Get(sessionMetaKey); ok {
		if m, ok := v.(map[string]any); ok {
			cur = m
		}
	}
	cur["tested"] = atoiDef(strVal(cur["tested"]), 0) + 1
	p.cache().Put(sessionMetaKey, cur, pingSessionTTL)
}

func (p *PingService) cancelled() bool {
	v, ok := p.cache().Get(sessionCancelKey)
	if !ok {
		return false
	}
	switch t := v.(type) {
	case bool:
		return t
	default:
		return true
	}
}

func (p *PingService) pingByTags(ctx context.Context, conn *Connection, keyToTag map[string]string, onResult func(PingResult), fastOnly bool) ([]PingResult, error) {
	locale := Locale(ctx)
	keyToOutbound := map[string]map[string]any{}
	nodeByKey := map[string]map[string]any{}
	for _, n := range conn.SubscriptionNodes {
		if n == nil || strVal(n["key"]) == "" {
			continue
		}
		nodeByKey[strVal(n["key"])] = n
	}
	for key := range keyToTag {
		if key == "__conn" {
			keyToOutbound[key] = conn.Outbound
			continue
		}
		if n, ok := nodeByKey[key]; ok {
			if ob, ok := n["outbound"].(map[string]any); ok {
				keyToOutbound[key] = ob
				continue
			}
		}
		keyToOutbound[key] = map[string]any{}
	}
	phase := i18n.T(locale, "resolver.phase_ping")
	if fastOnly {
		phase = i18n.T(locale, "resolver.phase_fast_ping")
	}
	p.writeMeta(map[string]any{"total": len(keyToTag), "tested": 0, "phase": phase})

	toDelay := map[string]string{}
	for k, t := range keyToTag {
		toDelay[k] = t
	}
	results := map[string]PingResult{}
	var mu sync.Mutex
	emit := func(r PingResult) {
		mu.Lock()
		results[r.Key] = r
		mu.Unlock()
		p.bumpProgress()
		if onResult != nil {
			onResult(r)
		}
	}
	if p.cancelled() {
		return nil, runtimeKey("resolver.ping_cancelled")
	}
	TCPProbe{}.CheckManyStreaming(keyToOutbound, pingFastTCPSec, func(key string, reachable bool) {
		if reachable {
			if fastOnly {
				emit(PingResult{Key: key, OK: true, Source: "tcp"})
				mu.Lock()
				delete(toDelay, key)
				mu.Unlock()
			}
			return
		}
		msg := i18n.T(locale, "resolver.tcp_unavailable")
		emit(PingResult{Key: key, Error: &msg, Source: "tcp"})
		mu.Lock()
		delete(toDelay, key)
		mu.Unlock()
	}, p.cancelled)
	if p.cancelled() {
		return nil, runtimeKey("resolver.ping_cancelled")
	}
	if fastOnly {
		p.persistCache(ctx, conn, results)
		return sortPingResults(mapValues(results)), nil
	}
	mu.Lock()
	remaining := cloneStrMap(toDelay)
	mu.Unlock()
	if len(remaining) > 0 {
		p.Svc.Clash.TestOutboundDelaysStreaming(ctx, remaining, pingDefaultTOMS, true, func(key string, d DelayResult) {
			emit(PingResult{Key: key, LatencyMS: d.LatencyMS, OK: d.OK, Error: d.Error, Source: "proxy"})
		}, p.cancelled)
	}
	if p.cancelled() {
		return nil, runtimeKey("resolver.ping_cancelled")
	}
	p.persistCache(ctx, conn, results)
	return sortPingResults(mapValues(results)), nil
}

func cloneStrMap(m map[string]string) map[string]string {
	out := make(map[string]string, len(m))
	for k, v := range m {
		out[k] = v
	}
	return out
}

func mapValues(m map[string]PingResult) []PingResult {
	out := make([]PingResult, 0, len(m))
	for _, v := range m {
		out = append(out, v)
	}
	return out
}

func sortPingResults(results []PingResult) []PingResult {
	tier := func(n PingResult) int {
		if n.OK {
			return 0
		}
		if n.Error != nil {
			return 2
		}
		return 1
	}
	for i := 0; i < len(results); i++ {
		for j := i + 1; j < len(results); j++ {
			ti, tj := tier(results[i]), tier(results[j])
			swap := false
			if ti != tj {
				swap = ti > tj
			} else if ti == 0 {
				ai, bi := 1<<30, 1<<30
				if results[i].LatencyMS != nil {
					ai = *results[i].LatencyMS
				}
				if results[j].LatencyMS != nil {
					bi = *results[j].LatencyMS
				}
				swap = ai > bi
			} else {
				swap = results[i].Key > results[j].Key
			}
			if swap {
				results[i], results[j] = results[j], results[i]
			}
		}
	}
	return results
}

func (p *PingService) persistCache(ctx context.Context, conn *Connection, results map[string]PingResult) {
	if len(results) == 0 {
		return
	}
	existing := conn.LatencyCache
	if existing == nil {
		existing = map[string]any{}
	}
	nodes, _ := existing["nodes"].(map[string]any)
	if nodes == nil {
		nodes = map[string]any{}
	}
	now := isoNow()
	for key, r := range results {
		src := r.Source
		if src == "" {
			src = "proxy"
		}
		nodes[key] = map[string]any{
			"latency_ms": intPtr(r.LatencyMS), "latency_ok": r.OK,
			"latency_error": r.Error, "source": src, "tested_at": now,
		}
	}
	conn.LatencyCache = map[string]any{"nodes": nodes, "updated_at": now}
	_ = p.Svc.Store.UpdateConnection(ctx, conn)
}

func (p *PingService) TouchPingCheckedAt(ctx context.Context, conn *Connection) {
	now := time.Now()
	conn.PingLastCheckedAt = &now
	_ = p.Svc.Store.UpdateConnection(ctx, conn)
}

func (p *PingService) ReadCachedLatencies(conn *Connection) map[string]map[string]any {
	if conn.LatencyCache == nil {
		return nil
	}
	raw, _ := conn.LatencyCache["nodes"].(map[string]any)
	if raw == nil {
		return nil
	}
	out := map[string]map[string]any{}
	ttl := pingCacheTTL.Seconds()
	now := time.Now()
	for key, entry := range raw {
		m, ok := entry.(map[string]any)
		if !ok {
			continue
		}
		testedAt := strVal(m["tested_at"])
		t, err := time.Parse(time.RFC3339, testedAt)
		if err != nil {
			t, err = time.Parse(time.RFC3339Nano, testedAt)
		}
		if err != nil || now.Sub(t).Seconds() > ttl {
			continue
		}
		out[key] = map[string]any{
			"latency_ms": m["latency_ms"], "latency_ok": m["latency_ok"],
			"latency_error": m["latency_error"], "source": first(strVal(m["source"]), "cached"),
			"tested_at": testedAt,
		}
	}
	if len(out) == 0 {
		return nil
	}
	return out
}

func (p *PingService) BestPingFromCache(conn *Connection) map[string]any {
	cached := p.ReadCachedLatencies(conn)
	if cached == nil {
		return nil
	}
	nameByKey := map[string]string{}
	for _, n := range conn.SubscriptionNodes {
		if n == nil || strVal(n["key"]) == "" {
			continue
		}
		nameByKey[strVal(n["key"])] = first(strVal(n["name"]), strVal(n["key"]))
	}
	var best map[string]any
	bestMS := 1 << 30
	for key, entry := range cached {
		ok := false
		switch v := entry["latency_ok"].(type) {
		case bool:
			ok = v
		case float64:
			ok = v != 0
		}
		if !ok {
			continue
		}
		ms := atoiDef(strVal(entry["latency_ms"]), -1)
		if ms < 0 {
			continue
		}
		if best == nil || ms < bestMS {
			bestMS = ms
			best = map[string]any{
				"key": key, "name": first(nameByKey[key], key), "latency_ms": ms,
				"source": first(strVal(entry["source"]), "ping_cache"),
			}
		}
	}
	return best
}

func (p *PingService) PersistActivePick(ctx context.Context, conn *Connection, pick map[string]any, source string) {
	key := strVal(pick["key"])
	if key == "" {
		return
	}
	var lat any
	if v := pick["latency_ms"]; v != nil && strVal(v) != "" {
		lat = atoiDef(strVal(v), 0)
	}
	conn.SubscriptionActive = map[string]any{
		"key": key, "name": first(strVal(pick["name"]), key),
		"latency_ms": lat, "source": source, "updated_at": isoNow(),
	}
	_ = p.Svc.Store.UpdateConnection(ctx, conn)
}

func (p *PingService) ReadPersistedActivePick(conn *Connection) map[string]any {
	if conn.SubscriptionActive == nil || strVal(conn.SubscriptionActive["key"]) == "" {
		return nil
	}
	d := conn.SubscriptionActive
	var lat any
	if d["latency_ms"] != nil {
		lat = atoiDef(strVal(d["latency_ms"]), 0)
	}
	return map[string]any{
		"key": strVal(d["key"]), "name": first(strVal(d["name"]), strVal(d["key"])),
		"latency_ms": lat, "source": first(strVal(d["source"]), "cached"),
	}
}

func (p *PingService) ResolveURLTestActivePick(ctx context.Context, conn *Connection) map[string]any {
	if !conn.IsURLTestMode() || !conn.Enabled {
		return nil
	}
	tag := p.Svc.RoutingOutboundTag(ctx, conn)
	node := conn.NodeForChildTag(tag)
	if node == nil || strVal(node["key"]) == "" {
		return nil
	}
	key := strVal(node["key"])
	var latencyMS any
	resp := p.Svc.Clash.Request(ctx, "/proxies/"+tag, nil, 3*time.Second, false)
	if resp.OK && resp.Body != nil {
		if hist, ok := resp.Body["history"].([]any); ok && len(hist) > 0 {
			last := hist[len(hist)-1]
			if n := atoiDef(strVal(last), 0); n > 0 {
				latencyMS = n
			}
		}
	}
	if latencyMS == nil {
		if cached := p.ReadCachedLatencies(conn); cached != nil {
			if e := cached[key]; e != nil {
				latencyMS = e["latency_ms"]
			}
		}
	}
	return map[string]any{"key": key, "name": first(strVal(node["name"]), key), "latency_ms": latencyMS}
}

func (p *PingService) SyncActivePickAfterPing(ctx context.Context, conn *Connection) {
	fresh, _ := p.Svc.Store.GetConnection(ctx, conn.ID)
	if fresh == nil || !fresh.IsSubscription() {
		return
	}
	if fresh.SubscriptionMode != nil && *fresh.SubscriptionMode == ModeSingle {
		key := strPtrVal(fresh.SubscriptionSelected)
		if key == "" {
			return
		}
		name := key
		for _, n := range fresh.SubscriptionNodes {
			if strVal(n["key"]) == key {
				name = first(strVal(n["name"]), key)
				break
			}
		}
		var lat any
		if cached := p.ReadCachedLatencies(fresh); cached != nil {
			if e := cached[key]; e != nil {
				lat = e["latency_ms"]
			}
		}
		p.PersistActivePick(ctx, fresh, map[string]any{"key": key, "name": name, "latency_ms": lat}, "user")
		return
	}
	if urltest := p.ResolveURLTestActivePick(ctx, fresh); urltest != nil {
		p.PersistActivePick(ctx, fresh, urltest, "urltest")
		return
	}
	if best := p.BestPingFromCache(fresh); best != nil {
		p.PersistActivePick(ctx, fresh, best, "ping")
	}
}

func (p *PingService) ApplyBestPickIfChanged(ctx context.Context, conn *Connection) map[string]any {
	best := p.BestPingFromCache(conn)
	mode := strPtrVal(conn.SubscriptionMode)
	if best == nil {
		return map[string]any{"switched": false, "mode": mode, "pick": nil, "previous_key": nil, "reason": "no_cache"}
	}
	if conn.IsURLTestMode() {
		return map[string]any{"switched": false, "mode": ModeURLTest, "pick": best, "previous_key": nil, "reason": "urltest_auto"}
	}
	if conn.SubscriptionMode != nil && *conn.SubscriptionMode == ModeSingle {
		current := strPtrVal(conn.SubscriptionSelected)
		if current == strVal(best["key"]) {
			return map[string]any{"switched": false, "mode": ModeSingle, "pick": best, "previous_key": current, "reason": "unchanged"}
		}
		var outbound map[string]any
		for _, n := range conn.SubscriptionNodes {
			if strVal(n["key"]) == strVal(best["key"]) {
				outbound, _ = n["outbound"].(map[string]any)
				break
			}
		}
		if outbound == nil || strVal(outbound["type"]) == "" {
			return map[string]any{"switched": false, "mode": ModeSingle, "pick": best, "previous_key": current, "reason": "node_not_found"}
		}
		sel := strVal(best["key"])
		conn.SubscriptionSelected = &sel
		conn.Outbound = outbound
		_ = p.Svc.Store.UpdateConnection(ctx, conn)
		p.PersistActivePick(ctx, conn, best, "ping")
		var prev any
		if current != "" {
			prev = current
		}
		return map[string]any{"switched": true, "mode": ModeSingle, "pick": best, "previous_key": prev, "reason": "switched"}
	}
	return map[string]any{"switched": false, "mode": mode, "pick": best, "previous_key": nil, "reason": "unsupported"}
}

func (p *PingService) CancelActiveSessionAndRestartProbe(ctx context.Context) map[string]any {
	had := p.cache().Has(sessionActiveKey)
	p.cache().Put(sessionCancelKey, true, 60*time.Second)
	p.probe().Stop(ctx)
	p.cache().Forget(sessionActiveKey)
	p.cache().Forget(sessionMetaKey)
	p.cache().Forget(sessionLockKey)
	p.cache().Forget(sessionCancelKey)
	p.probe().RebuildAndMaybeReload(ctx)
	_ = p.probe().EnsureStarted(ctx)
	return map[string]any{"ok": true, "restarted": true, "had_active_session": had}
}

func (p *PingService) SessionStatus() map[string]any {
	if !p.cache().Has(sessionActiveKey) {
		return map[string]any{"active": false}
	}
	meta, ok := p.cache().Get(sessionMetaKey)
	if !ok {
		return map[string]any{"active": true}
	}
	out := map[string]any{"active": true}
	if m, ok := meta.(map[string]any); ok {
		for k, v := range m {
			out[k] = v
		}
	}
	return out
}

func (p *PingService) FormatPingResult(r PingResult) map[string]any {
	src := r.Source
	if src == "" {
		src = "proxy"
	}
	return map[string]any{
		"key": r.Key, "latency_ms": intPtr(r.LatencyMS), "latency_ok": r.OK,
		"latency_error": r.Error, "latency_source": src,
	}
}

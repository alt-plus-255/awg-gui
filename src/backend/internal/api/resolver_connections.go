package api

import (
	"encoding/json"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/resolver"
)

type ResolverConnectionController struct {
	Svc *resolver.Service
}

func (c *ResolverConnectionController) ctx(r *http.Request) *http.Request {
	return r.WithContext(resolver.WithLocale(r.Context(), auth.LocaleFromContext(r.Context())))
}

func (c *ResolverConnectionController) WarmupPingProbe(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	ctx := r.Context()
	c.Svc.Probe.RebuildAndMaybeReload(ctx)
	if c.Svc.Probe.IsRunning(ctx) {
		c.Svc.Probe.Touch()
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "already_running": true})
		return
	}
	if err := c.Svc.Probe.EnsureStarted(ctx); err != nil {
		if c.Svc.Probe.IsRunning(ctx) {
			c.Svc.Probe.Touch()
			writeJSON(w, http.StatusOK, map[string]any{"ok": true, "already_running": true})
			return
		}
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"ok": false, "error": resolver.TranslateErr(auth.LocaleFromContext(ctx), err),
		})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (c *ResolverConnectionController) RestartPingProbe(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	writeJSON(w, http.StatusOK, c.Svc.Ping.CancelActiveSessionAndRestartProbe(r.Context()))
}

func (c *ResolverConnectionController) PingSession(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	writeJSON(w, http.StatusOK, c.Svc.Ping.SessionStatus())
}

func (c *ResolverConnectionController) Index(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conns, err := c.Svc.Store.ListConnections(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	traffic := c.Svc.TrafficByOutboundTag(r.Context())
	items := make([]map[string]any, 0, len(conns))
	for _, conn := range conns {
		items = append(items, c.serialize(r, conn, traffic[conn.OutboundTag()]))
	}
	writeJSON(w, http.StatusOK, map[string]any{"connections": items})
}

func (c *ResolverConnectionController) ParseSubscription(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	url := strings.TrimSpace(asString(req["url"]))
	body := strings.TrimSpace(asString(req["body"]))
	if url == "" && body == "" {
		writeValidation(w, r, "url", "resolver.subscription_url_or_content_required", nil)
		return
	}
	nodes, err := c.Svc.Fetch.FetchMerged(url, body)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"nodes": c.publicNodes(nodes, nil), "count": len(nodes)})
}

func (c *ResolverConnectionController) PingSubscription(w http.ResponseWriter, r *http.Request) {
	writeValidation(w, r, "connection", "resolver.save_connection_before_ping", nil)
}
func (c *ResolverConnectionController) PingSubscriptionStream(w http.ResponseWriter, r *http.Request) {
	writeValidation(w, r, "connection", "resolver.save_connection_before_ping", nil)
}
func (c *ResolverConnectionController) PingSubscriptionNode(w http.ResponseWriter, r *http.Request) {
	writeValidation(w, r, "connection", "resolver.save_connection_before_ping", nil)
}

func (c *ResolverConnectionController) loadConn(w http.ResponseWriter, r *http.Request) *resolver.Connection {
	id, ok := pathID(r, "connection")
	if !ok {
		writeNotFound(w, r)
		return nil
	}
	conn, err := c.Svc.Store.GetConnection(r.Context(), id)
	if err != nil || conn == nil {
		writeNotFound(w, r)
		return nil
	}
	return conn
}

func (c *ResolverConnectionController) PingConnectionSubscription(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conn := c.loadConn(w, r)
	if conn == nil {
		return
	}
	if !conn.IsSubscription() {
		writeValidation(w, r, "connection", "resolver.ping_subscriptions_only", nil)
		return
	}
	if len(conn.SubscriptionNodes) == 0 {
		writeValidation(w, r, "connection", "resolver.no_cached_nodes", nil)
		return
	}
	var req map[string]any
	_ = decodeJSON(r, &req)
	if req == nil {
		req = map[string]any{}
	}
	fast, _ := asBool(req["fast"])
	results, err := c.Svc.Ping.PingNodes(r.Context(), conn, nil, fast)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	c.Svc.Ping.SyncActivePickAfterPing(r.Context(), conn)
	latByKey := map[string]resolver.PingResult{}
	for _, res := range results {
		latByKey[res.Key] = res
	}
	public := c.publicNodes(conn.SubscriptionNodes, latByKey)
	public = sortNodesByLatency(public)
	writeJSON(w, http.StatusOK, map[string]any{
		"nodes": public, "count": len(conn.SubscriptionNodes),
		"tested": min(len(conn.SubscriptionNodes), resolver.MaxNodesPerSubscription),
		"truncated": len(conn.SubscriptionNodes) > resolver.MaxNodesPerSubscription,
	})
}

func (c *ResolverConnectionController) PingConnectionSubscriptionStream(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conn := c.loadConn(w, r)
	if conn == nil {
		return
	}
	if !conn.IsSubscription() {
		writeValidation(w, r, "connection", "resolver.ping_subscriptions_only", nil)
		return
	}
	if len(conn.SubscriptionNodes) == 0 {
		writeValidation(w, r, "connection", "resolver.no_cached_nodes", nil)
		return
	}
	var req map[string]any
	_ = decodeJSON(r, &req)
	if req == nil {
		req = map[string]any{}
	}
	fast, _ := asBool(req["fast"])
	autoApply, _ := asBool(req["auto_apply"])
	total := len(conn.SubscriptionNodes)
	truncated := total > resolver.MaxNodesPerSubscription

	w.Header().Set("Content-Type", "application/x-ndjson; charset=utf-8")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)
	flusher, _ := w.(http.Flusher)
	writeLine := func(v any) {
		b, _ := json.Marshal(v)
		_, _ = w.Write(append(b, '\n'))
		if flusher != nil {
			flusher.Flush()
		}
	}
	writeLine(map[string]any{
		"type": "start", "count": min(total, resolver.MaxNodesPerSubscription),
		"total": total, "truncated": truncated,
	})
	_, err := c.Svc.Ping.PingNodes(r.Context(), conn, func(res resolver.PingResult) {
		writeLine(map[string]any{
			"type": "result", "key": res.Key, "latency_ms": res.LatencyMS,
			"latency_ok": res.OK, "latency_error": res.Error,
			"latency_source": firstNonEmpty(res.Source, "proxy"),
		})
	}, fast)
	if err != nil {
		writeLine(map[string]any{"type": "error", "message": resolver.TranslateErr(auth.LocaleFromContext(r.Context()), err)})
	} else if autoApply {
		fresh, _ := c.Svc.Store.GetConnection(r.Context(), conn.ID)
		if fresh != nil {
			sw := c.Svc.Ping.ApplyBestPickIfChanged(r.Context(), fresh)
			if b, _ := sw["switched"].(bool); b {
				c.reloadIfNeeded(r)
				c.syncPingProbe(r)
			}
			c.Svc.Ping.SyncActivePickAfterPing(r.Context(), fresh)
			pick, _ := sw["pick"].(map[string]any)
			writeLine(map[string]any{
				"type": "switch", "switched": sw["switched"],
				"pick_name": pickVal(pick, "name"), "pick_key": pickVal(pick, "key"),
				"pick_latency_ms": pickVal(pick, "latency_ms"), "reason": sw["reason"],
			})
		}
	} else {
		c.Svc.Ping.SyncActivePickAfterPing(r.Context(), conn)
	}
	writeLine(map[string]any{
		"type": "done", "tested": min(total, resolver.MaxNodesPerSubscription), "truncated": truncated,
	})
}

func pickVal(m map[string]any, k string) any {
	if m == nil {
		return nil
	}
	return m[k]
}

func (c *ResolverConnectionController) SyncBestPick(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conn := c.loadConn(w, r)
	if conn == nil {
		return
	}
	if !conn.IsSubscription() {
		writeValidation(w, r, "connection", "resolver.subscriptions_only", nil)
		return
	}
	sw := c.Svc.Ping.ApplyBestPickIfChanged(r.Context(), conn)
	if b, _ := sw["switched"].(bool); b {
		c.reloadIfNeeded(r)
		c.syncPingProbe(r)
	}
	c.Svc.Ping.SyncActivePickAfterPing(r.Context(), conn)
	fresh, _ := c.Svc.Store.GetConnection(r.Context(), conn.ID)
	writeJSON(w, http.StatusOK, map[string]any{"result": sw, "connection": c.serialize(r, fresh, nil)})
}

func (c *ResolverConnectionController) PingConnectionSubscriptionNode(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conn := c.loadConn(w, r)
	if conn == nil {
		return
	}
	if !conn.IsSubscription() {
		writeValidation(w, r, "connection", "resolver.ping_subscriptions_only", nil)
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	key := asString(req["key"])
	if key == "" {
		writeValidation(w, r, "key", "api.http_422", nil)
		return
	}
	fast, _ := asBool(req["fast"])
	res, err := c.Svc.Ping.PingNode(r.Context(), conn, key, fast)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"result": c.Svc.Ping.FormatPingResult(res)})
}

func (c *ResolverConnectionController) Test(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	locale := auth.LocaleFromContext(r.Context())
	conn := c.loadConn(w, r)
	if conn == nil {
		return
	}
	if !conn.Enabled {
		writeValidation(w, r, "connection", "resolver.connection_disabled_enable_first", nil)
		return
	}
	probeOB := conn.TSPUProbeOutbound()
	if !c.Svc.WaitForClashAPI(r.Context()) {
		_ = c.Svc.Apply(r.Context(), resolver.ApplyOpts{})
		if !c.Svc.WaitForClashAPI(r.Context()) {
			tspu := resolver.Tspu{}.Probe(locale, probeOB, false)
			now := time.Now()
			conn.LastTestedAt = &now
			f := false
			conn.LastTestOK = &f
			conn.LastLatencyMS = nil
			st := asString(tspu["status"])
			conn.LastTSPUStatus = &st
			likely, _ := tspu["tspu_likely"].(bool)
			conn.LastTSPULikely = &likely
			det := asString(tspu["detail"])
			conn.LastTSPUDetail = &det
			conn.LastTSPUMeta = tspu
			errMsg := i18n.T(locale, "resolver.singbox_clash_unavailable")
			if likely {
				errMsg = i18n.Tf(locale, "resolver.tspu_prefix", map[string]string{"detail": det})
			}
			conn.LastTestError = &errMsg
			_ = c.Svc.Store.UpdateConnection(r.Context(), conn)
			fresh, _ := c.Svc.Store.GetConnection(r.Context(), conn.ID)
			writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
				"ok": false, "latency_ms": nil, "error": errMsg, "tspu": tspu,
				"connection": c.serialize(r, fresh, nil),
			})
			return
		}
	}
	result, err := c.Svc.Ping.PingConnection(r.Context(), conn, false)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	ok, _ := result["ok"].(bool)
	tspu := resolver.Tspu{}.Probe(locale, probeOB, ok)
	now := time.Now()
	conn.LastTestedAt = &now
	conn.LastTestOK = &ok
	if n, okN := asInt(result["latency_ms"]); okN {
		conn.LastLatencyMS = &n
	} else {
		conn.LastLatencyMS = nil
	}
	st := asString(tspu["status"])
	conn.LastTSPUStatus = &st
	likely, _ := tspu["tspu_likely"].(bool)
	conn.LastTSPULikely = &likely
	det := asString(tspu["detail"])
	conn.LastTSPUDetail = &det
	conn.LastTSPUMeta = tspu
	var errMsg *string
	if !ok {
		msg := asString(result["error"])
		if msg == "" {
			msg = i18n.T(locale, "api.error")
		}
		if likely {
			d := det
			if d == "" {
				d = i18n.T(locale, "resolver.tspu_dpi_likely")
			}
			msg = i18n.Tf(locale, "resolver.tspu_prefix", map[string]string{"detail": d})
		} else if st != "ok" && st != "skipped" && det != "" {
			if msg != "" {
				msg += " · "
			}
			msg += det
		}
		errMsg = &msg
	}
	conn.LastTestError = errMsg
	_ = c.Svc.Store.UpdateConnection(r.Context(), conn)
	traffic := c.Svc.TrafficByOutboundTag(r.Context())
	fresh, _ := c.Svc.Store.GetConnection(r.Context(), conn.ID)
	status := http.StatusOK
	if !ok {
		status = http.StatusUnprocessableEntity
	}
	writeJSON(w, status, map[string]any{
		"ok": ok, "latency_ms": result["latency_ms"], "error": errMsg, "tspu": tspu,
		"connection": c.serialize(r, fresh, traffic[conn.OutboundTag()]),
	})
}

func (c *ResolverConnectionController) Store(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	req, err := c.decodePayload(r, false, nil)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	kind := firstNonEmpty(asString(req["kind"]), resolver.KindProxy)
	var conn *resolver.Connection
	if kind == resolver.KindSubscription {
		conn, err = c.createSubscription(r, req)
	} else if asString(req["config_type"]) == "awg" {
		conn, err = c.createAWG(r, req)
	} else {
		ob, e := c.Svc.Parser.FromRequest(asString(req["config_type"]), asString(req["share_url"]), asString(req["outbound_json"]))
		if e != nil {
			writeResolverErr(w, r, e)
			return
		}
		enabled := true
		if v, ok := asBool(req["enabled"]); ok {
			enabled = v
		}
		interval := 5
		if n, ok := asInt(req["ping_check_interval_min"]); ok {
			interval = n
		}
		conn = &resolver.Connection{
			Name: asString(req["name"]), Kind: resolver.KindProxy,
			ConfigType: asString(req["config_type"]), Outbound: ob, Enabled: enabled,
			PingCheckIntervalMin: interval,
		}
		if v := asString(req["comment"]); v != "" {
			conn.Comment = &v
		}
		if asString(req["config_type"]) == "url" {
			if v := asString(req["share_url"]); v != "" {
				conn.ShareURL = &v
			}
		}
		id, e := c.Svc.Store.InsertConnection(r.Context(), conn)
		if e != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"message": e.Error()})
			return
		}
		conn, err = c.Svc.Store.GetConnection(r.Context(), id)
	}
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	c.reloadIfNeeded(r)
	c.syncPingProbe(r)
	writeJSON(w, http.StatusCreated, map[string]any{"connection": c.serialize(r, conn, nil)})
}

func (c *ResolverConnectionController) Update(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conn := c.loadConn(w, r)
	if conn == nil {
		return
	}
	req, err := c.decodePayload(r, true, conn)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	before := c.Svc.Print.Hash(conn)
	if v := asString(req["name"]); v != "" {
		conn.Name = v
	}
	if _, has := req["comment"]; has {
		s := asString(req["comment"])
		if s == "" {
			conn.Comment = nil
		} else {
			conn.Comment = &s
		}
	}
	if v, ok := asBool(req["enabled"]); ok {
		conn.Enabled = v
	}
	if n, ok := asInt(req["ping_check_interval_min"]); ok {
		conn.PingCheckIntervalMin = n
	}
	kind := firstNonEmpty(asString(req["kind"]), conn.Kind, resolver.KindProxy)
	if kind == resolver.KindSubscription {
		if err := c.applySubscription(r, conn, req); err != nil {
			writeResolverErr(w, r, err)
			return
		}
	} else {
		conn.Kind = resolver.KindProxy
		conn.SubscriptionURL = nil
		conn.SubscriptionBody = nil
		conn.SubscriptionMode = nil
		conn.SubscriptionSelected = nil
		conn.SubscriptionNodes = nil
		conn.SubscriptionFetchedAt = nil
		configType := firstNonEmpty(asString(req["config_type"]), conn.ConfigType)
		if configType == "awg" {
			if err := c.applyAWG(r, conn, req); err != nil {
				writeResolverErr(w, r, err)
				return
			}
		} else {
			needsParse := req["config_type"] != nil || req["share_url"] != nil || req["outbound_json"] != nil
			if needsParse {
				share := firstNonEmpty(asString(req["share_url"]), ptrStr(conn.ShareURL))
				obJSON := asString(req["outbound_json"])
				if obJSON == "" && conn.Outbound != nil {
					b, _ := json.Marshal(conn.Outbound)
					obJSON = string(b)
				}
				ob, e := c.Svc.Parser.FromRequest(configType, share, obJSON)
				if e != nil {
					writeResolverErr(w, r, e)
					return
				}
				conn.ConfigType = configType
				if configType == "url" && share != "" {
					conn.ShareURL = &share
				} else {
					conn.ShareURL = nil
				}
				conn.Outbound = ob
				conn.AWGConf = nil
				conn.ProtocolVersion = nil
			}
		}
	}
	if err := c.Svc.Store.UpdateConnection(r.Context(), conn); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	if conn.IsSubscription() {
		c.Svc.Ping.SyncActivePickAfterPing(r.Context(), conn)
	}
	reloaded := false
	if c.Svc.Print.Hash(conn) != before {
		c.reloadIfNeeded(r)
		c.syncPingProbe(r)
		reloaded = true
	}
	fresh, _ := c.Svc.Store.GetConnection(r.Context(), conn.ID)
	writeJSON(w, http.StatusOK, map[string]any{"connection": c.serialize(r, fresh, nil), "singbox_reloaded": reloaded})
}

func (c *ResolverConnectionController) Destroy(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conn := c.loadConn(w, r)
	if conn == nil {
		return
	}
	if conn.ConfigsCount > 0 {
		writeValidation(w, r, "connection", "resolver.cannot_delete_in_use", map[string]string{"refs": strconv.Itoa(conn.ConfigsCount)})
		return
	}
	if err := c.Svc.Store.DeleteConnection(r.Context(), conn.ID); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	c.reloadIfNeeded(r)
	c.syncPingProbe(r)
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (c *ResolverConnectionController) decodePayload(r *http.Request, updating bool, conn *resolver.Connection) (map[string]any, error) {
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		return nil, resolver.FieldErr("body", "api.http_422", nil)
	}
	kind := asString(req["kind"])
	if kind == "" && updating && conn != nil {
		kind = conn.Kind
	}
	if kind == "" {
		kind = resolver.KindProxy
	}
	if !updating && asString(req["name"]) == "" {
		return nil, resolver.FieldErr("name", "api.http_422", nil)
	}
	if kind != resolver.KindProxy && kind != resolver.KindSubscription {
		return nil, resolver.FieldErr("kind", "api.http_422", nil)
	}
	if n, ok := asInt(req["ping_check_interval_min"]); ok && (n < 0 || n > 1440) {
		return nil, resolver.FieldErr("ping_check_interval_min", "api.http_422", nil)
	}
	if kind == resolver.KindSubscription {
		if !updating && asString(req["subscription_url"]) == "" {
			return nil, resolver.FieldErr("subscription_url", "resolver.subscription_url_required", nil)
		}
		if !updating && asString(req["subscription_mode"]) == "" {
			return nil, resolver.FieldErr("subscription_mode", "resolver.subscription_mode_required", nil)
		}
		if m := asString(req["subscription_mode"]); m != "" && m != resolver.ModeSingle && m != resolver.ModeURLTest {
			return nil, resolver.FieldErr("subscription_mode", "resolver.subscription_mode_required", nil)
		}
	} else if !updating {
		ct := asString(req["config_type"])
		if ct != "url" && ct != "json" && ct != "awg" {
			return nil, resolver.FieldErr("config_type", "api.http_422", nil)
		}
		if ct == "awg" {
			if asString(req["awg_conf"]) == "" {
				return nil, resolver.FieldErr("awg_conf", "resolver.awg_conf_required", nil)
			}
			ver := asString(req["protocol_version"])
			if ver == "" || !resolver.HasProtocolVersion(ver) {
				return nil, resolver.FieldErr("protocol_version", "resolver.awg_protocol_version_invalid", nil)
			}
		}
	}
	req["kind"] = kind
	return req, nil
}

func (c *ResolverConnectionController) createAWG(r *http.Request, req map[string]any) (*resolver.Connection, error) {
	raw := asString(req["awg_conf"])
	if _, err := c.Svc.AWGParse.Parse(raw); err != nil {
		return nil, err
	}
	ver := firstNonEmpty(asString(req["protocol_version"]), resolver.LatestProtocolVersion())
	enabled := true
	if v, ok := asBool(req["enabled"]); ok {
		enabled = v
	}
	interval := 5
	if n, ok := asInt(req["ping_check_interval_min"]); ok {
		interval = n
	}
	conn := &resolver.Connection{
		Name: asString(req["name"]), Kind: resolver.KindProxy, ConfigType: "awg",
		AWGConf: &raw, ProtocolVersion: &ver, Outbound: map[string]any{"type": "direct"},
		Enabled: enabled, PingCheckIntervalMin: interval,
	}
	if v := asString(req["comment"]); v != "" {
		conn.Comment = &v
	}
	id, err := c.Svc.Store.InsertConnection(r.Context(), conn)
	if err != nil {
		return nil, err
	}
	conn.ID = id
	conn.Outbound = c.Svc.AWGBuild.OutboundFor(id)
	_ = c.Svc.Store.UpdateConnection(r.Context(), conn)
	return c.Svc.Store.GetConnection(r.Context(), id)
}

func (c *ResolverConnectionController) applyAWG(r *http.Request, conn *resolver.Connection, req map[string]any) error {
	conn.ConfigType = "awg"
	conn.ShareURL = nil
	raw := ptrStr(conn.AWGConf)
	if _, has := req["awg_conf"]; has {
		raw = asString(req["awg_conf"])
	}
	if strings.TrimSpace(raw) == "" {
		return resolver.FieldErr("awg_conf", "resolver.awg_conf_required", nil)
	}
	if _, err := c.Svc.AWGParse.Parse(raw); err != nil {
		return err
	}
	ver := firstNonEmpty(asString(req["protocol_version"]), ptrStr(conn.ProtocolVersion), resolver.LatestProtocolVersion())
	if !resolver.HasProtocolVersion(ver) {
		return resolver.FieldErr("protocol_version", "resolver.awg_protocol_version_invalid", nil)
	}
	conn.AWGConf = &raw
	conn.ProtocolVersion = &ver
	conn.Outbound = c.Svc.AWGBuild.OutboundFor(conn.ID)
	return nil
}

func (c *ResolverConnectionController) createSubscription(r *http.Request, req map[string]any) (*resolver.Connection, error) {
	nodes, err := c.fetchNodes(asString(req["subscription_url"]), asString(req["subscription_body"]))
	if err != nil {
		return nil, err
	}
	mode := asString(req["subscription_mode"])
	selected := asString(req["subscription_selected"])
	ob, err := outboundForSub(nodes, mode, selected)
	if err != nil {
		return nil, err
	}
	enabled := true
	if v, ok := asBool(req["enabled"]); ok {
		enabled = v
	}
	now := time.Now()
	url := asString(req["subscription_url"])
	body := strings.TrimSpace(asString(req["subscription_body"]))
	conn := &resolver.Connection{
		Name: asString(req["name"]), Kind: resolver.KindSubscription, ConfigType: "url",
		SubscriptionURL: &url, SubscriptionMode: &mode, SubscriptionNodes: nodes,
		SubscriptionFetchedAt: &now, Outbound: ob, Enabled: enabled, PingCheckIntervalMin: 5,
	}
	if n, ok := asInt(req["ping_check_interval_min"]); ok {
		conn.PingCheckIntervalMin = n
	}
	if v := asString(req["comment"]); v != "" {
		conn.Comment = &v
	}
	if body != "" {
		conn.SubscriptionBody = &body
	}
	if mode == resolver.ModeSingle && selected != "" {
		conn.SubscriptionSelected = &selected
	}
	id, err := c.Svc.Store.InsertConnection(r.Context(), conn)
	if err != nil {
		return nil, err
	}
	return c.Svc.Store.GetConnection(r.Context(), id)
}

func (c *ResolverConnectionController) applySubscription(r *http.Request, conn *resolver.Connection, req map[string]any) error {
	conn.Kind = resolver.KindSubscription
	conn.ConfigType = "url"
	conn.ShareURL = nil
	conn.AWGConf = nil
	conn.ProtocolVersion = nil
	url := firstNonEmpty(asString(req["subscription_url"]), ptrStr(conn.SubscriptionURL))
	if url == "" {
		return resolver.FieldErr("subscription_url", "resolver.subscription_url_required", nil)
	}
	mode := firstNonEmpty(asString(req["subscription_mode"]), ptrStr(conn.SubscriptionMode))
	if mode != resolver.ModeSingle && mode != resolver.ModeURLTest {
		return resolver.FieldErr("subscription_mode", "resolver.subscription_mode_required", nil)
	}
	urlChanged := asString(req["subscription_url"]) != "" && asString(req["subscription_url"]) != ptrStr(conn.SubscriptionURL)
	forceRefresh, _ := asBool(req["refresh_subscription"])
	_, bodyProvided := req["subscription_body"]
	body := ptrStr(conn.SubscriptionBody)
	if bodyProvided {
		body = strings.TrimSpace(asString(req["subscription_body"]))
	}
	nodes := conn.SubscriptionNodes
	if urlChanged || forceRefresh || bodyProvided || len(nodes) == 0 {
		fetched, err := c.fetchNodes(url, body)
		if err != nil {
			return err
		}
		nodes = fetched
		now := time.Now()
		conn.SubscriptionURL = &url
		if body != "" {
			conn.SubscriptionBody = &body
		} else {
			conn.SubscriptionBody = nil
		}
		conn.SubscriptionNodes = nodes
		conn.SubscriptionFetchedAt = &now
	}
	selected := ptrStr(conn.SubscriptionSelected)
	if _, has := req["subscription_selected"]; has {
		selected = asString(req["subscription_selected"])
	}
	ob, err := outboundForSub(nodes, mode, selected)
	if err != nil {
		return err
	}
	conn.SubscriptionMode = &mode
	if mode == resolver.ModeSingle && selected != "" {
		conn.SubscriptionSelected = &selected
	} else {
		conn.SubscriptionSelected = nil
	}
	conn.Outbound = ob
	return nil
}

func (c *ResolverConnectionController) fetchNodes(url, body string) ([]map[string]any, error) {
	nodes, err := c.Svc.Fetch.FetchMerged(url, body)
	if err != nil {
		if _, ok := err.(*resolver.ValidationError); ok {
			return nil, err
		}
		return nil, resolver.FieldErr("subscription_url", "resolver.subscription_fetch_failed_with_error", map[string]string{"error": resolver.TranslateErr("en", err)})
	}
	return nodes, nil
}

func outboundForSub(nodes []map[string]any, mode, selected string) (map[string]any, error) {
	if mode == resolver.ModeURLTest {
		return map[string]any{"type": "urltest"}, nil
	}
	if selected == "" {
		return nil, resolver.FieldErr("subscription_selected", "resolver.select_subscription_location", nil)
	}
	for _, n := range nodes {
		if asString(n["key"]) == selected {
			if ob, ok := n["outbound"].(map[string]any); ok {
				return ob, nil
			}
			return map[string]any{}, nil
		}
	}
	return nil, resolver.FieldErr("subscription_selected", "resolver.subscription_location_not_found", nil)
}

func (c *ResolverConnectionController) reloadIfNeeded(r *http.Request) {
	_ = c.Svc.Apply(r.Context(), resolver.ApplyOpts{})
}

func (c *ResolverConnectionController) syncPingProbe(r *http.Request) {
	c.Svc.Probe.RebuildAndMaybeReload(r.Context())
}

func (c *ResolverConnectionController) publicNodes(nodes []map[string]any, latByKey map[string]resolver.PingResult) []map[string]any {
	out := make([]map[string]any, 0, len(nodes))
	for _, n := range nodes {
		key := asString(n["key"])
		item := map[string]any{
			"key": key, "name": n["name"], "type": n["type"], "server": n["server"], "port": n["port"],
			"latency_ms": nil, "latency_ok": false, "latency_error": nil, "latency_source": nil,
		}
		if latByKey != nil {
			if lat, ok := latByKey[key]; ok {
				item["latency_ms"] = lat.LatencyMS
				item["latency_ok"] = lat.OK
				item["latency_error"] = lat.Error
				item["latency_source"] = firstNonEmpty(lat.Source, "proxy")
			}
		}
		out = append(out, item)
	}
	return out
}

func (c *ResolverConnectionController) publicNodesList(conn *resolver.Connection, nodes []map[string]any, activeKey, activeSource *string) []map[string]any {
	cached := c.Svc.Ping.ReadCachedLatencies(conn)
	mapped := make([]map[string]any, 0, len(nodes))
	for _, n := range nodes {
		key := asString(n["key"])
		var lat map[string]any
		if cached != nil {
			lat = cached[key]
		}
		isActive := activeKey != nil && *activeKey == key
		var src any
		if isActive {
			src = activeSource
		}
		item := map[string]any{
			"key": key, "name": n["name"], "type": n["type"], "server": n["server"], "port": n["port"],
			"latency_ms": nil, "latency_ok": false, "latency_error": nil, "latency_source": nil,
			"latency_tested_at": nil, "is_active": isActive, "is_best_pick": isActive, "active_source": src,
		}
		if lat != nil {
			item["latency_ms"] = lat["latency_ms"]
			item["latency_ok"] = lat["latency_ok"]
			item["latency_error"] = lat["latency_error"]
			item["latency_source"] = lat["source"]
			item["latency_tested_at"] = lat["tested_at"]
		}
		mapped = append(mapped, item)
	}
	return sortNodesByLatency(mapped)
}

func (c *ResolverConnectionController) serialize(r *http.Request, conn *resolver.Connection, traffic map[string]any) map[string]any {
	if conn == nil {
		return nil
	}
	rx, tx := 0, 0
	if traffic != nil {
		rx, _ = asInt(traffic["rx"])
		tx, _ = asInt(traffic["tx"])
	}
	var online any
	if conn.LastTestedAt != nil {
		online = conn.LastTestOK != nil && *conn.LastTestOK
	}
	kind := conn.Kind
	if kind == "" {
		kind = resolver.KindProxy
	}
	nodes := conn.SubscriptionNodes
	if nodes == nil {
		nodes = []map[string]any{}
	}
	var selectedName any
	if kind == resolver.KindSubscription && conn.SubscriptionMode != nil && *conn.SubscriptionMode == resolver.ModeSingle {
		for _, n := range nodes {
			if asString(n["key"]) == ptrStr(conn.SubscriptionSelected) {
				selectedName = n["name"]
				break
			}
		}
	}
	pick := c.subscriptionPickInfo(r, conn)
	activeKey := c.activeNodeKey(conn, pick)
	var activeSrc *string
	if s := asString(pick["subscription_pick_source"]); s != "" {
		activeSrc = &s
	}
	meta := conn.LastTSPUMeta
	if meta == nil {
		meta = map[string]any{}
	}
	return map[string]any{
		"id": conn.ID, "name": conn.Name, "comment": conn.Comment, "kind": kind,
		"config_type": conn.ConfigType, "share_url": conn.ShareURL, "awg_conf": conn.AWGConf,
		"protocol_version": conn.ProtocolVersion, "subscription_url": conn.SubscriptionURL,
		"subscription_body": conn.SubscriptionBody, "subscription_mode": conn.SubscriptionMode,
		"subscription_selected": conn.SubscriptionSelected, "subscription_selected_name": selectedName,
		"subscription_nodes": c.publicNodesList(conn, nodes, activeKey, activeSrc),
		"subscription_nodes_count": len(nodes),
		"subscription_fetched_at": isoPtr(conn.SubscriptionFetchedAt),
		"subscription_pick_name": pick["subscription_pick_name"],
		"subscription_pick_key": pick["subscription_pick_key"],
		"subscription_pick_latency_ms": pick["subscription_pick_latency_ms"],
		"subscription_pick_source": pick["subscription_pick_source"],
		"ping_check_interval_min": conn.PingCheckInterval(),
		"ping_last_checked_at": isoPtr(conn.PingLastCheckedAt),
		"outbound": conn.Outbound, "outbound_type": conn.Outbound["type"], "tag": conn.OutboundTag(),
		"enabled": conn.Enabled, "configs_count": conn.ConfigsCount, "rx": rx, "tx": tx, "online": online,
		"latency_ms": conn.LastLatencyMS, "last_tested_at": isoPtr(conn.LastTestedAt),
		"last_test_ok": conn.LastTestOK, "last_test_error": conn.LastTestError,
		"tspu": map[string]any{
			"status": conn.LastTSPUStatus, "likely": conn.LastTSPULikely, "detail": conn.LastTSPUDetail,
			"block_step": meta["block_step"], "control_ok": meta["control_ok"], "tcp_ok": meta["tcp_ok"],
			"tls_response": meta["tls_response"], "proxy_ok": meta["proxy_ok"],
			"server": meta["server"], "ip": meta["ip"], "port": meta["port"], "sni": meta["sni"],
			"chain": firstAny(meta["chain"], []any{}),
		},
		"created_at": isoPtr(conn.CreatedAt), "updated_at": isoPtr(conn.UpdatedAt),
	}
}

func (c *ResolverConnectionController) subscriptionPickInfo(r *http.Request, conn *resolver.Connection) map[string]any {
	empty := map[string]any{
		"subscription_pick_name": nil, "subscription_pick_key": nil,
		"subscription_pick_latency_ms": nil, "subscription_pick_source": nil,
	}
	if !conn.IsSubscription() {
		return empty
	}
	if conn.SubscriptionMode != nil && *conn.SubscriptionMode == resolver.ModeSingle {
		key := ptrStr(conn.SubscriptionSelected)
		if key != "" {
			if pick := c.singleModePick(conn, key); pick != nil {
				return pickToResponse(pick, "user")
			}
		}
		if persisted := c.Svc.Ping.ReadPersistedActivePick(conn); persisted != nil {
			return pickToResponse(persisted, asString(persisted["source"]))
		}
		return empty
	}
	if persisted := c.Svc.Ping.ReadPersistedActivePick(conn); persisted != nil {
		return pickToResponse(persisted, asString(persisted["source"]))
	}
	if live := c.Svc.Ping.ResolveURLTestActivePick(r.Context(), conn); live != nil {
		c.Svc.Ping.PersistActivePick(r.Context(), conn, live, "urltest")
		return pickToResponse(live, "urltest")
	}
	if best := c.Svc.Ping.BestPingFromCache(conn); best != nil {
		return pickToResponse(best, "cached")
	}
	return empty
}

func (c *ResolverConnectionController) singleModePick(conn *resolver.Connection, key string) map[string]any {
	cached := c.Svc.Ping.ReadCachedLatencies(conn)
	for _, n := range conn.SubscriptionNodes {
		if asString(n["key"]) != key {
			continue
		}
		var lat any
		if cached != nil {
			if e := cached[key]; e != nil {
				lat = e["latency_ms"]
			}
		}
		return map[string]any{"key": key, "name": firstNonEmpty(asString(n["name"]), key), "latency_ms": lat}
	}
	return nil
}

func (c *ResolverConnectionController) activeNodeKey(conn *resolver.Connection, pick map[string]any) *string {
	if conn.SubscriptionMode != nil && *conn.SubscriptionMode == resolver.ModeSingle {
		sel := ptrStr(conn.SubscriptionSelected)
		if sel != "" {
			return &sel
		}
		if k := asString(pick["subscription_pick_key"]); k != "" {
			return &k
		}
		return nil
	}
	if k := asString(pick["subscription_pick_key"]); k != "" {
		return &k
	}
	return nil
}

func pickToResponse(pick map[string]any, source string) map[string]any {
	return map[string]any{
		"subscription_pick_name": pick["name"], "subscription_pick_key": pick["key"],
		"subscription_pick_latency_ms": pick["latency_ms"], "subscription_pick_source": source,
	}
}

func sortNodesByLatency(nodes []map[string]any) []map[string]any {
	tier := func(n map[string]any) int {
		ok, _ := n["latency_ok"].(bool)
		if ok {
			return 0
		}
		if n["latency_error"] != nil && asString(n["latency_error"]) != "" {
			return 2
		}
		return 1
	}
	sort.SliceStable(nodes, func(i, j int) bool {
		ti, tj := tier(nodes[i]), tier(nodes[j])
		if ti != tj {
			return ti < tj
		}
		if ti == 0 {
			ai, _ := asInt(nodes[i]["latency_ms"])
			aj, _ := asInt(nodes[j]["latency_ms"])
			return ai < aj
		}
		return asString(nodes[i]["name"]) < asString(nodes[j]["name"])
	})
	return nodes
}

func firstNonEmpty(vals ...string) string {
	for _, v := range vals {
		if v != "" {
			return v
		}
	}
	return ""
}

func ptrStr(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

func isoPtr(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.UTC().Format(time.RFC3339)
}

func firstAny(v any, fallback any) any {
	if v == nil {
		return fallback
	}
	return v
}

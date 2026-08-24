package resolver

import (
	"encoding/json"
)

type Fingerprint struct {
	Parser OutboundParser
}

func (f Fingerprint) Hash(conn *Connection) string {
	return sha256Hex(mustJSON(f.payload(conn)))
}

func (f Fingerprint) NodesEqual(a, b []map[string]any) bool {
	return mustJSON(f.nodesPayload(a)) == mustJSON(f.nodesPayload(b))
}

func (f Fingerprint) payload(conn *Connection) map[string]any {
	if !conn.Enabled {
		return map[string]any{"enabled": false, "id": conn.ID}
	}
	if conn.IsSubscription() {
		sel := any(nil)
		if conn.SubscriptionMode != nil && *conn.SubscriptionMode == ModeSingle {
			sel = conn.SubscriptionSelected
		}
		return map[string]any{
			"enabled":       true,
			"kind":          KindSubscription,
			"mode":          conn.SubscriptionMode,
			"selected":      sel,
			"ping_interval": conn.PingCheckInterval(),
			"nodes":         f.nodesPayload(conn.SubscriptionNodes),
		}
	}
	ob := cloneMap(conn.Outbound)
	if strVal(ob["type"]) == "urltest" {
		ob = map[string]any{}
	}
	norm, err := f.Parser.Normalize(ob)
	if err != nil {
		norm = ob
	}
	var proto, awg any
	if conn.IsAWG() {
		proto = conn.ProtocolVersion
		awg = strPtrVal(conn.AWGConf)
	}
	return map[string]any{
		"enabled":          true,
		"kind":             KindProxy,
		"config_type":      conn.ConfigType,
		"protocol_version": proto,
		"awg_conf":         awg,
		"outbound":         norm,
	}
}

func (f Fingerprint) nodesPayload(nodes []map[string]any) []map[string]any {
	var items []map[string]any
	n := nodes
	if len(n) > MaxNodesPerSubscription {
		n = n[:MaxNodesPerSubscription]
	}
	for _, node := range n {
		if node == nil {
			continue
		}
		ob, _ := node["outbound"].(map[string]any)
		if ob == nil || strVal(ob["type"]) == "" {
			continue
		}
		norm, err := f.Parser.Normalize(cloneMap(ob))
		if err != nil {
			continue
		}
		items = append(items, map[string]any{"key": strVal(node["key"]), "outbound": norm})
	}
	return items
}

func mustJSON(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return ""
	}
	return string(b)
}

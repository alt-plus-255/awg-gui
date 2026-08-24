package resolver

type OutboundBuilder struct {
	Parser OutboundParser
}

type BuiltOutbounds struct {
	Outbounds               []map[string]any
	TagsAdded               map[string]bool
	TruncatedSubscriptions  map[int64]bool
}

func (b OutboundBuilder) BuildForConnections(conns []*Connection) BuiltOutbounds {
	outbounds := []map[string]any{{"type": "direct", "tag": "direct"}}
	tags := map[string]bool{"direct": true}
	truncated := map[int64]bool{}

	for _, conn := range conns {
		if conn == nil || !conn.Enabled {
			continue
		}
		tag := conn.OutboundTag()
		if tags[tag] {
			continue
		}
		if conn.IsURLTestMode() {
			nodes := conn.SubscriptionNodes
			if len(nodes) > MaxNodesPerSubscription {
				truncated[conn.ID] = true
				nodes = nodes[:MaxNodesPerSubscription]
			}
			var childTags []string
			i := 0
			for _, node := range nodes {
				if node == nil {
					continue
				}
				ob, _ := node["outbound"].(map[string]any)
				if ob == nil || strVal(ob["type"]) == "" {
					continue
				}
				i++
				child := conn.ChildOutboundTag(i)
				if tags[child] {
					continue
				}
				norm, err := b.Parser.Normalize(cloneMap(ob))
				if err != nil {
					continue
				}
				delete(norm, "tag")
				norm["tag"] = child
				outbounds = append(outbounds, norm)
				tags[child] = true
				childTags = append(childTags, child)
			}
			if len(childTags) > 0 {
				outbounds = append(outbounds, map[string]any{
					"type":                       "urltest",
					"tag":                        tag,
					"outbounds":                  childTags,
					"url":                        DelayTestURL,
					"interval":                   conn.URLTestIntervalDuration(),
					"tolerance":                  150,
					"interrupt_exist_connections": false,
				})
				tags[tag] = true
			}
			continue
		}
		ob := conn.Outbound
		if ob == nil || strVal(ob["type"]) == "" || strVal(ob["type"]) == "urltest" {
			continue
		}
		norm, err := b.Parser.Normalize(cloneMap(ob))
		if err != nil {
			continue
		}
		norm["tag"] = tag
		outbounds = append(outbounds, norm)
		tags[tag] = true
	}
	return BuiltOutbounds{Outbounds: outbounds, TagsAdded: tags, TruncatedSubscriptions: truncated}
}

func (b OutboundBuilder) ResolveNodeTag(conn *Connection, nodeKey string) *string {
	if conn.IsURLTestMode() {
		return conn.ChildTagForNodeKey(nodeKey)
	}
	if conn.IsSubscription() && conn.SubscriptionMode != nil && *conn.SubscriptionMode == ModeSingle {
		sel := strPtrVal(conn.SubscriptionSelected)
		if sel != "" && sel == nodeKey {
			t := conn.OutboundTag()
			return &t
		}
		return nil
	}
	t := conn.OutboundTag()
	return &t
}

func (b OutboundBuilder) PingableNodes(conn *Connection) []map[string]string {
	if !conn.IsSubscription() {
		return nil
	}
	if conn.IsURLTestMode() {
		var out []map[string]string
		i := 0
		for _, node := range conn.SubscriptionNodes {
			if i >= MaxNodesPerSubscription {
				break
			}
			if node == nil || strVal(node["key"]) == "" {
				continue
			}
			ob, _ := node["outbound"].(map[string]any)
			if ob == nil || strVal(ob["type"]) == "" {
				continue
			}
			i++
			out = append(out, map[string]string{"key": strVal(node["key"]), "tag": conn.ChildOutboundTag(i)})
		}
		return out
	}
	if conn.SubscriptionMode != nil && *conn.SubscriptionMode == ModeSingle {
		sel := strPtrVal(conn.SubscriptionSelected)
		if sel == "" {
			return nil
		}
		return []map[string]string{{"key": sel, "tag": conn.OutboundTag()}}
	}
	return nil
}

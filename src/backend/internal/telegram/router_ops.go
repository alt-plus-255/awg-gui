package telegram

import (
	"context"
	"fmt"
	"net"
	"strings"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/resolver"
)

func (r *UpdateRouter) wizardConfigName(ctx context.Context, chatID, text string) error {
	name, err := r.validateName(ctx, text, 64)
	if err != nil {
		return err
	}
	r.Conversations.Put(ctx, chatID, "cfg_new.type", map[string]any{"name": name})
	rows := Chunk([]map[string]string{
		Btn(r.t(ctx, "telegram.config_type_server"), "cfg:type:server"),
		Btn(r.t(ctx, "telegram.config_type_vn"), "cfg:type:virtual_network"),
	}, 1)
	r.Bot.SendMessage(ctx, chatID, r.t(ctx, "telegram.config_wizard_type"), map[string]any{"reply_markup": Inline(rows)})
	return nil
}

func (r *UpdateRouter) finishConfigCreate(ctx context.Context, chatID string, messageID int, typ string) {
	conv := r.Conversations.Get(ctx, chatID)
	if conv == nil || conv.Step != "cfg_new.type" {
		return
	}
	name := asStr(conv.Data["name"])
	if name == "" {
		r.Conversations.Clear(ctx, chatID)
		return
	}
	cfg, err := r.createConfig(ctx, name, typ)
	r.Conversations.Clear(ctx, chatID)
	if err != nil {
		r.showError(ctx, chatID, messageID, err.Error())
		return
	}
	prefix := r.tf(ctx, "telegram.config_created", map[string]string{"name": esc(cfg.Name)}) + "\n\n"
	r.showConfigDetail(ctx, chatID, messageID, cfg.ID, prefix)
}

func (r *UpdateRouter) wizardConfigEditName(ctx context.Context, chatID, text string, data map[string]any) error {
	cfg := r.findConfig(ctx, asInt64(data["config_id"]))
	if cfg == nil {
		r.Conversations.Clear(ctx, chatID)
		return nil
	}
	name, err := r.validateName(ctx, text, 64)
	if err != nil {
		return err
	}
	cfg.Name = name
	if err := r.Configs.Update(ctx, cfg); err != nil {
		return err
	}
	_ = r.AWG.ApplyConfig(ctx, nil, true, true)
	r.Conversations.Clear(ctx, chatID)
	r.Bot.SendMessage(ctx, chatID, r.t(ctx, "telegram.config_updated"), nil)
	return nil
}

func (r *UpdateRouter) wizardConfigEditPort(ctx context.Context, chatID, text string, data map[string]any) error {
	cfg := r.findConfig(ctx, asInt64(data["config_id"]))
	if cfg == nil {
		r.Conversations.Clear(ctx, chatID)
		return nil
	}
	port, err := parsePort(text)
	if err != nil || port < awg.PortMin || port > awg.PortMax {
		return &validationError{r.t(ctx, "telegram.error_invalid_port")}
	}
	if taken, _ := r.Configs.PortTaken(ctx, port, cfg.ID); taken {
		return &validationError{r.t(ctx, "telegram.error_port_taken")}
	}
	cfg.ListenPort = port
	if err := r.Configs.Update(ctx, cfg); err != nil {
		return err
	}
	_ = r.AWG.ApplyConfig(ctx, nil, true, true)
	r.Conversations.Clear(ctx, chatID)
	r.Bot.SendMessage(ctx, chatID, r.t(ctx, "telegram.config_updated"), nil)
	return nil
}

func (r *UpdateRouter) wizardConfigEditDns(ctx context.Context, chatID, text string, data map[string]any) error {
	cfg := r.findConfig(ctx, asInt64(data["config_id"]))
	if cfg == nil {
		r.Conversations.Clear(ctx, chatID)
		return nil
	}
	dns := strings.TrimSpace(text)
	if dns == "" || len(dns) > 255 {
		return &validationError{r.t(ctx, "telegram.error_invalid_name")}
	}
	cfg.PeerDNS = dns
	if err := r.Configs.Update(ctx, cfg); err != nil {
		return err
	}
	_ = r.AWG.ApplyConfig(ctx, nil, true, true)
	r.Conversations.Clear(ctx, chatID)
	r.Bot.SendMessage(ctx, chatID, r.t(ctx, "telegram.config_updated"), nil)
	return nil
}

func (r *UpdateRouter) wizardPeerName(ctx context.Context, chatID, text string, data map[string]any) error {
	cfg := r.findConfig(ctx, asInt64(data["config_id"]))
	if cfg == nil {
		r.Conversations.Clear(ctx, chatID)
		return nil
	}
	name, err := r.validateName(ctx, text, 64)
	if err != nil {
		return err
	}
	client, err := r.Clients.Create(ctx, name, nil)
	if err != nil {
		return err
	}
	if err := r.attachPeer(ctx, cfg, client); err != nil {
		return err
	}
	r.Conversations.Clear(ctx, chatID)
	r.Bot.SendMessage(ctx, chatID, r.tf(ctx, "telegram.peer_created", map[string]string{"name": esc(name)}), nil)
	return nil
}

func (r *UpdateRouter) wizardConnectionName(ctx context.Context, chatID, text string) error {
	name, err := r.validateName(ctx, text, 128)
	if err != nil {
		return err
	}
	r.Conversations.Put(ctx, chatID, "conn_new.url", map[string]any{"name": name})
	r.Bot.SendMessage(ctx, chatID, r.t(ctx, "telegram.connection_wizard_url"), map[string]any{"reply_markup": r.backConnectionsKeyboard(ctx)})
	return nil
}

func (r *UpdateRouter) wizardConnectionURL(ctx context.Context, chatID, text string, data map[string]any) error {
	name := asStr(data["name"])
	if name == "" {
		r.Conversations.Clear(ctx, chatID)
		return nil
	}
	shareURL := strings.TrimSpace(text)
	if shareURL == "" {
		return &validationError{r.t(ctx, "telegram.error_invalid_url")}
	}
	parser := resolver.OutboundParser{}
	outbound, err := parser.FromRequest("url", shareURL, "")
	if err != nil {
		if ve, ok := err.(*resolver.ValidationError); ok {
			return &validationError{ve.Translate(r.locale(ctx))}
		}
		return &validationError{err.Error()}
	}
	conn := &resolver.Connection{
		Name:                 name,
		Kind:                 resolver.KindProxy,
		ConfigType:           "url",
		ShareURL:             &shareURL,
		Outbound:             outbound,
		Enabled:              true,
		PingCheckIntervalMin: 5,
	}
	if _, err := r.Resolver.Store.InsertConnection(ctx, conn); err != nil {
		return err
	}
	_ = r.Resolver.Apply(ctx, resolver.ApplyOpts{RefreshSubscriptions: false})
	r.Conversations.Clear(ctx, chatID)
	r.Bot.SendMessage(ctx, chatID, r.tf(ctx, "telegram.connection_created", map[string]string{"name": esc(name)}), nil)
	return nil
}

func (r *UpdateRouter) showConfigsList(ctx context.Context, chatID string, messageID int) {
	configs, _ := r.Configs.List(ctx)
	text := r.t(ctx, "telegram.configs_title")
	if len(configs) == 0 {
		text += "\n\n" + r.t(ctx, "telegram.configs_empty")
	}
	buttons := []map[string]string{Btn(r.t(ctx, "telegram.create"), "cfg:new")}
	for _, c := range configs {
		status := "⏸"
		if c.Enabled {
			status = "✅"
		}
		buttons = append(buttons, Btn(status+" "+c.Name, "cfg:"+itoa64str(c.ID)))
	}
	buttons = append(buttons, Btn(r.t(ctx, "telegram.menu_home"), "m:home"))
	r.show(ctx, chatID, messageID, text, Inline(Chunk(buttons, 1)))
}

func (r *UpdateRouter) showConfigDetail(ctx context.Context, chatID string, messageID int, configID int64, prefix string) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	text := prefix + r.formatConfigDetail(ctx, cfg)
	toggleLabel := r.t(ctx, "telegram.config_enable")
	if cfg.Enabled {
		toggleLabel = r.t(ctx, "telegram.config_disable")
	}
	rows := [][]map[string]string{
		{Btn(toggleLabel, "cfg:en:"+itoa64str(configID)), Btn(r.t(ctx, "telegram.peers"), "cfg:peers:"+itoa64str(configID))},
		{Btn(r.t(ctx, "telegram.edit"), "cfg:edit:"+itoa64str(configID)), Btn(r.t(ctx, "telegram.delete"), "cfg:del:"+itoa64str(configID))},
		{Btn(r.t(ctx, "telegram.menu_back"), "m:cfg")},
	}
	r.show(ctx, chatID, messageID, text, Inline(rows))
}

func (r *UpdateRouter) formatConfigDetail(ctx context.Context, cfg *models.AwgConfig) string {
	typ := r.t(ctx, "telegram.config_type_server")
	if cfg.Type == "virtual_network" {
		typ = r.t(ctx, "telegram.config_type_vn")
	}
	dns := cfg.PeerDNS
	if dns == "" {
		dns = "—"
	}
	return r.tf(ctx, "telegram.config_detail", map[string]string{
		"name": esc(cfg.Name), "type": esc(typ), "port": itoa(cfg.ListenPort),
		"dns": esc(dns), "status": r.statusLabel(ctx, cfg.Enabled),
	})
}

func (r *UpdateRouter) showConfigEditMenu(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	text := r.tf(ctx, "telegram.config_edit_title", map[string]string{"name": esc(cfg.Name)})
	rows := Chunk([]map[string]string{
		Btn(r.t(ctx, "telegram.config_edit_name"), "cfg:edn:"+itoa64str(configID)),
		Btn(r.t(ctx, "telegram.config_edit_port"), "cfg:edp:"+itoa64str(configID)),
		Btn(r.t(ctx, "telegram.config_edit_dns"), "cfg:edd:"+itoa64str(configID)),
		Btn(r.t(ctx, "telegram.menu_back"), "cfg:"+itoa64str(configID)),
	}, 1)
	r.show(ctx, chatID, messageID, text, Inline(rows))
}

func (r *UpdateRouter) showConfigDeleteConfirm(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	text := r.tf(ctx, "telegram.config_delete_confirm", map[string]string{"name": esc(cfg.Name)})
	r.show(ctx, chatID, messageID, text, Inline(yesNo(ctx, r, "cfg:delok:"+itoa64str(configID), "cfg:"+itoa64str(configID))))
}

func (r *UpdateRouter) showConfigEnableConfirm(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	key := "telegram.config_enable_confirm"
	if cfg.Enabled {
		key = "telegram.config_disable_confirm"
	}
	r.show(ctx, chatID, messageID, r.tf(ctx, key, map[string]string{"name": esc(cfg.Name)}),
		Inline(yesNo(ctx, r, "cfg:enok:"+itoa64str(configID), "cfg:"+itoa64str(configID))))
}

func (r *UpdateRouter) toggleConfigEnabled(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	cfg.Enabled = !cfg.Enabled
	if cfg.Type == "virtual_network" && cfg.ResolverEnabled {
		cfg.ResolverEnabled = false
		cfg.ConnectionID = nil
		cfg.ResolverLastError = nil
	}
	_ = r.Configs.Update(ctx, cfg)
	_ = r.AWG.ApplyConfig(ctx, nil, true, true)
	r.showConfigDetail(ctx, chatID, messageID, configID, "")
}

func (r *UpdateRouter) deleteConfig(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	if n, _ := r.Configs.Count(ctx); n <= 1 {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.cannot_delete_last_config"))
		return
	}
	_ = r.Configs.Delete(ctx, configID)
	_ = r.AWG.ApplyConfig(ctx, nil, true, true)
	r.showConfigsList(ctx, chatID, messageID)
	r.show(ctx, chatID, 0, r.t(ctx, "telegram.config_deleted"), nil)
}

func (r *UpdateRouter) showPeersList(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	r.AWG.PrimeConfigPeerCache(ctx, cfg)
	peers, _ := r.Peers.ListByConfig(ctx, configID)
	text := r.tf(ctx, "telegram.peers_title", map[string]string{"name": esc(cfg.Name)})
	if len(peers) == 0 {
		text += "\n\n" + r.t(ctx, "telegram.peers_empty")
	}
	buttons := []map[string]string{Btn(r.t(ctx, "telegram.create"), "peer:new:"+itoa64str(configID))}
	for _, p := range peers {
		name := "#" + itoa64str(p.VpnClientID)
		if cl, _ := r.Clients.Find(ctx, p.VpnClientID); cl != nil {
			name = cl.Name
		}
		status := "⏸"
		if p.Enabled {
			status = "✅"
		}
		buttons = append(buttons, Btn(status+" "+name, "peer:"+itoa64str(configID)+":"+itoa64str(p.VpnClientID)))
	}
	buttons = append(buttons, Btn(r.t(ctx, "telegram.menu_back"), "cfg:"+itoa64str(configID)))
	r.show(ctx, chatID, messageID, text, Inline(Chunk(buttons, 1)))
}

func (r *UpdateRouter) showPeerDetail(ctx context.Context, chatID string, messageID int, configID, clientID int64) {
	m := r.findMembership(ctx, configID, clientID)
	if m == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.peer_not_found"))
		return
	}
	name := "#" + itoa64str(clientID)
	if m.Client != nil {
		name = m.Client.Name
	}
	addr := m.Address
	if addr == "" {
		addr = "—"
	}
	text := r.tf(ctx, "telegram.peer_detail", map[string]string{
		"name": esc(name), "address": esc(addr), "status": r.statusLabel(ctx, m.Enabled),
	})
	toggle := r.t(ctx, "telegram.config_enable")
	if m.Enabled {
		toggle = r.t(ctx, "telegram.config_disable")
	}
	rows := [][]map[string]string{
		{Btn(toggle, "peer:en:"+itoa64str(configID)+":"+itoa64str(clientID)), Btn(r.t(ctx, "telegram.vpn_uri"), "peer:uri:"+itoa64str(configID)+":"+itoa64str(clientID))},
		{Btn(r.t(ctx, "telegram.delete"), "peer:del:"+itoa64str(configID)+":"+itoa64str(clientID))},
		{Btn(r.t(ctx, "telegram.menu_back"), "cfg:peers:"+itoa64str(configID))},
	}
	r.show(ctx, chatID, messageID, text, Inline(rows))
}

func (r *UpdateRouter) showPeerDeleteConfirm(ctx context.Context, chatID string, messageID int, configID, clientID int64) {
	m := r.findMembership(ctx, configID, clientID)
	if m == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.peer_not_found"))
		return
	}
	name := "#" + itoa64str(clientID)
	if m.Client != nil {
		name = m.Client.Name
	}
	r.show(ctx, chatID, messageID, r.tf(ctx, "telegram.peer_delete_confirm", map[string]string{"name": esc(name)}),
		Inline(yesNo(ctx, r, "peer:delok:"+itoa64str(configID)+":"+itoa64str(clientID), "peer:"+itoa64str(configID)+":"+itoa64str(clientID))))
}

func (r *UpdateRouter) showPeerEnableConfirm(ctx context.Context, chatID string, messageID int, configID, clientID int64) {
	m := r.findMembership(ctx, configID, clientID)
	if m == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.peer_not_found"))
		return
	}
	name := "#" + itoa64str(clientID)
	if m.Client != nil {
		name = m.Client.Name
	}
	key := "telegram.peer_enable_confirm"
	if m.Enabled {
		key = "telegram.peer_disable_confirm"
	}
	r.show(ctx, chatID, messageID, r.tf(ctx, key, map[string]string{"name": esc(name)}),
		Inline(yesNo(ctx, r, "peer:enok:"+itoa64str(configID)+":"+itoa64str(clientID), "peer:"+itoa64str(configID)+":"+itoa64str(clientID))))
}

func (r *UpdateRouter) togglePeerEnabled(ctx context.Context, chatID string, messageID int, configID, clientID int64) {
	cfg := r.findConfig(ctx, configID)
	m := r.findMembership(ctx, configID, clientID)
	if cfg == nil || m == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.peer_not_found"))
		return
	}
	m.Enabled = !m.Enabled
	_ = r.Peers.Update(ctx, m)
	r.AWG.InvalidateAllowedIPCache(configID, m.ID)
	_ = r.AWG.ApplyConfig(ctx, cfg, false, false)
	r.showPeerDetail(ctx, chatID, messageID, configID, clientID)
}

func (r *UpdateRouter) deletePeer(ctx context.Context, chatID string, messageID int, configID, clientID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	_ = r.Peers.DeleteMembership(ctx, configID, clientID)
	r.pruneExcludedClientID(ctx, cfg, clientID)
	r.pruneClientFromZones(ctx, cfg, clientID)
	r.AWG.InvalidateConfigPeerCaches(configID)
	_ = r.AWG.ApplyConfig(ctx, cfg, false, false)
	r.showPeersList(ctx, chatID, messageID, configID)
}

func (r *UpdateRouter) sendPeerVpnURI(ctx context.Context, chatID string, configID, clientID int64) {
	m := r.findMembership(ctx, configID, clientID)
	if m == nil {
		r.Bot.SendMessage(ctx, chatID, r.t(ctx, "telegram.peer_not_found"), nil)
		return
	}
	if cfg := r.findConfig(ctx, configID); cfg != nil {
		m.Config = cfg
	}
	name := "#" + itoa64str(clientID)
	if m.Client != nil {
		name = m.Client.Name
	}
	uri, err := r.VpnURI.BuildFromMembership(ctx, m)
	if err != nil {
		r.Bot.SendMessage(ctx, chatID, r.tf(ctx, "telegram.error_generic", map[string]string{"message": esc(err.Error())}), nil)
		return
	}
	r.Bot.SendMessage(ctx, chatID, r.tf(ctx, "telegram.peer_uri_caption", map[string]string{"name": esc(name)})+"\n\n<code>"+esc(uri)+"</code>", nil)
}

func (r *UpdateRouter) showResolverList(ctx context.Context, chatID string, messageID int) {
	configs, _ := r.Configs.List(ctx)
	text := r.t(ctx, "telegram.resolver_title")
	buttons := []map[string]string{}
	count := 0
	for _, c := range configs {
		if c.Type != "server" {
			continue
		}
		count++
		flag := "✗"
		if c.ResolverEnabled {
			flag = "✓"
		}
		buttons = append(buttons, Btn(flag+" "+c.Name, "res:"+itoa64str(c.ID)))
	}
	if count == 0 {
		text += "\n\n" + r.t(ctx, "telegram.resolver_empty")
	}
	buttons = append(buttons, Btn(r.t(ctx, "telegram.menu_home"), "m:home"))
	r.show(ctx, chatID, messageID, text, Inline(Chunk(buttons, 1)))
}

func (r *UpdateRouter) showResolverDetail(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil || cfg.Type != "server" {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	connectionName := r.t(ctx, "telegram.resolver_no_connection")
	if cfg.ConnectionID != nil {
		if conn, _ := r.Resolver.Store.GetConnection(ctx, *cfg.ConnectionID); conn != nil {
			connectionName = conn.Name
		} else {
			connectionName = "#" + itoa64str(*cfg.ConnectionID)
		}
	}
	lists := cfg.CommunityLists
	var labels []string
	for _, tag := range lists {
		labels = append(labels, r.listLabel(ctx, tag))
	}
	listStr := "—"
	if len(labels) > 0 {
		listStr = strings.Join(labels, ", ")
	}
	text := r.tf(ctx, "telegram.resolver_detail", map[string]string{
		"name": esc(cfg.Name), "status": r.statusLabel(ctx, cfg.ResolverEnabled),
		"connection": esc(connectionName), "lists": esc(listStr),
	})
	toggle := r.t(ctx, "telegram.config_enable")
	if cfg.ResolverEnabled {
		toggle = r.t(ctx, "telegram.config_disable")
	}
	buttons := []map[string]string{
		Btn(toggle, "res:en:"+itoa64str(configID)),
		Btn(r.t(ctx, "telegram.resolver_pick_connection"), "res:conn:"+itoa64str(configID)+":0"),
	}
	listSet := map[string]bool{}
	for _, t := range lists {
		listSet[t] = true
	}
	for _, tag := range resolver.CommunityLists {
		mark := ""
		if listSet[tag] {
			mark = "✓ "
		}
		buttons = append(buttons, Btn(mark+r.listLabel(ctx, tag), "res:list:"+itoa64str(configID)+":"+tag))
	}
	buttons = append(buttons, Btn(r.t(ctx, "telegram.menu_back"), "m:res"))
	r.show(ctx, chatID, messageID, text, Inline(Chunk(buttons, 1)))
}

func (r *UpdateRouter) showResolverEnableConfirm(ctx context.Context, chatID string, messageID int, configID int64) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil || cfg.Type != "server" {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	key := "telegram.resolver_enable_confirm"
	if cfg.ResolverEnabled {
		key = "telegram.resolver_disable_confirm"
	}
	r.show(ctx, chatID, messageID, r.tf(ctx, key, map[string]string{"name": esc(cfg.Name)}),
		Inline(yesNo(ctx, r, "res:enok:"+itoa64str(configID), "res:"+itoa64str(configID))))
}

func (r *UpdateRouter) toggleResolver(ctx context.Context, chatID string, messageID int, configID int64) error {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil || cfg.Type != "server" {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return nil
	}
	rcfg, err := r.Resolver.Store.GetConfig(ctx, configID)
	if err != nil || rcfg == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return nil
	}
	if rcfg.ResolverEnabled {
		rcfg.ResolverEnabled = false
		rcfg.ResolverLastError = nil
		_ = r.Resolver.Store.UpdateConfigResolver(ctx, rcfg)
		_ = r.AWG.ApplyConfig(ctx, cfg, true, false)
		r.showResolverDetail(ctx, chatID, messageID, configID)
		return nil
	}
	if err := r.Resolver.AssertCanEnable(rcfg); err != nil {
		return err
	}
	normalized, err := r.Resolver.NormalizeLists(ctx, rcfg.CommunityLists, rcfg.UserDomains, rcfg.UserSubnets)
	if err != nil {
		return err
	}
	conn, err := r.Resolver.AssertConnectionSelected(ctx, rcfg.ConnectionID)
	if err != nil {
		return err
	}
	rcfg.ResolverEnabled = true
	id := conn.ID
	rcfg.ConnectionID = &id
	rcfg.CommunityLists = normalized["community_lists"]
	rcfg.UserDomains = normalized["user_domains"]
	rcfg.UserSubnets = normalized["user_subnets"]
	_ = r.Resolver.Store.UpdateConfigResolver(ctx, rcfg)
	_ = r.AWG.ApplyConfig(ctx, cfg, true, false)
	r.showResolverDetail(ctx, chatID, messageID, configID)
	return nil
}

func (r *UpdateRouter) toggleResolverList(ctx context.Context, chatID string, messageID int, configID int64, tag string) {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil || cfg.Type != "server" {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return
	}
	if !resolver.IsCommunityTag(tag) {
		return
	}
	rcfg, _ := r.Resolver.Store.GetConfig(ctx, configID)
	if rcfg == nil {
		return
	}
	lists := append([]string{}, rcfg.CommunityLists...)
	found := false
	var next []string
	for _, t := range lists {
		if t == tag {
			found = true
			continue
		}
		next = append(next, t)
	}
	if !found {
		excl := map[string]bool{}
		for _, t := range resolver.MutuallyExclusive {
			excl[t] = true
		}
		if excl[tag] {
			var filtered []string
			for _, t := range next {
				if !excl[t] {
					filtered = append(filtered, t)
				}
			}
			next = filtered
		}
		next = append(next, tag)
	}
	normalized, err := r.Resolver.NormalizeLists(ctx, next, rcfg.UserDomains, rcfg.UserSubnets)
	if err != nil {
		r.showError(ctx, chatID, messageID, err.Error())
		return
	}
	rcfg.CommunityLists = normalized["community_lists"]
	rcfg.UserDomains = normalized["user_domains"]
	rcfg.UserSubnets = normalized["user_subnets"]
	_ = r.Resolver.Store.UpdateConfigResolver(ctx, rcfg)
	_ = r.AWG.ApplyConfig(ctx, cfg, true, false)
	r.showResolverDetail(ctx, chatID, messageID, configID)
}

func (r *UpdateRouter) setResolverConnection(ctx context.Context, chatID string, messageID int, configID, connID int64) error {
	cfg := r.findConfig(ctx, configID)
	if cfg == nil || cfg.Type != "server" {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.config_not_found"))
		return nil
	}
	rcfg, _ := r.Resolver.Store.GetConfig(ctx, configID)
	if rcfg == nil {
		return nil
	}
	if connID == 0 {
		conns, _ := r.Resolver.Store.EnabledConnections(ctx)
		text := r.t(ctx, "telegram.resolver_pick_connection")
		var buttons []map[string]string
		for _, conn := range conns {
			mark := ""
			if rcfg.ConnectionID != nil && *rcfg.ConnectionID == conn.ID {
				mark = "✓ "
			}
			buttons = append(buttons, Btn(mark+conn.Name, "res:conn:"+itoa64str(configID)+":"+itoa64str(conn.ID)))
		}
		buttons = append(buttons, Btn(r.t(ctx, "telegram.menu_back"), "res:"+itoa64str(configID)))
		r.show(ctx, chatID, messageID, text, Inline(Chunk(buttons, 1)))
		return nil
	}
	conn, err := r.Resolver.AssertConnectionSelected(ctx, &connID)
	if err != nil {
		return err
	}
	id := conn.ID
	rcfg.ConnectionID = &id
	if rcfg.ResolverEnabled {
		normalized, err := r.Resolver.NormalizeLists(ctx, rcfg.CommunityLists, rcfg.UserDomains, rcfg.UserSubnets)
		if err != nil {
			return err
		}
		rcfg.CommunityLists = normalized["community_lists"]
		rcfg.UserDomains = normalized["user_domains"]
		rcfg.UserSubnets = normalized["user_subnets"]
	}
	_ = r.Resolver.Store.UpdateConfigResolver(ctx, rcfg)
	_ = r.AWG.ApplyConfig(ctx, cfg, true, false)
	r.showResolverDetail(ctx, chatID, messageID, configID)
	return nil
}

func (r *UpdateRouter) showConnectionsList(ctx context.Context, chatID string, messageID int) {
	conns, _ := r.Resolver.Store.ListConnections(ctx)
	text := r.t(ctx, "telegram.connections_title")
	if len(conns) == 0 {
		text += "\n\n" + r.t(ctx, "telegram.connections_empty")
	}
	buttons := []map[string]string{Btn(r.t(ctx, "telegram.create"), "conn:new")}
	for _, c := range conns {
		status := "⏸"
		if c.Enabled {
			status = "✅"
		}
		buttons = append(buttons, Btn(status+" "+c.Name, "conn:"+itoa64str(c.ID)))
	}
	buttons = append(buttons, Btn(r.t(ctx, "telegram.menu_home"), "m:home"))
	r.show(ctx, chatID, messageID, text, Inline(Chunk(buttons, 1)))
}

func (r *UpdateRouter) showConnectionDetail(ctx context.Context, chatID string, messageID int, connectionID int64) {
	conn, _ := r.Resolver.Store.GetConnection(ctx, connectionID)
	if conn == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.connection_not_found"))
		return
	}
	text := r.tf(ctx, "telegram.connection_detail", map[string]string{
		"name": esc(conn.Name), "type": esc(r.t(ctx, "telegram.connection_type_proxy")),
		"status": r.statusLabel(ctx, conn.Enabled),
	})
	toggle := r.t(ctx, "telegram.config_enable")
	if conn.Enabled {
		toggle = r.t(ctx, "telegram.config_disable")
	}
	rows := [][]map[string]string{
		{Btn(toggle, "conn:en:"+itoa64str(connectionID)), Btn(r.t(ctx, "telegram.delete"), "conn:del:"+itoa64str(connectionID))},
		{Btn(r.t(ctx, "telegram.menu_back"), "m:conn")},
	}
	r.show(ctx, chatID, messageID, text, Inline(rows))
}

func (r *UpdateRouter) showConnectionDeleteConfirm(ctx context.Context, chatID string, messageID int, connectionID int64) {
	conn, _ := r.Resolver.Store.GetConnection(ctx, connectionID)
	if conn == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.connection_not_found"))
		return
	}
	r.show(ctx, chatID, messageID, r.tf(ctx, "telegram.connection_delete_confirm", map[string]string{"name": esc(conn.Name)}),
		Inline(yesNo(ctx, r, "conn:delok:"+itoa64str(connectionID), "conn:"+itoa64str(connectionID))))
}

func (r *UpdateRouter) toggleConnectionEnabled(ctx context.Context, chatID string, messageID int, connectionID int64) {
	conn, _ := r.Resolver.Store.GetConnection(ctx, connectionID)
	if conn == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.connection_not_found"))
		return
	}
	conn.Enabled = !conn.Enabled
	_ = r.Resolver.Store.UpdateConnection(ctx, conn)
	_ = r.Resolver.Apply(ctx, resolver.ApplyOpts{RefreshSubscriptions: false})
	r.showConnectionDetail(ctx, chatID, messageID, connectionID)
}

func (r *UpdateRouter) deleteConnection(ctx context.Context, chatID string, messageID int, connectionID int64) {
	conn, _ := r.Resolver.Store.GetConnection(ctx, connectionID)
	if conn == nil {
		r.showError(ctx, chatID, messageID, r.t(ctx, "telegram.connection_not_found"))
		return
	}
	if conn.ConfigsCount > 0 {
		r.showError(ctx, chatID, messageID, r.tf(ctx, "telegram.connection_in_use", map[string]string{"refs": itoa(conn.ConfigsCount)}))
		return
	}
	_ = r.Resolver.Store.DeleteConnection(ctx, connectionID)
	_ = r.Resolver.Apply(ctx, resolver.ApplyOpts{RefreshSubscriptions: false})
	r.showConnectionsList(ctx, chatID, messageID)
}

func (r *UpdateRouter) showNotifications(ctx context.Context, chatID string, messageID int) {
	peerEnabled := r.Settings.NotificationsEnabled(ctx)
	dailyEnabled := r.Settings.DailyReportEnabled(ctx)
	text := r.t(ctx, "telegram.notifications_title") + "\n\n" +
		r.tf(ctx, "telegram.notifications_status", map[string]string{"status": r.statusLabel(ctx, peerEnabled)}) + "\n" +
		r.tf(ctx, "telegram.daily_report_status", map[string]string{"status": r.statusLabel(ctx, dailyEnabled)})
	peerBtn := r.t(ctx, "telegram.notifications_peer_enable")
	if peerEnabled {
		peerBtn = r.t(ctx, "telegram.notifications_peer_disable")
	}
	dailyBtn := r.t(ctx, "telegram.daily_report_enable")
	if dailyEnabled {
		dailyBtn = r.t(ctx, "telegram.daily_report_disable")
	}
	rows := [][]map[string]string{
		{Btn(peerBtn, "notif:en")},
		{Btn(dailyBtn, "notif:daily:en")},
		{Btn(r.t(ctx, "telegram.menu_home"), "m:home")},
	}
	r.show(ctx, chatID, messageID, text, Inline(rows))
}

func (r *UpdateRouter) showNotificationsEnableConfirm(ctx context.Context, chatID string, messageID int) {
	key := "telegram.notifications_enable_confirm"
	if r.Settings.NotificationsEnabled(ctx) {
		key = "telegram.notifications_disable_confirm"
	}
	r.show(ctx, chatID, messageID, r.t(ctx, key), Inline(yesNo(ctx, r, "notif:enok", "m:notif")))
}

func (r *UpdateRouter) toggleNotifications(ctx context.Context, chatID string, messageID int) {
	next := "1"
	if r.Settings.NotificationsEnabled(ctx) {
		next = "0"
	}
	_ = r.Settings.Store.Set(ctx, "telegram_notifications_enabled", next)
	r.showNotifications(ctx, chatID, messageID)
}

func (r *UpdateRouter) showDailyReportEnableConfirm(ctx context.Context, chatID string, messageID int) {
	key := "telegram.daily_report_enable_confirm"
	if r.Settings.DailyReportEnabled(ctx) {
		key = "telegram.daily_report_disable_confirm"
	}
	r.show(ctx, chatID, messageID, r.t(ctx, key), Inline(yesNo(ctx, r, "notif:daily:enok", "m:notif")))
}

func (r *UpdateRouter) toggleDailyReport(ctx context.Context, chatID string, messageID int) {
	next := "1"
	if r.Settings.DailyReportEnabled(ctx) {
		next = "0"
	}
	_ = r.Settings.Store.Set(ctx, "telegram_daily_report_enabled", next)
	r.showNotifications(ctx, chatID, messageID)
}

func (r *UpdateRouter) createConfig(ctx context.Context, name, typ string) (*models.AwgConfig, error) {
	protocolVersion := r.AWG.Versions.Latest()
	keys, err := r.AWG.GenerateKeyPair(ctx)
	if err != nil {
		return nil, err
	}
	junk := r.AWG.Versions.ProfileForConfig(protocolVersion).GenerateJunkParams()
	defaults := r.AWG.DefaultConfigAttributes()
	subnet := defaults["internal_subnet"].(string)
	if err := r.assertSubnetAvailable(ctx, subnet, 0); err != nil {
		return nil, err
	}
	serverAddress := defaults["server_address"].(string)
	if ip, n, ok := parseSubnetPrefix(subnet); ok {
		serverAddress = ip + ".1/" + n
	}
	iface, err := r.AWG.AllocateIface(ctx)
	if err != nil {
		return nil, err
	}
	port, err := r.AWG.NextFreeListenPort(ctx)
	if err != nil {
		return nil, err
	}
	if taken, _ := r.Configs.PortTaken(ctx, port, 0); taken {
		return nil, &validationError{r.t(ctx, "telegram.error_port_taken")}
	}
	cfg := &models.AwgConfig{
		Name: name, Type: typ, ProtocolVersion: protocolVersion, VnPolicy: "allow_all",
		Iface: iface, ListenPort: port, InternalSubnet: subnet, ServerAddress: serverAddress,
		ServerPrivateKey: keys.Private, ServerPublicKey: keys.Public,
		PeerDNS: defaults["peer_dns"].(string), ClientAllowedIPs: defaults["client_allowed_ips"].(string),
		PersistentKeepalive: defaults["persistent_keepalive"].(int), Enabled: true,
	}
	cfg.ApplyJunk(junk)
	if err := r.Configs.Create(ctx, cfg); err != nil {
		return nil, err
	}
	_ = r.AWG.ApplyConfig(ctx, nil, true, true)
	return cfg, nil
}

func (r *UpdateRouter) attachPeer(ctx context.Context, cfg *models.AwgConfig, client *models.VpnClient) error {
	if exists, _ := r.Peers.ExistsMembership(ctx, cfg.ID, client.ID); exists {
		return &validationError{r.t(ctx, "telegram.peer_already_bound")}
	}
	keys, err := r.AWG.GenerateKeyPair(ctx)
	if err != nil {
		return err
	}
	psk, err := awg.GeneratePresharedKey()
	if err != nil {
		return err
	}
	addr, err := r.AWG.NextClientAddress(ctx, cfg)
	if err != nil {
		return err
	}
	m := &models.AwgConfigPeer{
		AwgConfigID: cfg.ID, VpnClientID: client.ID, Enabled: true,
		PrivateKey: keys.Private, PublicKey: keys.Public, PresharedKey: &psk,
		Address: addr, ExtraAllowedIPs: []string{}, ExcludedClientIDs: []int64{},
	}
	if err := r.Peers.Create(ctx, m); err != nil {
		return err
	}
	r.AWG.InvalidateAllowedIPCache(cfg.ID, m.ID)
	_, _ = r.AWG.EnsurePeerKeys(ctx, m)
	_ = r.AWG.ApplyConfig(ctx, cfg, false, false)
	return nil
}

func (r *UpdateRouter) listLabel(ctx context.Context, tag string) string {
	key := "telegram.list_" + tag
	label := r.t(ctx, key)
	if label == key {
		return tag
	}
	return label
}

func (r *UpdateRouter) assertSubnetAvailable(ctx context.Context, subnet string, ignoreID int64) error {
	key := normalizeSubnetKey(subnet)
	if key == "" {
		return &validationError{r.t(ctx, "configs.invalid_internal_subnet")}
	}
	existing, _ := r.Configs.Subnets(ctx, ignoreID)
	for _, e := range existing {
		if normalizeSubnetKey(e) == key {
			return &validationError{r.t(ctx, "configs.subnet_taken")}
		}
	}
	return nil
}

func (r *UpdateRouter) pruneExcludedClientID(ctx context.Context, cfg *models.AwgConfig, clientID int64) {
	peers, _ := r.Peers.ListByConfig(ctx, cfg.ID)
	for i := range peers {
		m := &peers[i]
		var next []int64
		found := false
		for _, id := range m.ExcludedClientIDs {
			if id == clientID {
				found = true
				continue
			}
			next = append(next, id)
		}
		if found {
			m.ExcludedClientIDs = next
			_ = r.Peers.Update(ctx, m)
		}
	}
}

func (r *UpdateRouter) pruneClientFromZones(ctx context.Context, cfg *models.AwgConfig, clientID int64) {
	zones := cfg.VnZones()
	if len(zones.Rules) == 0 {
		return
	}
	changed := false
	var rules []models.VnRule
	for _, rule := range zones.Rules {
		src := diffIDs(rule.SrcClientIDs, clientID)
		dest := diffIDs(rule.DestClientIDs, clientID)
		if len(src) != len(rule.SrcClientIDs) || len(dest) != len(rule.DestClientIDs) {
			changed = true
		}
		if len(src) > 0 && len(dest) > 0 {
			rules = append(rules, models.VnRule{SrcClientIDs: src, DestClientIDs: dest})
		}
	}
	if changed {
		zones.Rules = rules
		cfg.SetVnZones(zones)
		_ = r.Configs.Update(ctx, cfg)
	}
}

func yesNo(ctx context.Context, r *UpdateRouter, yes, no string) [][]map[string]string {
	return [][]map[string]string{{Btn(r.t(ctx, "telegram.yes"), yes), Btn(r.t(ctx, "telegram.no"), no)}}
}

func parsePort(text string) (int, error) {
	var n int
	_, err := fmt.Sscanf(strings.TrimSpace(text), "%d", &n)
	return n, err
}

func parseSubnetPrefix(subnet string) (string, string, bool) {
	parts := strings.Split(strings.TrimSpace(subnet), "/")
	if len(parts) != 2 {
		return "", "", false
	}
	ip := net.ParseIP(parts[0])
	if ip == nil {
		return "", "", false
	}
	ip = ip.To4()
	if ip == nil {
		return "", "", false
	}
	octets := strings.Split(ip.String(), ".")
	if len(octets) != 4 {
		return "", "", false
	}
	return octets[0] + "." + octets[1] + "." + octets[2], parts[1], true
}

func normalizeSubnetKey(subnet string) string {
	parts := strings.Split(strings.TrimSpace(subnet), "/")
	if len(parts) != 2 {
		return ""
	}
	ip := net.ParseIP(parts[0])
	if ip == nil {
		return ""
	}
	ip4 := ip.To4()
	if ip4 == nil {
		return ""
	}
	var mask int
	if _, err := fmt.Sscanf(parts[1], "%d", &mask); err != nil || mask < 0 || mask > 32 {
		return ""
	}
	n := uint32(ip4[0])<<24 | uint32(ip4[1])<<16 | uint32(ip4[2])<<8 | uint32(ip4[3])
	var bits uint32
	if mask > 0 {
		bits = ^uint32(0) << (32 - mask)
	}
	n &= bits
	return fmt.Sprintf("%d.%d.%d.%d/%d", n>>24, (n>>16)&0xff, (n>>8)&0xff, n&0xff, mask)
}

func diffIDs(ids []int64, drop int64) []int64 {
	var out []int64
	for _, id := range ids {
		if id != drop {
			out = append(out, id)
		}
	}
	return out
}

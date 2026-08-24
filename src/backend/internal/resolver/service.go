package resolver

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/i18n"
)

type ApplyOpts struct {
	RefreshSubscriptions bool
	URLTestRoutingRetry  bool
	ForceSyncLists       bool
}

type Service struct {
	Cfg     config.Config
	Store   *Store
	KV      *KV
	Paths   Paths
	Files   FileHelper
	Lists   *Lists
	Merged  *Merged
	Parser  OutboundParser
	Builder OutboundBuilder
	Print   Fingerprint
	Fetch   *SubscriptionFetcher
	Clash   *Clash
	Egress  *Egress
	Scripts *Scripts
	AWGParse AWGConfParser
	AWGBuild AWGClientConfBuilder
	Docker  Docker
	Cache   *MemCache
	Ping    *PingService
	Speed   *SpeedTest
	Probe   *ProbeManager
}

func New(cfg config.Config, store *Store, kv *KV) *Service {
	docker := CLIDocker{Bin: cfg.DockerBin}
	paths := Paths{ConfigDir: cfg.AWGConfigDir}
	files := FileHelper{}
	cache := NewMemCache()
	parser := OutboundParser{}
	s := &Service{
		Cfg: cfg, Store: store, KV: kv, Paths: paths, Files: files,
		Parser: parser, Builder: OutboundBuilder{Parser: parser}, Print: Fingerprint{Parser: parser},
		Fetch: &SubscriptionFetcher{Parser: parser, Clash: ClashSubParser{Parser: parser}},
		Clash: &Clash{Docker: docker, Container: cfg.AWGContainer},
		Egress: &Egress{Docker: docker, Container: cfg.AWGContainer, KV: kv},
		Scripts: &Scripts{ConfigDir: cfg.AWGConfigDir, Docker: docker, Container: cfg.AWGContainer, Files: files},
		Docker: docker, Cache: cache,
		Merged: &Merged{Docker: docker, Container: cfg.AWGContainer, Paths: paths, Files: files},
	}
	s.Lists = &Lists{Store: store, KV: kv, Paths: paths, Files: files, Merged: s.Merged}
	s.Probe = &ProbeManager{Svc: s}
	s.Ping = &PingService{Svc: s}
	s.Speed = &SpeedTest{Svc: s}
	return s
}

func (s *Service) GatewayIP(cfg *AWGConfig) string {
	addr := cfg.ServerAddress
	if strings.Contains(addr, "/") {
		addr = strings.SplitN(addr, "/", 2)[0]
	}
	if addr == "" {
		return "10.66.66.1"
	}
	return addr
}

func (s *Service) ClientAllowedIPsPreview(cfg *AWGConfig) string {
	if !cfg.ResolverEnabled {
		return cfg.ClientAllowedIPs
	}
	return "0.0.0.0/0, ::/0"
}

func (s *Service) SubnetCIDR(cfg *AWGConfig) string {
	if cfg.InternalSubnet != "" {
		return cfg.InternalSubnet
	}
	return "10.66.66.0/24"
}

func (s *Service) NormalizeLists(ctx context.Context, lists, domains, subnets []string) (map[string][]string, error) {
	lists = uniqueStrings(lists)
	known := map[string]bool{}
	for _, t := range s.Lists.KnownListTags(ctx) {
		known[t] = true
	}
	for _, tag := range lists {
		if !known[tag] {
			return nil, FieldErr("community_lists", "resolver.unknown_list", map[string]string{"tag": tag})
		}
	}
	var hits []string
	excl := map[string]bool{}
	for _, t := range MutuallyExclusive {
		excl[t] = true
	}
	for _, t := range lists {
		if excl[t] {
			hits = append(hits, t)
		}
	}
	if len(hits) > 1 {
		return nil, FieldErr("community_lists", "resolver.cannot_select_conflicting_lists", nil)
	}
	dom, err := s.normalizeDomains(domains)
	if err != nil {
		return nil, err
	}
	sub, err := s.normalizeSubnets(subnets)
	if err != nil {
		return nil, err
	}
	return map[string][]string{"community_lists": lists, "user_domains": dom, "user_subnets": sub}, nil
}

func (s *Service) normalizeDomains(domains []string) ([]string, error) {
	var out []string
	for _, raw := range domains {
		for _, part := range splitTokens(raw) {
			part = strings.ToLower(strings.TrimSpace(part))
			if part == "" || strings.HasPrefix(part, "//") {
				continue
			}
			part = strings.TrimPrefix(strings.TrimPrefix(part, "http://"), "https://")
			if i := strings.IndexAny(part, "/:"); i >= 0 {
				part = part[:i]
			}
			part = strings.TrimLeft(part, ".")
			if !domainOK(part) {
				return nil, FieldErr("user_domains", "resolver.invalid_domain", map[string]string{"raw": raw})
			}
			out = append(out, part)
		}
	}
	return uniqueStrings(out), nil
}

func (s *Service) normalizeSubnets(subnets []string) ([]string, error) {
	var out []string
	for _, raw := range subnets {
		for _, part := range splitTokens(raw) {
			part = strings.TrimSpace(part)
			if part == "" || strings.HasPrefix(part, "//") {
				continue
			}
			if !strings.Contains(part, "/") {
				if isIPv4(part) {
					part += "/32"
				}
			}
			host, mask, ok := strings.Cut(part, "/")
			if !ok || !isIP(host) {
				return nil, FieldErr("user_subnets", "resolver.invalid_subnet", map[string]string{"raw": raw})
			}
			n := atoiDef(mask, -1)
			max := 32
			if strings.Contains(host, ":") {
				max = 128
			}
			if n < 0 || n > max {
				return nil, FieldErr("user_subnets", "resolver.invalid_mask", map[string]string{"raw": raw})
			}
			out = append(out, host+"/"+itoa(n))
		}
	}
	return uniqueStrings(out), nil
}

func (s *Service) AssertCanEnable(cfg *AWGConfig) error {
	if cfg.Type == "virtual_network" {
		return FieldErr("resolver_enabled", "resolver.unavailable_for_vn", nil)
	}
	if cfg.Type != "server" {
		return FieldErr("resolver_enabled", "resolver.server_configs_only", nil)
	}
	return nil
}

func (s *Service) AssertConnectionSelected(ctx context.Context, id *int64) (*Connection, error) {
	if id == nil || *id == 0 {
		return nil, FieldErr("connection_id", "resolver.select_connection", nil)
	}
	conn, err := s.Store.GetConnection(ctx, *id)
	if err != nil {
		return nil, err
	}
	if conn == nil {
		return nil, FieldErr("connection_id", "resolver.connection_not_found", nil)
	}
	if !conn.Enabled {
		return nil, FieldErr("connection_id", "resolver.connection_disabled", nil)
	}
	return conn, nil
}

func (s *Service) Apply(ctx context.Context, opts ApplyOpts) error {
	locale := Locale(ctx)
	configs, err := s.Store.EnabledServerConfigs(ctx)
	if err != nil {
		return err
	}
	hasConn, _ := s.Store.HasEnabledConnections(ctx)
	_ = os.MkdirAll(s.Paths.ConfigDir, 0o755)

	if len(configs) == 0 && !hasConn {
		s.syncAWGClientConfs(ctx)
		_ = os.Remove(s.Paths.SingBoxConfigPath())
		_ = os.WriteFile(s.Paths.ResolverIfacesPath(), []byte(""), 0o644)
		_ = os.WriteFile(s.Paths.ProxyCIDRsAllPath(), []byte(""), 0o644)
		s.writeStatusFile(map[string]any{
			"enabled": false, "healthy": true,
			"message": i18n.T(locale, "resolver.disabled"), "updated_at": isoNow(),
		})
		_ = s.ReloadSingBox(ctx)
		return nil
	}

	if err := func() error {
		s.syncAWGClientConfs(ctx)
		if opts.RefreshSubscriptions {
			s.refreshSubscriptionConnections(ctx)
		}
		urltestBefore := map[int64]string{}
		connsByID := map[int64]*Connection{}
		allConns, _ := s.Store.ListConnections(ctx)
		for _, c := range allConns {
			connsByID[c.ID] = c
		}
		for _, cfg := range configs {
			if cfg.ConnectionID == nil {
				continue
			}
			conn := connsByID[*cfg.ConnectionID]
			if conn != nil && conn.IsURLTestMode() {
				urltestBefore[conn.ID] = s.RoutingOutboundTag(ctx, conn)
			}
		}
		s.Merged.ResetChangeFlags()
		sb, err := s.BuildSingBoxConfig(ctx, configs, opts.ForceSyncLists)
		if err != nil {
			return err
		}
		sb = stripLegacyInboundFields(sb)
		js, err := marshalPretty(sb)
		if err != nil {
			return runtimeKey("resolver.singbox_serialize_failed")
		}
		singChanged, err := s.Files.WriteIfChanged(s.Paths.SingBoxConfigPath(), js)
		if err != nil {
			return err
		}
		var ifaces []string
		ifaceReject := map[string]bool{}
		for _, c := range configs {
			ifaces = append(ifaces, c.Iface)
			ifaceReject[c.Iface] = c.ResolverRejectQUIC
		}
		_, _ = s.Files.WriteIfChanged(s.Paths.ResolverIfacesPath(), strings.Join(ifaces, "\n")+"\n")
		_, _ = s.Scripts.EnsureResolverMarkScripts()
		if len(configs) > 0 {
			s.Scripts.RefreshMarks(ctx, ifaces)
		}
		now := time.Now()
		for _, c := range configs {
			c.ResolverUpdatedAt = &now
			c.ResolverLastError = nil
			_ = s.Store.UpdateConfigResolver(ctx, c)
		}
		msg := i18n.T(locale, "resolver.connections_active_resolver_off")
		if len(configs) > 0 {
			msg = i18n.T(locale, "resolver.config_applied")
		}
		s.writeStatusFile(map[string]any{
			"enabled": len(configs) > 0, "healthy": true, "message": msg,
			"ifaces": ifaces, "updated_at": isoNow(),
		})
		if singChanged || s.Merged.ApplyMergedChanged || s.Merged.ApplyProxyCIDRsChg {
			if err := s.ReloadSingBox(ctx); err != nil {
				return err
			}
		}
		if opts.URLTestRoutingRetry && len(urltestBefore) > 0 && s.Clash.WaitForAPI(ctx, 25, 200*time.Millisecond) {
			for id, tagBefore := range urltestBefore {
				conn, _ := s.Store.GetConnection(ctx, id)
				if conn != nil && s.RoutingOutboundTag(ctx, conn) != tagBefore {
					return s.Apply(ctx, ApplyOpts{RefreshSubscriptions: opts.RefreshSubscriptions})
				}
			}
		}
		return nil
	}(); err != nil {
		msg := TranslateErr(locale, err)
		log.Printf("resolver apply failed: %s", msg)
		for _, c := range configs {
			m := msg
			c.ResolverLastError = &m
			_ = s.Store.UpdateConfigResolver(ctx, c)
		}
		s.writeStatusFile(map[string]any{
			"enabled": len(configs) > 0, "healthy": false, "message": msg, "updated_at": isoNow(),
		})
		return err
	}
	return nil
}

func (s *Service) BuildSingBoxConfig(ctx context.Context, configs []*AWGConfig, forceSync bool) (map[string]any, error) {
	communityTags := s.collectCommunityTags(configs)
	if forceSync {
		if err := s.Lists.SyncCommunity(ctx, communityTags, true); err != nil {
			return nil, err
		}
	} else if err := s.Lists.AssertSelectedListsOnDisk(ctx, communityTags); err != nil {
		return nil, err
	}

	ruleSets := []map[string]any{}
	dnsRules := []map[string]any{
		{"query_type": []string{"HTTPS"}, "action": "reject"},
		{"domain_suffix": []string{"use-application-dns.net"}, "action": "reject"},
	}
	var allProxyCIDRs []string
	var quicReject []map[string]any
	sniffIn := []string{TProxyInboundTag, UDPTProxyInbound, "dns-in"}
	routeRules := []map[string]any{
		{"action": "sniff", "timeout": "300ms", "inbound": sniffIn},
		{"port": 53, "action": "hijack-dns"},
		{"protocol": "dns", "action": "hijack-dns"},
	}

	allConns, _ := s.Store.EnabledConnections(ctx)
	built := s.Builder.BuildForConnections(allConns)
	outbounds := built.Outbounds
	tagsAdded := built.TagsAdded
	connsByID := map[int64]*Connection{}
	for _, c := range allConns {
		connsByID[c.ID] = c
	}

	for _, cfg := range configs {
		routingTag := s.routingTagForConfig(cfg, connsByID)
		source := []string{s.SubnetCIDR(cfg)}
		lists := cfg.CommunityLists
		domains := cfg.UserDomains
		subnets := cfg.UserSubnets
		if cfg.ResolverRejectQUIC {
			quicReject = append(quicReject, map[string]any{
				"inbound": []string{UDPTProxyInbound}, "source_ip_cidr": source,
				"protocol": "quic", "action": "reject",
			})
		}
		for _, tag := range lists {
			if !s.Lists.IsCustomTag(tag) && !fileExists(s.Paths.CommunityRulesetPath(tag)) {
				return nil, runtimeKeyParams("resolver.ruleset_not_on_disk_refresh", map[string]string{"tag": tag})
			}
		}
		if len(lists) == 0 && len(domains) == 0 && len(subnets) == 0 {
			routeRules = append(routeRules, map[string]any{
				"source_ip_cidr": source, "ip_cidr": []string{FakeIPCIDR},
				"action": "route", "outbound": routingTag,
			})
			continue
		}
		mergedTag, ipTag, ipCIDRs, err := s.Merged.WriteMergedRulesetForConfig(ctx, cfg)
		if err != nil {
			return nil, err
		}
		ruleSets = append(ruleSets, map[string]any{
			"type": "local", "tag": mergedTag, "format": "source",
			"path": fmt.Sprintf("/config/rulesets/merged_cfg_%d.json", cfg.ID),
		})
		if len(lists) > 0 || len(domains) > 0 {
			dnsRules = append(dnsRules, map[string]any{
				"source_ip_cidr": source, "rule_set": []string{mergedTag},
				"action": "route", "server": "fakeip", "rewrite_ttl": FakeIPRewriteTTL,
			})
			routeRules = append(routeRules, map[string]any{
				"source_ip_cidr": source, "ip_cidr": []string{FakeIPCIDR},
				"action": "route", "outbound": routingTag,
			})
			routeRules = append(routeRules, map[string]any{
				"inbound": []string{TProxyInboundTag, UDPTProxyInbound},
				"source_ip_cidr": source, "rule_set": []string{mergedTag},
				"action": "route", "outbound": routingTag,
			})
		}
		if ipTag != nil && len(ipCIDRs) > 0 {
			ruleSets = append(ruleSets, map[string]any{
				"type": "local", "tag": *ipTag, "format": "source",
				"path": fmt.Sprintf("/config/rulesets/merged_cfg_%d_ip.json", cfg.ID),
			})
			routeRules = append(routeRules, map[string]any{
				"inbound": []string{TProxyInboundTag, UDPTProxyInbound},
				"source_ip_cidr": source, "rule_set": []string{*ipTag},
				"action": "route", "outbound": routingTag,
			})
			allProxyCIDRs = append(allProxyCIDRs, ipCIDRs...)
		}
		if len(lists) == 0 && len(domains) == 0 {
			routeRules = append(routeRules, map[string]any{
				"source_ip_cidr": source, "ip_cidr": []string{FakeIPCIDR},
				"action": "route", "outbound": routingTag,
			})
		}
	}
	_, _ = s.Merged.WriteProxyCIDRsAll(allProxyCIDRs)
	for _, q := range quicReject {
		insertAfterDNSHijack(&routeRules, q)
	}
	dnsRules = append(dnsRules, map[string]any{"action": "route", "server": "remote"})

	fallbackDNS := "1.1.1.1"
	fallbackSet := false
	dnsServers := []map[string]any{
		{"type": "udp", "tag": "bootstrap", "server": "8.8.8.8", "server_port": 53},
	}
	for _, cfg := range configs {
		upstream := strings.TrimSpace(strPtrVal(cfg.ResolverDNS))
		if upstream == "" {
			upstream = "1.1.1.1"
		}
		if !fallbackSet {
			fallbackDNS = upstream
			fallbackSet = true
		}
		tag := fmt.Sprintf("remote_cfg_%d", cfg.ID)
		dnsServers = append(dnsServers, map[string]any{
			"type": "udp", "tag": tag, "server": upstream, "server_port": 53,
		})
		dnsRules = append(dnsRules[:len(dnsRules)-1], map[string]any{
			"source_ip_cidr": []string{s.SubnetCIDR(cfg)}, "action": "route", "server": tag,
		}, dnsRules[len(dnsRules)-1])
	}
	dnsServers = append(dnsServers,
		map[string]any{"type": "udp", "tag": "remote", "server": fallbackDNS, "server_port": 53},
		map[string]any{"type": "fakeip", "tag": "fakeip", "inet4_range": FakeIPCIDR},
	)

	inbounds := []map[string]any{
		{"type": "direct", "tag": "dns-in", "listen": "0.0.0.0", "listen_port": DNSListenPort},
		{"type": "redirect", "tag": TProxyInboundTag, "listen": TProxyListen, "listen_port": TProxyPort, "tcp_fast_open": true},
		{"type": "tproxy", "tag": UDPTProxyInbound, "listen": TProxyListen, "listen_port": UDPTProxyPort, "network": "udp", "udp_fragment": true},
	}
	inbounds = s.withTelegramMixed(ctx, inbounds, &outbounds, &routeRules, tagsAdded)

	return map[string]any{
		"log": map[string]any{"level": "warn", "timestamp": true},
		"dns": map[string]any{
			"servers": dnsServers, "rules": dnsRules, "final": "remote",
			"independent_cache": true, "cache_capacity": 4096, "strategy": "ipv4_only",
		},
		"inbounds":  inbounds,
		"outbounds": outbounds,
		"route": map[string]any{
			"rules": routeRules, "rule_set": ruleSets, "final": "direct",
			"auto_detect_interface": false, "default_interface": s.Egress.Resolve(ctx),
			"default_domain_resolver": "bootstrap",
		},
		"experimental": map[string]any{
			"cache_file": map[string]any{
				"enabled": true, "path": "/config/sing-box-cache.db",
				"store_fakeip": true, "store_rdrc": false,
			},
			"clash_api": map[string]any{"external_controller": ClashAPIAddr, "default_mode": "rule"},
		},
	}, nil
}

func (s *Service) withTelegramMixed(ctx context.Context, inbounds []map[string]any, outbounds *[]map[string]any, routeRules *[]map[string]any, tagsAdded map[string]bool) []map[string]any {
	ids := s.Store.TelegramConnectionIDs(ctx, s.KV)
	if len(ids) == 0 {
		return inbounds
	}
	var tags []string
	for _, id := range ids {
		tag := fmt.Sprintf("conn_%d", id)
		if tagsAdded[tag] {
			tags = append(tags, tag)
		}
	}
	if len(tags) == 0 {
		return inbounds
	}
	user, pass := s.Store.TelegramMixedAuth(ctx, s.KV)
	if user == "" {
		user = "tg"
	}
	if pass == "" {
		pass = "tg"
	}
	inbounds = append(inbounds, map[string]any{
		"type": "mixed", "tag": TelegramMixedTag, "listen": "0.0.0.0", "listen_port": TelegramMixedPort,
		"users": []map[string]any{{"username": user, "password": pass}},
	})
	outTag := tags[0]
	if len(tags) > 1 {
		outTag = TelegramOutboundTag
		if !tagsAdded[outTag] {
			*outbounds = append(*outbounds, map[string]any{
				"type": "urltest", "tag": outTag, "outbounds": tags,
				"url": DelayTestURL, "interval": "3m",
			})
		}
	}
	insertAfterDNSHijack(routeRules, map[string]any{
		"inbound": []string{TelegramMixedTag}, "action": "route", "outbound": outTag,
	})
	return inbounds
}

func insertAfterDNSHijack(rules *[]map[string]any, rule map[string]any) {
	idx := 0
	for i, existing := range *rules {
		if strVal(existing["action"]) == "hijack-dns" {
			idx = i + 1
		}
	}
	r := *rules
	*rules = append(r[:idx], append([]map[string]any{rule}, r[idx:]...)...)
}

func stripLegacyInboundFields(cfg map[string]any) map[string]any {
	inbounds, _ := cfg["inbounds"].([]map[string]any)
	legacy := []string{"sniff", "sniff_override_destination", "sniff_timeout", "domain_strategy", "udp_disable_domain_unmapping"}
	for _, in := range inbounds {
		for _, k := range legacy {
			delete(in, k)
		}
	}
	return cfg
}

func (s *Service) routingTagForConfig(cfg *AWGConfig, conns map[int64]*Connection) string {
	if cfg.ConnectionID == nil {
		return "direct"
	}
	conn := conns[*cfg.ConnectionID]
	if conn == nil || !conn.Enabled {
		return "direct"
	}
	if conn.IsURLTestMode() {
		return conn.OutboundTag()
	}
	if conn.Outbound != nil && strVal(conn.Outbound["type"]) != "" {
		return conn.OutboundTag()
	}
	return "direct"
}

func (s *Service) RoutingOutboundTag(ctx context.Context, conn *Connection) string {
	if !conn.IsURLTestMode() {
		return conn.OutboundTag()
	}
	parent := conn.OutboundTag()
	fallback := conn.ChildOutboundTag(1)
	resp := s.Clash.Request(ctx, "/proxies/"+parent, nil, 3*time.Second, false)
	if resp.OK && resp.Body != nil {
		now := strVal(resp.Body["now"])
		if now != "" && strings.HasPrefix(now, parent+"_") {
			return now
		}
	}
	return fallback
}

func (s *Service) collectCommunityTags(configs []*AWGConfig) []string {
	seen := map[string]bool{}
	var tags []string
	for _, c := range configs {
		for _, t := range c.CommunityLists {
			if t != "" && !seen[t] {
				seen[t] = true
				tags = append(tags, t)
			}
		}
	}
	return tags
}

func (s *Service) syncAWGClientConfs(ctx context.Context) {
	dir := s.Paths.ConfigDir
	_ = os.MkdirAll(dir, 0o755)
	conns, _ := s.Store.EnabledConnections(ctx)
	keep := map[string]bool{}
	for _, conn := range conns {
		if conn.ConfigType != "awg" || conn.AWGConf == nil {
			continue
		}
		iface := s.AWGBuild.IfaceName(conn.ID)
		keep[iface] = true
		parsed, err := s.AWGParse.Parse(*conn.AWGConf)
		if err != nil {
			log.Printf("awg client conf sync failed for conn_%d: %v", conn.ID, err)
			continue
		}
		ver := first(strPtrVal(conn.ProtocolVersion), "2.0")
		body := s.AWGBuild.Build(parsed, ver)
		path := filepath.Join(dir, iface+".conf")
		if ch, _ := s.Files.WriteIfChanged(path, body); ch {
			_ = os.Chtimes(path, time.Now(), time.Now())
		}
	}
	matches, _ := filepath.Glob(filepath.Join(dir, "awgc*.conf"))
	awgcRE := regexp.MustCompile(`^awgc\d+$`)
	for _, path := range matches {
		iface := strings.TrimSuffix(filepath.Base(path), ".conf")
		if !awgcRE.MatchString(iface) {
			continue
		}
		if !keep[iface] {
			_ = os.Remove(path)
		}
	}
}

func (s *Service) refreshSubscriptionConnections(ctx context.Context) bool {
	conns, _ := s.Store.EnabledConnections(ctx)
	anyChanged := false
	for _, conn := range conns {
		if !conn.IsSubscription() {
			continue
		}
		u := strings.TrimSpace(strPtrVal(conn.SubscriptionURL))
		if u == "" {
			continue
		}
		hashBefore := s.Print.Hash(conn)
		body := strings.TrimSpace(strPtrVal(conn.SubscriptionBody))
		var bodyPtr *string
		if body != "" {
			bodyPtr = &body
		}
		nodes, err := s.Fetch.FetchMerged(u, strPtrVal(bodyPtr))
		if err != nil {
			log.Printf("subscription refresh failed for conn %d: %v", conn.ID, err)
			continue
		}
		if !s.Print.NodesEqual(conn.SubscriptionNodes, nodes) {
			conn.SubscriptionNodes = nodes
		}
		now := time.Now()
		conn.SubscriptionFetchedAt = &now
		if conn.SubscriptionMode != nil && *conn.SubscriptionMode == ModeSingle {
			selected := strPtrVal(conn.SubscriptionSelected)
			var node map[string]any
			if selected != "" {
				for _, n := range nodes {
					if strVal(n["key"]) == selected {
						node = n
						break
					}
				}
			}
			if node == nil && len(nodes) > 0 {
				node = nodes[0]
				k := strVal(node["key"])
				conn.SubscriptionSelected = &k
			}
			if node != nil {
				if ob, ok := node["outbound"].(map[string]any); ok {
					conn.Outbound = ob
				}
			}
		} else if conn.SubscriptionMode != nil && *conn.SubscriptionMode == ModeURLTest {
			conn.Outbound = map[string]any{"type": "urltest"}
		}
		if s.Print.Hash(conn) != hashBefore {
			anyChanged = true
		}
		_ = s.Store.UpdateConnection(ctx, conn)
	}
	if anyChanged {
		s.Probe.RebuildAndMaybeReload(ctx)
	}
	return anyChanged
}

func (s *Service) IsSingBoxRunning(ctx context.Context) bool {
	r, err := s.Docker.Exec(ctx, s.Cfg.AWGContainer, []string{"sh", "-c",
		`pid=$(cat /run/sing-box.pid 2>/dev/null); if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then echo yes; elif pgrep -f "/usr/local/bin/sing-box run -c /config/sing-box.json" >/dev/null 2>&1; then echo yes; else echo no; fi`},
		10*time.Second)
	if err != nil {
		return false
	}
	return strings.TrimSpace(r.Stdout) == "yes"
}

func (s *Service) ReloadSingBox(ctx context.Context) error {
	r, err := s.Docker.Exec(ctx, s.Cfg.AWGContainer, []string{"sh", "-c",
		`if [ -x /config/reload-singbox.sh ]; then /config/reload-singbox.sh; else /usr/local/bin/reload-singbox.sh; fi`},
		30*time.Second)
	if err != nil {
		return err
	}
	if !r.Successful() {
		msg := strings.TrimSpace(r.Stderr + "\n" + r.Stdout)
		if msg == "" {
			msg = "sing-box reload failed"
		}
		log.Printf("reload-singbox: %s", msg)
		return fmt.Errorf("%s", msg)
	}
	return nil
}

func (s *Service) writeStatusFile(payload map[string]any) {
	b, _ := json.MarshalIndent(payload, "", "    ")
	_ = os.WriteFile(s.Paths.ResolverStatusPath(), append(b, '\n'), 0o644)
}

func (s *Service) Status(ctx context.Context) map[string]any {
	locale := Locale(ctx)
	file := map[string]any{}
	if raw, err := os.ReadFile(s.Paths.ResolverStatusPath()); err == nil {
		_ = json.Unmarshal(raw, &file)
	}
	configs, _ := s.Store.ListServerConfigs(ctx)
	conns, _ := s.Store.ListConnections(ctx)
	connItems := make([]map[string]any, 0, len(conns))
	connsByID := map[int64]*Connection{}
	for _, c := range conns {
		connsByID[c.ID] = c
		connItems = append(connItems, map[string]any{
			"id": c.ID, "name": c.Name, "comment": c.Comment, "config_type": c.ConfigType,
			"outbound_type": c.Outbound["type"], "enabled": c.Enabled, "tag": c.OutboundTag(),
		})
	}
	enabledCount := 0
	var cfgItems []map[string]any
	for _, c := range configs {
		if c.ResolverEnabled {
			enabledCount++
		}
		var connName, connTag any
		if c.ConnectionID != nil {
			if conn := connsByID[*c.ConnectionID]; conn != nil {
				connName = conn.Name
				connTag = conn.OutboundTag()
			}
		}
		clientDNS := c.PeerDNS
		if c.ResolverEnabled {
			clientDNS = s.GatewayIP(c)
		}
		resDNS := "1.1.1.1"
		if c.ResolverDNS != nil && *c.ResolverDNS != "" {
			resDNS = *c.ResolverDNS
		}
		cfgItems = append(cfgItems, map[string]any{
			"id": c.ID, "name": c.Name, "iface": c.Iface, "type": c.Type, "enabled": c.Enabled,
			"resolver_enabled": c.ResolverEnabled, "resolver_reject_quic": c.ResolverRejectQUIC,
			"connection_id": c.ConnectionID, "connection_name": connName, "connection_tag": connTag,
			"community_lists": c.CommunityLists, "user_domains": c.UserDomains, "user_subnets": c.UserSubnets,
			"resolver_updated_at": isoTime(c.ResolverUpdatedAt), "resolver_last_error": c.ResolverLastError,
			"gateway_ip": s.GatewayIP(c), "resolver_dns": resDNS, "client_dns": clientDNS,
			"client_allowed_ips_preview": s.ClientAllowedIPsPreview(c),
			"has_peer_extra_allowed_ips": c.HasPeerExtraAllowed,
		})
	}
	singRunning := s.IsSingBoxRunning(ctx)
	healthy := true
	if enabledCount > 0 {
		healthy = singRunning
		if h, ok := file["healthy"].(bool); ok {
			healthy = healthy && h
		}
		for _, c := range configs {
			if c.ResolverEnabled && strPtrVal(c.ResolverLastError) != "" {
				healthy = false
			}
		}
	}
	msg := strVal(file["message"])
	if msg == "" {
		if enabledCount > 0 {
			msg = "OK"
		} else {
			msg = i18n.T(locale, "resolver.disabled")
		}
	}
	return map[string]any{
		"enabled": enabledCount > 0, "healthy": healthy, "singbox_running": singRunning,
		"fakeip_cidr": FakeIPCIDR, "message": msg, "updated_at": file["updated_at"],
		"needs_initial_sync": s.Lists.NeedsInitialSync(ctx),
		"community_lists":    CommunityListCatalog(),
		"custom_lists":       s.Lists.CustomListCatalog(ctx),
		"connections":        connItems,
		"configs":            cfgItems,
	}
}

func (s *Service) Diagnose(ctx context.Context) map[string]any {
	return diagnose(ctx, s)
}

func (s *Service) WaitForClashAPI(ctx context.Context) bool {
	return s.Clash.WaitForAPI(ctx, 25, 200*time.Millisecond)
}

func (s *Service) TrafficByOutboundTag(ctx context.Context) map[string]map[string]any {
	return s.Clash.TrafficByOutboundTag(ctx)
}

package diagnostics

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/docker"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/panelops"
	"github.com/awggui/backend/internal/settings"
	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
	"github.com/awggui/backend/internal/system"
)

var maskJSONKeys = map[string]bool{
	"password": true, "private_key": true, "uuid": true, "auth": true,
	"token": true, "secret": true, "psk": true, "api_key": true,
}

type Service struct {
	Cfg      config.Config
	DB       *sql.DB
	Docker   *docker.Runtime
	Stats    *stats.Service
	Sys      *system.Service
	Settings *settings.Store
	Configs  *store.Configs
	Peers    *store.Peers
	PanelOps *panelops.Client
	Locale   string
}

func New(cfg config.Config, db *sql.DB, d *docker.Runtime, st *stats.Service, sys *system.Service, set *settings.Store, configs *store.Configs, peers *store.Peers, ops *panelops.Client) *Service {
	return &Service{Cfg: cfg, DB: db, Docker: d, Stats: st, Sys: sys, Settings: set, Configs: configs, Peers: peers, PanelOps: ops}
}

func (s *Service) Status(ctx context.Context, locale string) map[string]any {
	containers := s.containerChecks(ctx, locale)
	singbox := s.singBoxRunningCheck(ctx, locale)
	ifaces := s.ifaceStatusList(ctx, locale, nil)
	cfgs, _ := s.Configs.All(ctx)
	configRows := make([]map[string]any, 0, len(cfgs))
	for _, c := range cfgs {
		typeLabel := i18n.T(locale, "api.type_server")
		if c.Type == "virtual_network" {
			typeLabel = i18n.T(locale, "api.type_virtual_network")
		}
		configRows = append(configRows, map[string]any{
			"id": c.ID, "name": c.Name, "type": c.Type, "type_label": typeLabel,
			"iface": c.Iface, "enabled": c.Enabled, "resolver_enabled": c.ResolverEnabled,
		})
	}
	containersOk := true
	for _, c := range containers {
		if ok, _ := c["ok"].(bool); !ok {
			containersOk = false
			break
		}
	}
	ifacesOk := true
	for _, iface := range ifaces {
		if ok, _ := iface["ok"].(bool); !ok {
			ifacesOk = false
			break
		}
	}
	singOk, _ := singbox["ok"].(bool)
	endpoint := os.Getenv("SERVER_ENDPOINT")
	if endpoint == "" {
		endpoint = "auto"
	}
	if s.Settings != nil {
		endpoint = s.Settings.GetValue(ctx, "server_endpoint", endpoint)
	}
	tz := os.Getenv("TZ")
	if tz == "" {
		tz = "UTC"
	}
	if s.Settings != nil {
		tz = s.Settings.GetValue(ctx, "timezone", tz)
	}
	panelPort, _ := strconv.Atoi(s.Cfg.PanelPort)
	if panelPort == 0 {
		panelPort = 8877
	}
	return map[string]any{
		"ok":         containersOk && singOk && ifacesOk,
		"containers": containers,
		"singbox":    singbox,
		"ping_probe": s.pingProbeStatus(ctx),
		"ifaces":     ifaces,
		"configs":    configRows,
		"system": map[string]any{
			"endpoint":      strings.TrimSpace(endpoint),
			"panel_port":    panelPort,
			"timezone":      tz,
			"awg_container": s.Stats.ContainerName(),
		},
		"updated_at": time.Now().Format(time.RFC3339),
	}
}

func (s *Service) Run(ctx context.Context, locale string, configIDs []int64) map[string]any {
	cfgs, _ := s.Configs.All(ctx)
	if configIDs != nil && len(configIDs) > 0 {
		want := map[int64]bool{}
		for _, id := range configIDs {
			want[id] = true
		}
		filtered := cfgs[:0]
		for _, c := range cfgs {
			if want[c.ID] {
				filtered = append(filtered, c)
			}
		}
		cfgs = filtered
	}

	hints := []string{}
	groups := []map[string]any{}

	runtime := s.groupRuntime(ctx, locale)
	groups = append(groups, runtime)
	hints = append(hints, stringSlice(runtime["hints"])...)

	awgGroup := s.groupAwgIfaces(ctx, locale, cfgs)
	groups = append(groups, awgGroup)
	hints = append(hints, stringSlice(awgGroup["hints"])...)

	resolverGroup := s.groupResolver(ctx, locale, cfgs)
	groups = append(groups, resolverGroup)
	hints = append(hints, stringSlice(resolverGroup["hints"])...)

	outboundsGroup := s.groupOutbounds(ctx, locale, cfgs)
	groups = append(groups, outboundsGroup)
	hints = append(hints, stringSlice(outboundsGroup["hints"])...)

	vnGroup := s.groupVirtualNetworks(ctx, locale, cfgs)
	groups = append(groups, vnGroup)
	hints = append(hints, stringSlice(vnGroup["hints"])...)

	allOk, anyFail, anyPass := true, false, false
	outGroups := make([]map[string]any, 0, len(groups))
	for _, g := range groups {
		ok, _ := g["ok"].(bool)
		if !ok {
			allOk = false
			anyFail = true
		} else {
			anyPass = true
		}
		cp := map[string]any{}
		for k, v := range g {
			if k == "hints" {
				continue
			}
			cp[k] = v
		}
		outGroups = append(outGroups, cp)
	}
	status := "success"
	if !allOk {
		if anyPass && anyFail {
			status = "warning"
		} else {
			status = "error"
		}
	}
	return map[string]any{
		"ok":         allOk,
		"status":     status,
		"groups":     outGroups,
		"hints":      uniqueStrings(hints),
		"updated_at": time.Now().Format(time.RFC3339),
	}
}

func (s *Service) SingBoxConfig(locale string) map[string]any {
	path := s.Stats.ConfigDir() + "/sing-box.json"
	st, err := os.Stat(path)
	if err != nil {
		return map[string]any{
			"ok": false, "masked": true, "content": nil,
			"error": i18n.T(locale, "system.singbox_json_not_found"), "updated_at": nil,
		}
	}
	raw, _ := os.ReadFile(path)
	var decoded any
	if json.Unmarshal(raw, &decoded) != nil {
		return map[string]any{
			"ok": false, "masked": true, "content": maskAwgConfText(string(raw)),
			"error": i18n.T(locale, "system.invalid_json_masked_raw"),
			"updated_at": st.ModTime().Format(time.RFC3339),
		}
	}
	masked := maskJSONSecrets(decoded)
	content, err := json.MarshalIndent(masked, "", "    ")
	if err != nil {
		return map[string]any{"ok": false, "masked": true, "content": nil, "error": err.Error(), "updated_at": st.ModTime().Format(time.RFC3339)}
	}
	return map[string]any{
		"ok": true, "masked": true, "content": string(content) + "\n", "error": nil,
		"updated_at": st.ModTime().Format(time.RFC3339),
	}
}

func (s *Service) AwgConfigs(ctx context.Context, locale string) map[string]any {
	dir := s.Stats.ConfigDir()
	cfgs, _ := s.Configs.All(ctx)
	byIface := map[string]models.AwgConfig{}
	for _, c := range cfgs {
		byIface[c.Iface] = c
	}
	matches, _ := filepath.Glob(dir + "/awg*.conf")
	items := []map[string]any{}
	clientExitRE := regexp.MustCompile(`^awgc\d+$`)
	for _, path := range matches {
		iface := strings.TrimSuffix(filepath.Base(path), ".conf")
		raw, _ := os.ReadFile(path)
		st, _ := os.Stat(path)
		updated := ""
		if st != nil {
			updated = st.ModTime().Format(time.RFC3339)
		}
		cfg, ok := byIface[iface]
		isClientExit := clientExitRE.MatchString(iface)
		name := iface
		var typ any
		var typeLabel any
		var configID any
		if ok {
			name = cfg.Name
			typ = cfg.Type
			configID = cfg.ID
			if cfg.Type == "virtual_network" {
				typeLabel = i18n.T(locale, "api.type_virtual_network")
			} else {
				typeLabel = i18n.T(locale, "api.type_server")
			}
		} else if isClientExit {
			name = "connection exit " + iface
			typ = "connection_exit"
			typeLabel = "AWG connection exit"
		}
		items = append(items, map[string]any{
			"iface": iface, "name": name, "type": typ, "type_label": typeLabel,
			"config_id": configID, "content": maskAwgConfText(string(raw)), "updated_at": updated,
		})
	}
	for i := 0; i < len(items); i++ {
		for j := i + 1; j < len(items); j++ {
			ai, _ := items[i]["iface"].(string)
			aj, _ := items[j]["iface"].(string)
			if aj < ai {
				items[i], items[j] = items[j], items[i]
			}
		}
	}
	return map[string]any{"ok": true, "masked": true, "configs": items}
}

func (s *Service) containerChecks(ctx context.Context, locale string) []map[string]any {
	list := []struct{ name, label string }{
		{"awggui-awg", "AmneziaWG"},
		{"awggui-app", "panel_api"},
		{"awggui-db", "MariaDB"},
		{"awggui-caddy", "Caddy"},
		{"awggui-docker-proxy", "docker_proxy"},
		{"awggui-panel-ops", "panel_ops"},
	}
	out := make([]map[string]any, 0, len(list))
	for _, c := range list {
		name := c.name
		if name == "awggui-awg" {
			name = s.Stats.ContainerName()
		}
		running := s.Stats.IsContainerRunning(ctx, name)
		label := c.label
		if c.label == "panel_api" {
			label = i18n.T(locale, "system.container_panel_api")
		}
		detail := "stopped"
		if running {
			detail = "running"
		}
		out = append(out, map[string]any{
			"name": name, "label": label, "ok": running, "running": running, "detail": detail,
		})
	}
	return out
}

func (s *Service) singBoxRunningCheck(ctx context.Context, locale string) map[string]any {
	running := false
	detail := i18n.T(locale, "system.awg_container_not_running")
	if s.Stats.IsContainerRunning(ctx, "") {
		running = s.Sys.IsSingBoxRunning(ctx)
		if running {
			detail = i18n.T(locale, "system.process_running")
		} else {
			detail = i18n.T(locale, "system.process_not_found")
		}
	}
	return map[string]any{"ok": running, "running": running, "label": "sing-box", "detail": detail}
}

func (s *Service) ifaceStatusList(ctx context.Context, locale string, configs []models.AwgConfig) []map[string]any {
	if configs == nil {
		configs, _ = s.Configs.ListEnabled(ctx)
	}
	awgUp := s.Stats.IsContainerRunning(ctx, "")
	out := []map[string]any{}
	for _, c := range configs {
		if !awgUp {
			out = append(out, map[string]any{
				"config_id": c.ID, "name": c.Name, "iface": c.Iface, "type": c.Type,
				"ok": false, "up": false, "detail": i18n.T(locale, "system.awg_container_not_running"),
			})
			continue
		}
		up := s.Stats.IfaceIsUp(ctx, c.Iface)
		detail := "up"
		if !up {
			detail = i18n.T(locale, "system.iface_down_or_missing")
		}
		out = append(out, map[string]any{
			"config_id": c.ID, "name": c.Name, "iface": c.Iface, "type": c.Type,
			"ok": up, "up": up, "detail": detail,
		})
	}
	return out
}

func (s *Service) groupRuntime(ctx context.Context, locale string) map[string]any {
	checks := []map[string]any{}
	hints := []string{}
	for _, c := range s.containerChecks(ctx, locale) {
		name, _ := c["name"].(string)
		label, _ := c["label"].(string)
		ok, _ := c["ok"].(bool)
		detail, _ := c["detail"].(string)
		checks = append(checks, map[string]any{
			"id": "container_" + name, "ok": ok, "label": label + " (" + name + ")", "detail": detail,
		})
		if !ok {
			hints = append(hints, i18n.Tf(locale, "system.container_not_running_hint", map[string]string{"name": name}))
		}
	}
	singbox := s.singBoxRunningCheck(ctx, locale)
	ok, _ := singbox["ok"].(bool)
	checks = append(checks, map[string]any{
		"id": "singbox_running", "ok": ok, "label": "sing-box", "detail": singbox["detail"],
	})
	if !ok {
		hints = append(hints, i18n.T(locale, "system.singbox_not_running_hint"))
	}
	return finalizeGroup("runtime", i18n.T(locale, "system.group_runtime"), checks, hints)
}

func (s *Service) groupAwgIfaces(ctx context.Context, locale string, configs []models.AwgConfig) map[string]any {
	checks := []map[string]any{}
	hints := []string{}
	var targets []models.AwgConfig
	for _, c := range configs {
		if c.Enabled {
			targets = append(targets, c)
		}
	}
	if len(targets) == 0 {
		checks = append(checks, map[string]any{
			"id": "awg_ifaces_none", "ok": true,
			"label": i18n.T(locale, "system.awg_ifaces_label"),
			"detail": i18n.T(locale, "system.no_enabled_configs_in_selection"),
		})
		return finalizeGroup("awg", "AWG ifaces", checks, hints)
	}
	running := s.Stats.IsContainerRunning(ctx, "")
	kernelLoaded := running && s.Stats.KernelModuleLoaded(ctx)
	datapath := "unknown"
	if running {
		datapath = s.awgDatapath(ctx)
	}
	userspace := datapath == "userspace"
	for _, c := range targets {
		up := running && s.Stats.IfaceIsUp(ctx, c.Iface)
		showOk := false
		showDetail := ""
		if up {
			showOk, showDetail = s.Stats.AwgShowProbe(ctx, c.Iface)
		}
		typeLabel := "server"
		if c.Type == "virtual_network" {
			typeLabel = "VN"
		}
		detail := "up · awg show OK"
		if showOk && showDetail == "via dump" {
			detail = i18n.T(locale, "system.awg_show_ok_via_dump")
		}
		if !up {
			detail = "iface down"
		} else if !showOk {
			if showDetail != "" {
				detail = i18n.Tf(locale, "system.awg_show_unavailable_detail", map[string]string{"detail": showDetail})
			} else {
				detail = i18n.T(locale, "system.awg_show_unavailable")
			}
		}
		checks = append(checks, map[string]any{
			"id": "iface_" + c.Iface, "ok": up && showOk,
			"label": c.Name + " (" + c.Iface + ", " + typeLabel + ")", "detail": detail,
		})
		if !up {
			if s.Stats.ConfFileExists(c.Iface) {
				hints = append(hints, i18n.Tf(locale, "system.iface_down_conf_present_hint", map[string]string{"iface": c.Iface, "name": c.Name}))
			} else {
				hints = append(hints, i18n.Tf(locale, "system.iface_not_up_hint", map[string]string{"iface": c.Iface, "name": c.Name}))
			}
		} else if !showOk {
			if kernelLoaded {
				hints = append(hints, i18n.Tf(locale, "system.awg_show_kernel_oops_hint", map[string]string{"iface": c.Iface}))
			} else {
				hints = append(hints, i18n.Tf(locale, "system.awg_show_no_response_hint", map[string]string{"iface": c.Iface}))
			}
		}
	}

	if running {
		masqOK := s.hasMasquerade(ctx)
		masqDetail := "ok"
		if !masqOK {
			masqDetail = i18n.T(locale, "system.nat_masquerade_missing")
		}
		checks = append(checks, map[string]any{
			"id": "nat_masquerade", "ok": masqOK,
			"label": i18n.T(locale, "system.nat_masquerade_label"),
			"detail": masqDetail,
		})
		if !masqOK {
			hints = append(hints, i18n.T(locale, "system.nat_masquerade_hint"))
		}

		var missingRoutes []string
		for _, c := range targets {
			if !s.Stats.IfaceIsUp(ctx, c.Iface) {
				continue
			}
			if !s.hasIfaceConnectedRoute(ctx, c.Iface) {
				missingRoutes = append(missingRoutes, c.Iface)
			}
		}
		routesOK := len(missingRoutes) == 0
		routeDetail := "ok"
		if !routesOK {
			routeDetail = i18n.Tf(locale, "system.awg_routes_missing_detail", map[string]string{
				"ifaces": strings.Join(missingRoutes, ", "),
			})
		}
		checks = append(checks, map[string]any{
			"id": "awg_connected_routes", "ok": routesOK,
			"label": i18n.T(locale, "system.awg_routes_label"), "detail": routeDetail,
		})
		if !routesOK {
			hints = append(hints, i18n.T(locale, "system.awg_routes_hint"))
		}

		datapathOK := datapath != "userspace"
		datapathDetail := i18n.T(locale, "system.awg_datapath_unknown")
		switch datapath {
		case "kernel":
			datapathDetail = i18n.T(locale, "system.awg_datapath_kernel")
		case "userspace":
			datapathDetail = i18n.T(locale, "system.awg_datapath_userspace")
		}
		checks = append(checks, map[string]any{
			"id": "awg_datapath", "ok": datapathOK,
			"label": i18n.T(locale, "system.awg_datapath_label"),
			"detail": datapathDetail,
		})
		if userspace {
			if kernelLoaded {
				hints = append(hints, i18n.T(locale, "system.awg_datapath_userspace_despite_module_hint"))
			} else {
				hints = append(hints, i18n.T(locale, "system.awg_datapath_userspace_hint"))
			}
		} else if datapath == "unknown" {
			hints = append(hints, i18n.T(locale, "system.awg_datapath_unknown_hint"))
		}

		if s.moduleBlacklisted() {
			checks = append(checks, map[string]any{
				"id": "awg_module_blacklist", "ok": false,
				"label": i18n.T(locale, "system.awg_module_blacklisted_label"),
				"detail": i18n.T(locale, "system.awg_module_blacklisted_detail"),
			})
			hints = append(hints, i18n.T(locale, "system.awg_module_blacklisted_hint"))
		}
	}

	return finalizeGroup("awg", "AWG ifaces", checks, hints)
}

func (s *Service) groupResolver(ctx context.Context, locale string, configs []models.AwgConfig) map[string]any {
	hasServer := false
	hasResolver := false
	var resolverIfaces []string
	for _, c := range configs {
		if c.Type == "server" {
			hasServer = true
			if c.ResolverEnabled {
				hasResolver = true
				if c.Enabled {
					resolverIfaces = append(resolverIfaces, c.Iface)
				}
			}
		}
	}
	if !hasServer || !hasResolver {
		detail := i18n.T(locale, "system.no_server_configs_in_selection")
		if hasServer {
			detail = i18n.T(locale, "system.no_resolver_enabled_servers")
		}
		checks := []map[string]any{{
			"id": "resolver_skipped", "ok": true,
			"label": i18n.T(locale, "system.resolver_label"), "detail": detail,
		}}
		return finalizeGroup("resolver", i18n.T(locale, "system.group_resolver"), checks, nil)
	}
	checks := []map[string]any{}
	hints := []string{}
	sing := s.singBoxRunningCheck(ctx, locale)
	ok, _ := sing["ok"].(bool)
	checks = append(checks, map[string]any{
		"id": "singbox_running", "ok": ok, "label": "sing-box", "detail": sing["detail"],
	})
	if !ok {
		hints = append(hints, i18n.T(locale, "system.singbox_not_running_hint"))
	}
	path := s.Stats.ConfigDir() + "/sing-box.json"
	exists := fileExists(path)
	detail := "ok"
	if !exists {
		detail = i18n.T(locale, "system.singbox_json_not_found")
	}
	checks = append(checks, map[string]any{
		"id": "singbox_config", "ok": exists, "label": "sing-box.json", "detail": detail,
	})

	running := s.Stats.IsContainerRunning(ctx, "")
	if running && len(resolverIfaces) > 0 {
		var missingDNS []string
		for _, iface := range resolverIfaces {
			if !s.hasDNSRedirect(ctx, iface) {
				missingDNS = append(missingDNS, iface)
			}
		}
		dnsOK := len(missingDNS) == 0
		dnsDetail := "ok"
		if !dnsOK {
			dnsDetail = i18n.Tf(locale, "system.dns_redirect_missing_detail", map[string]string{
				"ifaces": strings.Join(missingDNS, ", "),
			})
		}
		checks = append(checks, map[string]any{
			"id": "dns_redirect", "ok": dnsOK,
			"label": i18n.T(locale, "system.dns_redirect_label"), "detail": dnsDetail,
		})
		if !dnsOK {
			hints = append(hints, i18n.T(locale, "system.dns_redirect_hint"))
		}

		datapath := s.awgDatapath(ctx)
		datapathOK := datapath == "kernel"
		datapathDetail := i18n.T(locale, "system.awg_datapath_unknown")
		switch datapath {
		case "kernel":
			datapathDetail = i18n.T(locale, "system.awg_datapath_kernel")
		case "userspace":
			datapathDetail = i18n.T(locale, "system.awg_datapath_userspace_resolver")
			datapathOK = false
		}
		checks = append(checks, map[string]any{
			"id": "resolver_datapath", "ok": datapathOK,
			"label": i18n.T(locale, "system.awg_datapath_label"),
			"detail": datapathDetail,
		})
		if datapath == "userspace" {
			hints = append(hints, i18n.T(locale, "system.awg_datapath_userspace_resolver_hint"))
		} else if datapath == "unknown" {
			hints = append(hints, i18n.T(locale, "system.awg_datapath_unknown_hint"))
		}
	}

	for _, c := range configs {
		if c.Type != "server" || !c.ResolverEnabled || !c.ResolverRejectQuic {
			continue
		}
		hints = append(hints, i18n.Tf(locale, "system.reject_quic_abr_hint", map[string]string{"name": c.Name}))
	}

	g := finalizeGroup("resolver", i18n.T(locale, "system.group_resolver"), checks, hints)
	g["details"] = nil
	return g
}

type resolverConn struct {
	ID      int64
	Name    string
	Enabled bool
}

func (s *Service) groupOutbounds(ctx context.Context, locale string, configs []models.AwgConfig) map[string]any {
	checks := []map[string]any{}
	hints := []string{}
	connIDs := map[int64]bool{}
	for _, c := range configs {
		if c.Type == "server" && c.ResolverEnabled && c.ConnectionID != nil {
			connIDs[*c.ConnectionID] = true
		}
	}
	conns := s.loadConnections(ctx, connIDs)
	if len(connIDs) == 0 {
		enabled := []resolverConn{}
		for _, c := range conns {
			if c.Enabled {
				enabled = append(enabled, c)
			}
		}
		if len(enabled) == 0 {
			checks = append(checks, map[string]any{
				"id": "outbounds_none", "ok": true, "label": "Outbounds",
				"detail": i18n.T(locale, "system.no_active_resolver_connections"),
			})
			return finalizeGroup("outbounds", "Outbounds", checks, hints)
		}
		conns = enabled
	}
	if !s.Stats.IsContainerRunning(ctx, "") {
		checks = append(checks, map[string]any{
			"id": "outbounds_no_awg", "ok": false, "label": "Outbounds",
			"detail": i18n.T(locale, "system.awg_container_not_running"),
		})
		return finalizeGroup("outbounds", "Outbounds", checks, []string{i18n.T(locale, "system.start_awg_for_outbounds")})
	}
	for _, conn := range conns {
		tag := "conn_" + strconv.FormatInt(conn.ID, 10)
		result := s.testOutboundDelay(ctx, locale, tag)
		ok, _ := result["ok"].(bool)
		detail := i18n.T(locale, "system.latency_error")
		if ok {
			if ms, okn := result["latency_ms"].(int); okn && ms > 0 {
				detail = strconv.Itoa(ms) + " ms"
			} else {
				detail = "OK"
			}
		} else if err, _ := result["error"].(string); err != "" {
			detail = err
		}
		checks = append(checks, map[string]any{
			"id": "outbound_" + strconv.FormatInt(conn.ID, 10), "ok": ok,
			"label": conn.Name + " (" + tag + ")", "detail": detail,
		})
		if !ok {
			errStr, _ := result["error"].(string)
			if errStr == "" {
				errStr = i18n.T(locale, "system.no_clash_api_response")
			}
			hints = append(hints, i18n.Tf(locale, "system.connection_no_clash_response", map[string]string{"name": conn.Name, "error": errStr}))
		} else if ms, okn := result["latency_ms"].(int); okn && ms >= 200 {
			hints = append(hints, i18n.Tf(locale, "system.outbound_high_rtt_hint", map[string]string{
				"name": conn.Name,
				"ms":   strconv.Itoa(ms),
			}))
		}
	}
	return finalizeGroup("outbounds", "Outbounds", checks, hints)
}

func (s *Service) loadConnections(ctx context.Context, ids map[int64]bool) []resolverConn {
	if s.DB == nil {
		return nil
	}
	q := `SELECT id, name, enabled FROM resolver_connections`
	args := []any{}
	if len(ids) > 0 {
		q += ` WHERE id IN (`
		i := 0
		for id := range ids {
			if i > 0 {
				q += `,`
			}
			q += `?`
			args = append(args, id)
			i++
		}
		q += `)`
	}
	q += ` ORDER BY id`
	rows, err := s.DB.QueryContext(ctx, q, args...)
	if err != nil {
		return nil
	}
	defer rows.Close()
	var out []resolverConn
	for rows.Next() {
		var c resolverConn
		if err := rows.Scan(&c.ID, &c.Name, &c.Enabled); err != nil {
			continue
		}
		out = append(out, c)
	}
	return out
}

func (s *Service) testOutboundDelay(ctx context.Context, locale, tag string) map[string]any {
	path := "/proxies/" + url.PathEscape(tag) + "/delay"
	qs := "url=" + url.QueryEscape("https://www.gstatic.com/generate_204") + "&timeout=5000"
	curlURL := "http://127.0.0.1:9090" + path + "?" + qs
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{
		"curl", "-sS", "-m", "10", "-w", "___HTTP_STATUS___%{http_code}", curlURL,
	}, 15*time.Second, "")
	out := r.Stdout
	marker := "___HTTP_STATUS___"
	pos := strings.LastIndex(out, marker)
	if pos < 0 {
		return map[string]any{"ok": false, "latency_ms": nil, "error": i18n.T(locale, "resolver.clash_api_unavailable")}
	}
	rawBody := out[:pos]
	status, _ := strconv.Atoi(out[pos+len(marker):])
	var body map[string]any
	_ = json.Unmarshal([]byte(rawBody), &body)
	if status >= 200 && status < 300 {
		if d, ok := body["delay"].(float64); ok {
			delay := int(d)
			if delay > 0 {
				return map[string]any{"ok": true, "latency_ms": delay, "error": nil}
			}
			return map[string]any{"ok": false, "latency_ms": nil, "error": i18n.T(locale, "resolver.zero_delay")}
		}
	}
	err := i18n.T(locale, "resolver.check_failed")
	if msg, ok := body["message"].(string); ok && msg != "" {
		err = msg
	}
	lower := strings.ToLower(err)
	if strings.Contains(lower, "timeout") {
		err = i18n.T(locale, "resolver.timeout")
	}
	return map[string]any{"ok": false, "latency_ms": nil, "error": err}
}

func (s *Service) groupVirtualNetworks(ctx context.Context, locale string, configs []models.AwgConfig) map[string]any {
	checks := []map[string]any{}
	hints := []string{}
	var vns []models.AwgConfig
	for _, c := range configs {
		if c.Type == "virtual_network" {
			vns = append(vns, c)
		}
	}
	if len(vns) == 0 {
		checks = append(checks, map[string]any{
			"id": "vn_none", "ok": true,
			"label": i18n.T(locale, "system.virtual_networks_label"),
			"detail": i18n.T(locale, "system.no_vn_in_selection"),
		})
		return finalizeGroup("vn", i18n.T(locale, "system.group_vn"), checks, hints)
	}
	live := s.Stats.LivePeerStats(ctx, nil)
	running := s.Stats.IsContainerRunning(ctx, "")
	for _, c := range vns {
		up := c.Enabled && running && s.Stats.IfaceIsUp(ctx, c.Iface)
		peerCount, _ := s.Peers.Count(ctx, []int64{c.ID})
		enabledPeers, _ := s.Peers.CountEnabled(ctx, []int64{c.ID})
		online := 0
		members, _ := s.Peers.ListByConfig(ctx, c.ID)
		for _, m := range members {
			if st, ok := live.ByPublicKey[m.PublicKey]; ok {
				if on, _ := st["online"].(bool); on {
					online++
				}
			}
		}
		parts := []string{}
		if c.Enabled {
			parts = append(parts, "enabled")
		} else {
			parts = append(parts, "disabled")
		}
		if up {
			parts = append(parts, "iface up")
		} else {
			parts = append(parts, "iface down")
		}
		parts = append(parts, "peers="+strconv.Itoa(peerCount)+" (enabled="+strconv.Itoa(enabledPeers)+")")
		parts = append(parts, "online≈"+strconv.Itoa(online))
		ok := true
		if !c.Enabled {
			parts = append(parts, i18n.T(locale, "system.skip_disabled"))
		} else if !up {
			ok = false
		} else if enabledPeers > 0 && online == 0 {
			ok = false
			parts = append(parts, i18n.T(locale, "system.no_fresh_handshakes"))
		}
		checks = append(checks, map[string]any{
			"id": "vn_" + strconv.FormatInt(c.ID, 10), "ok": ok,
			"label": c.Name + " (" + c.Iface + ")", "detail": strings.Join(parts, " · "),
		})
		if c.Enabled && !up {
			hints = append(hints, i18n.Tf(locale, "system.vn_iface_not_up", map[string]string{"name": c.Name}))
		} else if c.Enabled && enabledPeers > 0 && online == 0 {
			hints = append(hints, i18n.Tf(locale, "system.vn_no_fresh_handshakes", map[string]string{"name": c.Name}))
		}
	}
	return finalizeGroup("vn", i18n.T(locale, "system.group_vn"), checks, hints)
}

func (s *Service) pingProbeStatus(ctx context.Context) map[string]any {
	path := s.Stats.ConfigDir() + "/sing-box-ping.json"
	var bytes int
	var outboundCount any
	if st, err := os.Stat(path); err == nil {
		bytes = int(st.Size())
		raw, _ := os.ReadFile(path)
		var jsonDoc map[string]any
		if json.Unmarshal(raw, &jsonDoc) == nil {
			if ob, ok := jsonDoc["outbounds"].([]any); ok {
				outboundCount = len(ob)
			}
		}
	}
	running := false
	if s.Stats.IsContainerRunning(ctx, "") {
		r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", `test -f /run/sing-box-ping.pid && kill -0 "$(cat /run/sing-box-ping.pid)" 2>/dev/null`}, 5*time.Second, "")
		running = r.Successful()
	}
	return map[string]any{"config_bytes": bytes, "outbound_count": outboundCount, "running": running}
}

var diagIfaceRE = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,14}$`)

func (s *Service) awgDatapath(ctx context.Context) string {
	// Prefer real process check over pgrep -f (shell argv false positive). userspace wins if go is running.
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c",
		`if ps aux 2>/dev/null | grep -q '[a]mneziawg-go '; then echo userspace; elif [ -d /sys/module/amneziawg ] || lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg; then echo kernel; else echo unknown; fi`},
		8*time.Second, "")
	v := strings.TrimSpace(r.Stdout)
	if v == "kernel" || v == "userspace" || v == "unknown" {
		return v
	}
	return "unknown"
}

func (s *Service) moduleBlacklisted() bool {
	if s.PanelOps == nil {
		return false
	}
	data, err := s.PanelOps.AWGKernelStatus()
	if err != nil || data == nil {
		return false
	}
	switch v := data["module_blacklisted"].(type) {
	case bool:
		return v
	case float64:
		return v != 0
	case string:
		return v == "true" || v == "1"
	default:
		return false
	}
}

func (s *Service) hasMasquerade(ctx context.Context) bool {
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c",
		`iptables -t nat -S POSTROUTING 2>/dev/null | grep -q ' -j MASQUERADE' && echo yes || echo no`}, 8*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

func (s *Service) hasIfaceConnectedRoute(ctx context.Context, iface string) bool {
	if !diagIfaceRE.MatchString(iface) {
		return false
	}
	// Non-default IPv4 routes via this iface (tunnel subnet / peer AllowedIPs).
	script := `ip -4 route show dev ` + iface + ` 2>/dev/null | grep -qvE '^default|[[:space:]]default[[:space:]]' && echo yes || echo no`
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script}, 8*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

func (s *Service) hasDNSRedirect(ctx context.Context, iface string) bool {
	if !diagIfaceRE.MatchString(iface) {
		return false
	}
	// PostUp installs udp+tcp REDIRECT --dport 53; require at least udp.
	script := `iptables -t nat -S PREROUTING 2>/dev/null | grep -E -- '-i ` + iface + ` .*--dport 53 .*REDIRECT' | grep -q udp && echo yes || echo no`
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script}, 8*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

func finalizeGroup(id, title string, checks []map[string]any, hints []string) map[string]any {
	allOk, anyOk, anyFail := true, false, false
	if len(checks) == 0 {
		allOk = true
	}
	for _, c := range checks {
		ok, _ := c["ok"].(bool)
		if ok {
			anyOk = true
		} else {
			allOk = false
			anyFail = true
		}
	}
	status := "success"
	if !allOk {
		if anyOk && anyFail {
			status = "warning"
		} else {
			status = "error"
		}
	}
	if hints == nil {
		hints = []string{}
	}
	return map[string]any{
		"id": id, "title": title, "ok": allOk, "status": status, "checks": checks, "hints": hints,
	}
}

func maskAwgConfText(text string) string {
	rePriv := regexp.MustCompile(`(?mi)^(PrivateKey\s*=\s*).+$`)
	rePsk := regexp.MustCompile(`(?mi)^(PresharedKey\s*=\s*).+$`)
	text = rePriv.ReplaceAllString(text, "${1}***")
	return rePsk.ReplaceAllString(text, "${1}***")
}

func maskJSONSecrets(data any) any {
	switch t := data.(type) {
	case map[string]any:
		out := map[string]any{}
		for k, v := range t {
			if maskJSONKeys[strings.ToLower(k)] {
				out[k] = "***"
				continue
			}
			out[k] = maskJSONSecrets(v)
		}
		return out
	case []any:
		out := make([]any, len(t))
		for i, v := range t {
			out[i] = maskJSONSecrets(v)
		}
		return out
	default:
		return data
	}
}

func stringSlice(v any) []string {
	switch t := v.(type) {
	case []string:
		return t
	case []any:
		out := make([]string, 0, len(t))
		for _, x := range t {
			if s, ok := x.(string); ok && s != "" {
				out = append(out, s)
			}
		}
		return out
	default:
		return nil
	}
}

func uniqueStrings(in []string) []string {
	seen := map[string]bool{}
	out := []string{}
	for _, s := range in {
		if s == "" || seen[s] {
			continue
		}
		seen[s] = true
		out = append(out, s)
	}
	return out
}

func fileExists(path string) bool {
	st, err := os.Stat(path)
	return err == nil && !st.IsDir()
}

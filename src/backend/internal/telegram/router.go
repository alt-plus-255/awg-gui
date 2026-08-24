package telegram

import (
	"context"
	"encoding/json"
	"html"
	"log"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/resolver"
	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
	"github.com/awggui/backend/internal/system"
	"github.com/awggui/backend/internal/vpnuri"
)

const onlineListLimit = 8

type UpdateRouter struct {
	Settings      *Settings
	Bot           *Client
	Conversations *ConversationStore
	AWG           *awg.Service
	VpnURI        *vpnuri.Service
	Resolver      *resolver.Service
	Stats         *stats.Service
	Host          *system.HostMetrics
	Configs       *store.Configs
	Peers         *store.Peers
	Clients       *store.Clients
}

type validationError struct{ msg string }

func (e *validationError) Error() string { return e.msg }

func (r *UpdateRouter) Handle(ctx context.Context, update map[string]any) {
	if cb, ok := update["callback_query"].(map[string]any); ok {
		r.handleCallback(ctx, cb)
		return
	}
	if msg, ok := update["message"].(map[string]any); ok {
		r.handleMessage(ctx, msg)
	}
}

func (r *UpdateRouter) locale(ctx context.Context) string {
	return r.Settings.Language(ctx)
}

func (r *UpdateRouter) t(ctx context.Context, key string) string {
	return i18n.T(r.locale(ctx), key)
}

func (r *UpdateRouter) tf(ctx context.Context, key string, vars map[string]string) string {
	return i18n.Tf(r.locale(ctx), key, vars)
}

func (r *UpdateRouter) handleMessage(ctx context.Context, message map[string]any) {
	from, _ := message["from"].(map[string]any)
	chat, _ := message["chat"].(map[string]any)
	userID := from["id"]
	chatID := asStr(chat["id"])
	text := strings.TrimSpace(asStr(message["text"]))
	if !r.Settings.IsAdmin(ctx, userID) {
		if chatID != "" && strings.HasPrefix(text, "/start") {
			r.Bot.SendMessage(ctx, chatID, r.t(ctx, "telegram.forbidden"), nil)
		}
		return
	}
	if chatID == "" || text == "" {
		return
	}
	if strings.HasPrefix(text, "/start") {
		r.Conversations.Clear(ctx, chatID)
		r.showHome(ctx, chatID, 0, "", false)
		return
	}
	if conv := r.Conversations.Get(ctx, chatID); conv != nil {
		r.handleWizardText(ctx, chatID, text, conv)
		return
	}
	r.showHome(ctx, chatID, 0, "", false)
}

func (r *UpdateRouter) handleCallback(ctx context.Context, callback map[string]any) {
	from, _ := callback["from"].(map[string]any)
	callbackID := asStr(callback["id"])
	if !r.Settings.IsAdmin(ctx, from["id"]) {
		if callbackID != "" {
			r.Bot.AnswerCallbackQuery(ctx, callbackID, r.t(ctx, "telegram.forbidden"), true)
		}
		return
	}
	if callbackID != "" {
		r.Bot.AnswerCallbackQuery(ctx, callbackID, "", false)
	}
	message, _ := callback["message"].(map[string]any)
	if message == nil {
		return
	}
	chat, _ := message["chat"].(map[string]any)
	chatID := asStr(chat["id"])
	messageID := int(asInt64(message["message_id"]))
	if chatID == "" || messageID == 0 {
		return
	}
	data := asStr(callback["data"])
	if data == "" {
		return
	}
	defer func() {
		if rec := recover(); rec != nil {
			log.Printf("telegram.callback panic: %v", rec)
			r.showError(ctx, chatID, messageID, fmtErr(rec))
		}
	}()
	if err := r.routeCallback(ctx, chatID, messageID, data); err != nil {
		r.showError(ctx, chatID, messageID, err.Error())
	}
}

func (r *UpdateRouter) routeCallback(ctx context.Context, chatID string, messageID int, data string) error {
	switch data {
	case "m:home":
		r.Conversations.Clear(ctx, chatID)
		r.showHome(ctx, chatID, messageID, "", false)
		return nil
	case "m:cfg":
		r.showConfigsList(ctx, chatID, messageID)
		return nil
	case "m:conn":
		r.showConnectionsList(ctx, chatID, messageID)
		return nil
	case "m:res":
		r.showResolverList(ctx, chatID, messageID)
		return nil
	case "m:notif":
		r.showNotifications(ctx, chatID, messageID)
		return nil
	case "m:refresh":
		r.showHome(ctx, chatID, messageID, r.t(ctx, "telegram.refresh_done"), true)
		return nil
	case "cfg:new":
		r.Conversations.Put(ctx, chatID, "cfg_new.name", nil)
		r.show(ctx, chatID, messageID, r.t(ctx, "telegram.config_wizard_name"), r.backHomeKeyboard(ctx))
		return nil
	case "conn:new":
		r.Conversations.Put(ctx, chatID, "conn_new.name", nil)
		r.show(ctx, chatID, messageID, r.t(ctx, "telegram.connection_wizard_name"), r.backConnectionsKeyboard(ctx))
		return nil
	case "notif:en":
		r.showNotificationsEnableConfirm(ctx, chatID, messageID)
		return nil
	case "notif:enok":
		r.toggleNotifications(ctx, chatID, messageID)
		return nil
	case "notif:daily:en":
		r.showDailyReportEnableConfirm(ctx, chatID, messageID)
		return nil
	case "notif:daily:enok":
		r.toggleDailyReport(ctx, chatID, messageID)
		return nil
	}
	if m := re(`^cfg:type:(server|virtual_network)$`).FindStringSubmatch(data); m != nil {
		r.finishConfigCreate(ctx, chatID, messageID, m[1])
		return nil
	}
	if m := re(`^cfg:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showConfigDetail(ctx, chatID, messageID, atoi64(m[1]), "")
		return nil
	}
	if m := re(`^cfg:en:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showConfigEnableConfirm(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^cfg:enok:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.toggleConfigEnabled(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^cfg:del:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showConfigDeleteConfirm(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^cfg:delok:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.deleteConfig(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^cfg:peers:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showPeersList(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^cfg:edit:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showConfigEditMenu(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^cfg:edn:(\d+)$`).FindStringSubmatch(data); m != nil {
		id := atoi64(m[1])
		r.Conversations.Put(ctx, chatID, "cfg_edit.name", map[string]any{"config_id": id})
		r.show(ctx, chatID, messageID, r.t(ctx, "telegram.config_edit_name_prompt"), r.backConfigKeyboard(ctx, id))
		return nil
	}
	if m := re(`^cfg:edp:(\d+)$`).FindStringSubmatch(data); m != nil {
		id := atoi64(m[1])
		r.Conversations.Put(ctx, chatID, "cfg_edit.port", map[string]any{"config_id": id})
		r.show(ctx, chatID, messageID, r.t(ctx, "telegram.config_edit_port_prompt"), r.backConfigKeyboard(ctx, id))
		return nil
	}
	if m := re(`^cfg:edd:(\d+)$`).FindStringSubmatch(data); m != nil {
		id := atoi64(m[1])
		r.Conversations.Put(ctx, chatID, "cfg_edit.dns", map[string]any{"config_id": id})
		r.show(ctx, chatID, messageID, r.t(ctx, "telegram.config_edit_dns_prompt"), r.backConfigKeyboard(ctx, id))
		return nil
	}
	if m := re(`^peer:(\d+):(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showPeerDetail(ctx, chatID, messageID, atoi64(m[1]), atoi64(m[2]))
		return nil
	}
	if m := re(`^peer:en:(\d+):(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showPeerEnableConfirm(ctx, chatID, messageID, atoi64(m[1]), atoi64(m[2]))
		return nil
	}
	if m := re(`^peer:enok:(\d+):(\d+)$`).FindStringSubmatch(data); m != nil {
		r.togglePeerEnabled(ctx, chatID, messageID, atoi64(m[1]), atoi64(m[2]))
		return nil
	}
	if m := re(`^peer:del:(\d+):(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showPeerDeleteConfirm(ctx, chatID, messageID, atoi64(m[1]), atoi64(m[2]))
		return nil
	}
	if m := re(`^peer:delok:(\d+):(\d+)$`).FindStringSubmatch(data); m != nil {
		r.deletePeer(ctx, chatID, messageID, atoi64(m[1]), atoi64(m[2]))
		return nil
	}
	if m := re(`^peer:new:(\d+)$`).FindStringSubmatch(data); m != nil {
		configID := atoi64(m[1])
		r.Conversations.Put(ctx, chatID, "peer_new.name", map[string]any{"config_id": configID})
		r.show(ctx, chatID, messageID, r.t(ctx, "telegram.peer_wizard_name"), r.backPeersKeyboard(ctx, configID))
		return nil
	}
	if m := re(`^peer:uri:(\d+):(\d+)$`).FindStringSubmatch(data); m != nil {
		r.sendPeerVpnURI(ctx, chatID, atoi64(m[1]), atoi64(m[2]))
		return nil
	}
	if m := re(`^res:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showResolverDetail(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^res:en:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showResolverEnableConfirm(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^res:enok:(\d+)$`).FindStringSubmatch(data); m != nil {
		return r.toggleResolver(ctx, chatID, messageID, atoi64(m[1]))
	}
	if m := re(`^res:list:(\d+):(.+)$`).FindStringSubmatch(data); m != nil {
		r.toggleResolverList(ctx, chatID, messageID, atoi64(m[1]), m[2])
		return nil
	}
	if m := re(`^res:conn:(\d+):(\d+)$`).FindStringSubmatch(data); m != nil {
		return r.setResolverConnection(ctx, chatID, messageID, atoi64(m[1]), atoi64(m[2]))
	}
	if m := re(`^conn:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showConnectionDetail(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^conn:en:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.toggleConnectionEnabled(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^conn:del:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.showConnectionDeleteConfirm(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	if m := re(`^conn:delok:(\d+)$`).FindStringSubmatch(data); m != nil {
		r.deleteConnection(ctx, chatID, messageID, atoi64(m[1]))
		return nil
	}
	return nil
}

func (r *UpdateRouter) handleWizardText(ctx context.Context, chatID, text string, conv *Conversation) {
	defer func() {
		if rec := recover(); rec != nil {
			r.Bot.SendMessage(ctx, chatID, r.tf(ctx, "telegram.error_generic", map[string]string{"message": esc(fmtErr(rec))}), nil)
		}
	}()
	var err error
	switch conv.Step {
	case "cfg_new.name":
		err = r.wizardConfigName(ctx, chatID, text)
	case "cfg_edit.name":
		err = r.wizardConfigEditName(ctx, chatID, text, conv.Data)
	case "cfg_edit.port":
		err = r.wizardConfigEditPort(ctx, chatID, text, conv.Data)
	case "cfg_edit.dns":
		err = r.wizardConfigEditDns(ctx, chatID, text, conv.Data)
	case "peer_new.name":
		err = r.wizardPeerName(ctx, chatID, text, conv.Data)
	case "conn_new.name":
		err = r.wizardConnectionName(ctx, chatID, text)
	case "conn_new.url":
		err = r.wizardConnectionURL(ctx, chatID, text, conv.Data)
	default:
		r.Conversations.Clear(ctx, chatID)
	}
	if err != nil {
		r.Bot.SendMessage(ctx, chatID, r.tf(ctx, "telegram.error_generic", map[string]string{"message": esc(err.Error())}), nil)
	}
}

func (r *UpdateRouter) showHome(ctx context.Context, chatID string, messageID int, prefix string, syncFromDocker bool) {
	parts := []string{}
	if prefix != "" {
		parts = append(parts, prefix)
	}
	parts = append(parts, r.t(ctx, "telegram.home_title"), r.formatDashboardSummary(ctx, syncFromDocker), r.t(ctx, "telegram.welcome"))
	text := strings.Join(filterEmpty(parts), "\n\n")
	rows := [][]map[string]string{
		{Btn(r.t(ctx, "telegram.menu_configs"), "m:cfg"), Btn(r.t(ctx, "telegram.menu_connections"), "m:conn")},
		{Btn(r.t(ctx, "telegram.menu_resolver"), "m:res"), Btn(r.t(ctx, "telegram.menu_notifications"), "m:notif")},
		{Btn(r.t(ctx, "telegram.menu_refresh"), "m:refresh")},
	}
	r.show(ctx, chatID, messageID, text, Inline(rows))
}

func (r *UpdateRouter) formatDashboardSummary(ctx context.Context, syncFromDocker bool) string {
	na := r.t(ctx, "telegram.dashboard_na")
	if syncFromDocker && r.Stats != nil {
		r.Stats.RefreshFromDocker(ctx, nil)
	}
	var peers []map[string]any
	if r.Stats != nil {
		peers, _ = r.Stats.PeersFromDB(ctx, nil)
	}
	onlinePeers := []map[string]any{}
	for _, p := range peers {
		if ok, _ := p["online"].(bool); ok {
			onlinePeers = append(onlinePeers, p)
		}
	}
	clientsTotal, _ := r.Clients.Count(ctx)
	enabled, _ := r.Peers.CountEnabled(ctx, nil)
	awgStatus := na
	endpoint := na
	if r.AWG.IsContainerRunning(ctx) {
		awgStatus = r.t(ctx, "telegram.dashboard_awg_up")
	} else {
		awgStatus = r.t(ctx, "telegram.dashboard_awg_down")
	}
	if st, err := r.AWG.EndpointStatus(ctx, ""); err == nil {
		if ep := strings.TrimSpace(asStr(st["endpoint"])); ep != "" {
			endpoint = ep
		}
	}
	cpu, ram, disk := na, na, na
	if r.Host != nil {
		h := r.Host.Collect()
		cpu = formatPercent(r.locale(ctx), nested(h, "cpu", "percent"))
		ram = formatPercent(r.locale(ctx), nested(h, "memory", "percent"))
		disk = formatPercent(r.locale(ctx), nested(h, "disk", "percent"))
	}
	lines := []string{
		r.tf(ctx, "telegram.dashboard_awg", map[string]string{"status": awgStatus, "endpoint": esc(endpoint)}),
		r.tf(ctx, "telegram.dashboard_summary", map[string]string{
			"peers": itoa(clientsTotal), "enabled": itoa(enabled), "online": itoa(len(onlinePeers)),
		}),
		r.tf(ctx, "telegram.dashboard_host", map[string]string{"cpu": cpu, "ram": ram, "disk": disk}),
	}
	if len(onlinePeers) > 0 {
		lines = append(lines, r.t(ctx, "telegram.dashboard_online_title"))
		limit := onlineListLimit
		if limit > len(onlinePeers) {
			limit = len(onlinePeers)
		}
		for _, peer := range onlinePeers[:limit] {
			name := strings.TrimSpace(asStr(peer["name"]))
			if name == "" {
				name = na
			}
			config := strings.TrimSpace(asStr(peer["config_name"]))
			if config == "" {
				config = na
			}
			lines = append(lines, r.tf(ctx, "telegram.dashboard_online_line", map[string]string{"name": esc(name), "config": esc(config)}))
		}
		if more := len(onlinePeers) - onlineListLimit; more > 0 {
			lines = append(lines, r.tf(ctx, "telegram.dashboard_online_more", map[string]string{"count": itoa(more)}))
		}
	}
	return strings.Join(lines, "\n")
}

func (r *UpdateRouter) show(ctx context.Context, chatID string, messageID int, text string, keyboard map[string]any) {
	extra := map[string]any{}
	if keyboard != nil {
		extra["reply_markup"] = keyboard
	}
	if messageID > 0 {
		result := r.Bot.EditMessageText(ctx, chatID, messageID, text, extra)
		if !resultOK(result) {
			r.Bot.SendMessage(ctx, chatID, text, extra)
		}
		return
	}
	r.Bot.SendMessage(ctx, chatID, text, extra)
}

func (r *UpdateRouter) showError(ctx context.Context, chatID string, messageID int, message string) {
	r.show(ctx, chatID, messageID, r.tf(ctx, "telegram.error_generic", map[string]string{"message": esc(message)}), nil)
}

func (r *UpdateRouter) backHomeKeyboard(ctx context.Context) map[string]any {
	return Inline([][]map[string]string{{Btn(r.t(ctx, "telegram.menu_home"), "m:home")}})
}
func (r *UpdateRouter) backConfigKeyboard(ctx context.Context, configID int64) map[string]any {
	return Inline([][]map[string]string{{Btn(r.t(ctx, "telegram.menu_back"), "cfg:"+itoa64str(configID))}})
}
func (r *UpdateRouter) backPeersKeyboard(ctx context.Context, configID int64) map[string]any {
	return Inline([][]map[string]string{{Btn(r.t(ctx, "telegram.menu_back"), "cfg:peers:"+itoa64str(configID))}})
}
func (r *UpdateRouter) backConnectionsKeyboard(ctx context.Context) map[string]any {
	return Inline([][]map[string]string{{Btn(r.t(ctx, "telegram.menu_back"), "m:conn")}})
}

func (r *UpdateRouter) statusLabel(ctx context.Context, enabled bool) string {
	if enabled {
		return r.t(ctx, "telegram.on")
	}
	return r.t(ctx, "telegram.off")
}

func (r *UpdateRouter) validateName(ctx context.Context, text string, max int) (string, error) {
	name := strings.TrimSpace(text)
	if name == "" || utf8.RuneCountInString(name) > max {
		return "", &validationError{r.t(ctx, "telegram.error_invalid_name")}
	}
	return name, nil
}

func (r *UpdateRouter) findConfig(ctx context.Context, id int64) *models.AwgConfig {
	if id <= 0 {
		return nil
	}
	c, _ := r.Configs.Find(ctx, id)
	return c
}

func (r *UpdateRouter) findMembership(ctx context.Context, configID, clientID int64) *models.AwgConfigPeer {
	m, _ := r.Peers.FindMembership(ctx, configID, clientID)
	if m == nil {
		return nil
	}
	if cl, _ := r.Clients.Find(ctx, clientID); cl != nil {
		m.Client = cl
	}
	return m
}

func esc(v string) string { return html.EscapeString(v) }

func re(pat string) *regexp.Regexp { return regexp.MustCompile(pat) }

func atoi64(s string) int64 {
	n, _ := strconv.ParseInt(s, 10, 64)
	return n
}

func filterEmpty(in []string) []string {
	var out []string
	for _, s := range in {
		if s != "" {
			out = append(out, s)
		}
	}
	return out
}

func fmtErr(v any) string {
	if e, ok := v.(error); ok {
		return e.Error()
	}
	b, _ := json.Marshal(v)
	return string(b)
}

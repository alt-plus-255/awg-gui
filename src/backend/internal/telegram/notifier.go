package telegram

import (
	"context"
	"encoding/json"
	"html"
	"strings"

	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
)

const peerOnlineCacheKey = "telegram.peer.online"

type PeerNotifier struct {
	Settings *Settings
	Bot      *Client
	Stats    *stats.Service
	Peers    *store.Peers
	Configs  *store.Configs
	Clients  *store.Clients
	Cache    *store.Cache
}

func (n *PeerNotifier) CheckAndNotify(ctx context.Context) {
	if !n.Settings.IsConfigured(ctx) || !n.Settings.NotificationsEnabled(ctx) {
		return
	}
	if !n.Bot.IsReady(ctx) {
		return
	}
	locale := n.Settings.Language(ctx)
	previous := map[string]bool{}
	if raw, ok := n.Cache.Get(ctx, peerOnlineCacheKey); ok && raw != "" {
		_ = json.Unmarshal([]byte(raw), &previous)
	}
	if n.Stats != nil {
		n.Stats.RefreshFromDocker(ctx, nil)
	}
	memberships, err := n.Peers.ListAll(ctx)
	if err != nil {
		return
	}
	clients, _ := n.Clients.List(ctx)
	clientByID := map[int64]string{}
	for _, c := range clients {
		clientByID[c.ID] = c.Name
	}
	configs, _ := n.Configs.All(ctx)
	configByID := map[int64]string{}
	for _, c := range configs {
		configByID[c.ID] = c.Name
	}
	current := map[string]bool{}
	adminChatID := n.Settings.AdminID(ctx)
	hasBaseline := len(previous) > 0
	for i := range memberships {
		m := &memberships[i]
		key := strings.TrimSpace(m.PublicKey)
		if key == "" {
			continue
		}
		online := m.Online != nil && *m.Online
		current[key] = online
		if !hasBaseline {
			continue
		}
		was := previous[key]
		if was == online {
			continue
		}
		configName := configByID[m.AwgConfigID]
		if configName == "" {
			configName = "#" + itoa64str(m.AwgConfigID)
		}
		clientName := clientByID[m.VpnClientID]
		if clientName == "" {
			clientName = "#" + itoa64str(m.VpnClientID)
		}
		var text string
		if online {
			text = i18n.Tf(locale, "telegram.notify_online", map[string]string{
				"client": html.EscapeString(clientName),
				"config": html.EscapeString(configName),
			})
		} else {
			text = i18n.Tf(locale, "telegram.notify_offline", map[string]string{
				"client": html.EscapeString(clientName),
				"config": html.EscapeString(configName),
			})
		}
		n.Bot.SendMessage(ctx, adminChatID, text, nil)
	}
	b, _ := json.Marshal(current)
	n.Cache.PutForever(ctx, peerOnlineCacheKey, string(b))
}

func itoa64str(n int64) string {
	return asStr(n)
}

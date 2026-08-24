package telegram

import (
	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/resolver"
	"github.com/awggui/backend/internal/settings"
	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
	"github.com/awggui/backend/internal/system"
	"github.com/awggui/backend/internal/vpnuri"
)

type Service struct {
	Settings      *Settings
	Bot           *Client
	Pool          *ProxyPool
	Sync          *WebhookSync
	Router        *UpdateRouter
	Notifier      *PeerNotifier
	Report        *DailyReport
	Conversations *ConversationStore
	Cache         *store.Cache
	AWG           *awg.Service
}

func New(
	cfg config.Config,
	st *settings.Store,
	cache *store.Cache,
	awgSvc *awg.Service,
	res *resolver.Service,
	statsSvc *stats.Service,
	host *system.HostMetrics,
	vpnURI *vpnuri.Service,
	configs *store.Configs,
	peers *store.Peers,
	clients *store.Clients,
) *Service {
	tgSettings := NewSettings(st, cfg)
	pool := &ProxyPool{Settings: tgSettings, Cache: cache}
	bot := &Client{Settings: tgSettings, Pool: pool}
	pool.Bot = bot
	conv := &ConversationStore{Cache: cache}
	svc := &Service{
		Settings:      tgSettings,
		Bot:           bot,
		Pool:          pool,
		Conversations: conv,
		Cache:         cache,
		AWG:           awgSvc,
	}
	svc.Sync = &WebhookSync{Settings: tgSettings, Bot: bot, AWG: awgSvc, Pool: pool, Resolver: res}
	svc.Router = &UpdateRouter{
		Settings: tgSettings, Bot: bot, Conversations: conv, AWG: awgSvc, VpnURI: vpnURI,
		Resolver: res, Stats: statsSvc, Host: host, Configs: configs, Peers: peers, Clients: clients,
	}
	svc.Notifier = &PeerNotifier{
		Settings: tgSettings, Bot: bot, Stats: statsSvc, Peers: peers, Configs: configs, Clients: clients, Cache: cache,
	}
	svc.Report = &DailyReport{
		Settings: tgSettings, Bot: bot, Host: host, AWG: awgSvc, Configs: configs, Peers: peers, Clients: clients,
	}
	return svc
}

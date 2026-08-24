package server

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"

	"github.com/awggui/backend/internal/api"
	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/diagnostics"
	"github.com/awggui/backend/internal/docker"
	"github.com/awggui/backend/internal/panelops"
	"github.com/awggui/backend/internal/qrcode"
	"github.com/awggui/backend/internal/resolver"
	"github.com/awggui/backend/internal/settings"
	"github.com/awggui/backend/internal/ssl"
	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
	"github.com/awggui/backend/internal/system"
	"github.com/awggui/backend/internal/telegram"
	"github.com/awggui/backend/internal/update"
	"github.com/awggui/backend/internal/vpnuri"
	"github.com/awggui/backend/internal/ws"
	"github.com/go-chi/chi/v5"
	chimw "github.com/go-chi/chi/v5/middleware"
)

type App struct {
	Handler  http.Handler
	WS       *ws.Server
	Stats    *stats.Service
	Resolver *resolver.Service
	Telegram *telegram.Service
}

func New(cfg config.Config, db *sql.DB) *App {
	sessions, _ := auth.NewManager(db, cfg)
	users := auth.NewUserStore(db)
	protection := auth.NewLoginProtectionService(db)
	captcha := auth.NewCaptchaService(db)
	twoFactor := auth.NewTwoFactorService(users, sessions.Key())

	mw := &auth.Middleware{
		Sessions: sessions,
		Users:    users,
		DB:       db,
		Locale:   cfg.AppLocale,
	}

	authCtrl := &api.AuthController{
		Cfg:        cfg,
		DB:         db,
		Sessions:   sessions,
		Users:      users,
		Protection: protection,
		Captcha:    captcha,
		TwoFactor:  twoFactor,
	}
	twoFACtrl := &api.TwoFactorController{TwoFactor: twoFactor}

	settingsStore := settings.New(db)
	cacheStore := store.NewCache(db)
	clients := store.NewClients(db)
	configs := store.NewConfigs(db)
	peers := store.NewPeers(db)
	handshakes := store.NewHandshakes(db)
	dockerRT := docker.NewWithBin(cfg.DockerBin)
	awgSvc := awg.New(cfg, dockerRT, settingsStore, cacheStore, configs, peers, clients, handshakes)
	qr := qrcode.New()
	vpnURI := vpnuri.New(awgSvc, qr)

	resStore := &resolver.Store{DB: db}
	resKV := &resolver.KV{DB: db}
	resSvc := resolver.New(cfg, resStore, resKV)
	awgSvc.OnResolverApply = func(ctx context.Context, refresh bool) error {
		return resSvc.Apply(ctx, resolver.ApplyOpts{RefreshSubscriptions: refresh})
	}

	clientCtrl := &api.ClientController{AWG: awgSvc, Clients: clients, Configs: configs, Peers: peers}
	configCtrl := &api.ConfigController{
		AWG: awgSvc, QR: qr, VpnURI: vpnURI,
		Configs: configs, Peers: peers, Clients: clients,
	}

	statsSvc := stats.New(cfg, dockerRT, configs, peers, clients, handshakes)
	hostMetrics := system.NewHostMetrics(dockerRT)
	sysSvc := system.New(cfg, dockerRT, statsSvc, hostMetrics, cacheStore)
	diagSvc := diagnostics.New(cfg, db, dockerRT, statsSvc, sysSvc, settingsStore, configs, peers)
	tokens := &ws.TokenStore{Cache: cacheStore}
	broadcaster := stats.NewBroadcaster(statsSvc, hostMetrics)
	wsServer := ws.NewServer(broadcaster, tokens)

	ops := panelops.New(cfg)
	sslSvc := ssl.New(cfg, awgSvc, dockerRT, ops, settingsStore)
	updSvc := update.New(cfg, ops)
	tgSvc := telegram.New(cfg, settingsStore, cacheStore, awgSvc, resSvc, statsSvc, hostMetrics, vpnURI, configs, peers, clients)
	settingsCtrl := &api.SettingsController{
		AWG: awgSvc, Settings: settingsStore, SSL: sslSvc, Telegram: tgSvc, Updates: updSvc, PanelOps: ops,
	}

	statsCtrl := &api.StatsController{Stats: statsSvc, Host: hostMetrics, Peers: peers, Clients: clients}
	sysCtrl := &api.SystemController{Sys: sysSvc, Host: hostMetrics}
	diagCtrl := &api.DiagnosticsController{Diag: diagSvc}
	wsTokenCtrl := &api.WsTokenController{Tokens: tokens}
	resCtrl := &api.ResolverController{Svc: resSvc, AWG: awgSvc}
	resSettingsCtrl := &api.ResolverSettingsController{Svc: resSvc}
	resListsCtrl := &api.ResolverCustomListController{Svc: resSvc}
	resConnCtrl := &api.ResolverConnectionController{Svc: resSvc}
	resSpeedCtrl := &api.ResolverSpeedTestController{Svc: resSvc}

	r := chi.NewRouter()
	r.Use(chimw.RequestID)
	r.Use(chimw.RealIP)
	r.Use(chimw.Recoverer)
	r.Use(mw.SetLocale)
	r.Use(mw.StartSession)

	r.Get("/", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"name":   "awggui",
			"status": "ok",
		})
	})
	r.Get("/up", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok\n"))
	})
	r.Get("/sanctum/csrf-cookie", authCtrl.CsrfCookie)

	r.Route("/api", func(apiR chi.Router) {
		apiR.Use(mw.VerifyCSRF)
		apiR.Use(mw.RequireAuth)

		apiR.Post("/login", authCtrl.Login)
		apiR.Get("/login/status", authCtrl.LoginStatus)
		apiR.Get("/login/info", authCtrl.LoginInfo)
		apiR.Get("/login/captcha", authCtrl.LoginCaptcha)
		apiR.Post("/telegram/webhook/{secret}", settingsCtrl.TelegramWebhook)
		apiR.Post("/logout", authCtrl.Logout)
		apiR.Get("/me", authCtrl.Me)

		apiR.Get("/2fa/status", twoFACtrl.Status)
		apiR.Post("/2fa/setup", twoFACtrl.Setup)
		apiR.Post("/2fa/confirm", twoFACtrl.Confirm)
		apiR.Delete("/2fa", twoFACtrl.Destroy)

		apiR.Get("/system/status", sysCtrl.Status)
		apiR.Get("/system/processes", sysCtrl.Processes)
		apiR.Post("/system/restart-awg", sysCtrl.RestartAwg)
		apiR.Post("/system/restart-singbox", sysCtrl.RestartSingBox)
		apiR.Post("/system/restart-all", sysCtrl.RestartAll)

		apiR.Get("/diagnostics/status", diagCtrl.Status)
		apiR.Post("/diagnostics/run", diagCtrl.Run)
		apiR.Get("/diagnostics/configs/sing-box", diagCtrl.SingBoxConfig)
		apiR.Get("/diagnostics/configs/awg", diagCtrl.AwgConfigs)

		apiR.Get("/ws/token", wsTokenCtrl.Show)

		apiR.Get("/stats", statsCtrl.Index)
		apiR.Post("/stats/refresh", statsCtrl.Refresh)
		apiR.Get("/stats/live", statsCtrl.Live)

		apiR.Get("/clients", clientCtrl.Index)
		apiR.Post("/clients", clientCtrl.Store)
		apiR.Put("/clients/{clientID}", clientCtrl.Update)
		apiR.Delete("/clients/{clientID}", clientCtrl.Destroy)

		apiR.Get("/awg-protocol-versions", configCtrl.ProtocolVersions)
		apiR.Get("/configs", configCtrl.Index)
		apiR.Post("/configs", configCtrl.Store)
		apiR.Get("/configs/{configID}", configCtrl.Show)
		apiR.Put("/configs/{configID}", configCtrl.Update)
		apiR.Delete("/configs/{configID}", configCtrl.Destroy)
		apiR.Get("/configs/{configID}/server-config", configCtrl.ServerConfig)
		apiR.Post("/configs/{configID}/regenerate-server-keys", configCtrl.RegenerateServerKeys)
		apiR.Post("/configs/{configID}/regenerate-junk", configCtrl.RegenerateJunk)
		apiR.Post("/configs/{configID}/reveal-server-key", configCtrl.RevealServerKey)
		apiR.Post("/configs/{configID}/restart-awg", configCtrl.Restart)
		apiR.Put("/configs/{configID}/zones", configCtrl.UpdateZones)

		apiR.Get("/configs/{configID}/peers", configCtrl.ListPeers)
		apiR.Get("/configs/{configID}/links", configCtrl.Links)
		apiR.Post("/configs/{configID}/peers", configCtrl.AttachPeer)
		apiR.Put("/configs/{configID}/peers/{clientID}", configCtrl.UpdatePeer)
		apiR.Delete("/configs/{configID}/peers/{clientID}", configCtrl.DetachPeer)
		apiR.Get("/configs/{configID}/peers/{clientID}/config", configCtrl.PeerConfig)
		apiR.Get("/configs/{configID}/peers/{clientID}/vpn-uri", configCtrl.PeerVpnURI)
		apiR.Get("/configs/{configID}/peers/{clientID}/qr", configCtrl.PeerQR)
		apiR.Post("/configs/{configID}/peers/{clientID}/regenerate-keys", configCtrl.RegeneratePeerKeys)
		apiR.Post("/configs/{configID}/peers/{clientID}/regenerate-psk", configCtrl.RegeneratePeerPSK)
		apiR.Post("/configs/{configID}/peers/{clientID}/reveal-keys", configCtrl.RevealPeerKeys)
		apiR.Post("/configs/{configID}/peers/{clientID}/reset-traffic", configCtrl.ResetPeerTraffic)
		apiR.Get("/configs/{configID}/peers/{clientID}/handshake-logs", configCtrl.PeerHandshakeLogs)
		apiR.Get("/configs/{configID}/handshake-logs", configCtrl.HandshakeLogs)
		apiR.Delete("/configs/{configID}/handshake-logs", configCtrl.ClearHandshakeLogs)
		apiR.Post("/configs/{configID}/reset-traffic", configCtrl.ResetConfigTraffic)

		apiR.Get("/settings/detect-public-ip", settingsCtrl.DetectPublicIP)
		apiR.Post("/settings/restart-awg", settingsCtrl.RestartAWG)
		apiR.Get("/settings/update-status", settingsCtrl.UpdateStatus)
		apiR.Post("/settings/check-updates", settingsCtrl.CheckProjectUpdates)
		apiR.Post("/settings/update", settingsCtrl.StartProjectUpdate)
		apiR.Post("/settings/update/clear-log", settingsCtrl.ClearProjectUpdateLog)
		apiR.Post("/settings/update/retry-stuck", settingsCtrl.RetryStuckProjectUpdate)
		apiR.Get("/settings/awg-kernel", settingsCtrl.AWGKernelStatus)
		apiR.Post("/settings/awg-kernel/install", settingsCtrl.AWGKernelInstall)
		apiR.Post("/settings/awg-kernel/uninstall", settingsCtrl.AWGKernelUninstall)
		apiR.Post("/settings/test-webhook", settingsCtrl.TestWebhook)
		apiR.Post("/settings/test-telegram", settingsCtrl.TestTelegram)
		apiR.Post("/settings/test-telegram-proxy", settingsCtrl.TestTelegramProxy)
		apiR.Post("/settings/ssl/issue/start", settingsCtrl.SSLIssueStart)
		apiR.Post("/settings/ssl/issue/complete", settingsCtrl.SSLIssueComplete)
		apiR.Post("/settings/ssl/recover", settingsCtrl.SSLRecover)
		apiR.Post("/settings/ssl/disable", settingsCtrl.SSLDisable)
		apiR.Post("/settings/ssl/abort", settingsCtrl.SSLAbort)
		apiR.Get("/settings", settingsCtrl.Show)
		apiR.Put("/settings", settingsCtrl.Update)

		apiR.Get("/resolver", resCtrl.Show)
		apiR.Put("/resolver/configs/{config}", resCtrl.UpdateConfig)
		apiR.Post("/resolver/refresh", resCtrl.Refresh)
		apiR.Get("/resolver/diagnose", resCtrl.Diagnose)

		apiR.Get("/resolver/settings", resSettingsCtrl.Show)
		apiR.Put("/resolver/settings", resSettingsCtrl.Update)
		apiR.Post("/resolver/settings/sync-lists", resSettingsCtrl.SyncAll)
		apiR.Post("/resolver/settings/sync-lists/{tag}", resSettingsCtrl.SyncOne)

		apiR.Get("/resolver/custom-lists", resListsCtrl.Index)
		apiR.Post("/resolver/custom-lists", resListsCtrl.Store)
		apiR.Put("/resolver/custom-lists/{customList}", resListsCtrl.Update)
		apiR.Delete("/resolver/custom-lists/{customList}", resListsCtrl.Destroy)

		apiR.Get("/resolver/connections", resConnCtrl.Index)
		apiR.Get("/resolver/ping-session", resConnCtrl.PingSession)
		apiR.Post("/resolver/ping-probe/warmup", resConnCtrl.WarmupPingProbe)
		apiR.Post("/resolver/ping-probe/restart", resConnCtrl.RestartPingProbe)
		apiR.Post("/resolver/connections/parse-subscription", resConnCtrl.ParseSubscription)
		apiR.Post("/resolver/connections/ping-subscription", resConnCtrl.PingSubscription)
		apiR.Post("/resolver/connections/ping-subscription-stream", resConnCtrl.PingSubscriptionStream)
		apiR.Post("/resolver/connections/ping-subscription-node", resConnCtrl.PingSubscriptionNode)
		apiR.Post("/resolver/connections", resConnCtrl.Store)
		apiR.Post("/resolver/connections/{connection}/ping-subscription", resConnCtrl.PingConnectionSubscription)
		apiR.Post("/resolver/connections/{connection}/ping-subscription-stream", resConnCtrl.PingConnectionSubscriptionStream)
		apiR.Post("/resolver/connections/{connection}/ping-subscription-node", resConnCtrl.PingConnectionSubscriptionNode)
		apiR.Post("/resolver/connections/{connection}/sync-best-pick", resConnCtrl.SyncBestPick)
		apiR.Post("/resolver/connections/{connection}/test", resConnCtrl.Test)
		apiR.Get("/resolver/speed-test/status", resSpeedCtrl.Status)
		apiR.Post("/resolver/connections/{connection}/speed-test", resSpeedCtrl.RunConnection)
		apiR.Post("/resolver/speed-test/batch", resSpeedCtrl.RunBatch)
		apiR.Put("/resolver/connections/{connection}", resConnCtrl.Update)
		apiR.Delete("/resolver/connections/{connection}", resConnCtrl.Destroy)
	})

	return &App{Handler: r, WS: wsServer, Stats: statsSvc, Resolver: resSvc, Telegram: tgSvc}
}

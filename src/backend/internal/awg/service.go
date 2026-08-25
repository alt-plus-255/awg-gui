package awg

import (
	"context"
	"log"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/cps"
	"github.com/awggui/backend/internal/docker"
	"github.com/awggui/backend/internal/models"
	"github.com/awggui/backend/internal/settings"
	"github.com/awggui/backend/internal/store"
)

const (
	PortMin = 51820
	PortMax = 51839

	ClientImportNamePeer        = "peer_name"
	ClientImportNameVersionHost = "version_host"

	restartLockKey = "awg_restarting"
	restartLockTTL = 120 * time.Second

	DNSListenPort    = 53
	DNSRedirectPort  = 5353
	TProxyPort       = 1602
	FakeIPCIDR       = "198.18.0.0/15"
	TunIface         = "sbox0"
	EgressFallback   = "eth0"
	OnlineWindowSec  = 180
)

type Service struct {
	Cfg      config.Config
	Docker   *docker.Runtime
	Settings *settings.Store
	Cache    *store.Cache
	Configs  *store.Configs
	Peers    *store.Peers
	Clients  *store.Clients
	Logs     *store.Handshakes
	Versions *VersionRegistry

	mu                   sync.Mutex
	enabledPeersCache    map[int64][]models.AwgConfigPeer
	clientAllowedIPCache map[string]string
	egressCache          string

	// OnResolverApply is set by the server to avoid an awg↔resolver import cycle.
	OnResolverApply func(ctx context.Context, refreshSubscriptions bool) error
}

func New(
	cfg config.Config,
	d *docker.Runtime,
	st *settings.Store,
	cache *store.Cache,
	configs *store.Configs,
	peers *store.Peers,
	clients *store.Clients,
	logs *store.Handshakes,
) *Service {
	return &Service{
		Cfg:                  cfg,
		Docker:               d,
		Settings:             st,
		Cache:                cache,
		Configs:              configs,
		Peers:                peers,
		Clients:              clients,
		Logs:                 logs,
		Versions:             NewVersionRegistry(),
		enabledPeersCache:    map[int64][]models.AwgConfigPeer{},
		clientAllowedIPCache: map[string]string{},
	}
}

func (s *Service) ProfileFor(cfg *models.AwgConfig) VersionProfile {
	ver := ""
	if cfg != nil {
		ver = cfg.ProtocolVersion
	}
	return s.Versions.ProfileForConfig(ver)
}

func (s *Service) PrimeConfigPeerCache(ctx context.Context, cfg *models.AwgConfig) {
	_, _ = s.enabledPeersForConfig(ctx, cfg)
}

func (s *Service) ConfigDir() string {
	return strings.TrimRight(s.Cfg.AWGConfigDir, "/")
}

func (s *Service) ConfigPath(cfg *models.AwgConfig) string {
	return s.ConfigDir() + "/" + cfg.Iface + ".conf"
}

func (s *Service) HostConfigDir() string {
	return strings.TrimRight(s.Cfg.HostAWGConfigDir, "/")
}

func (s *Service) HostConfigPath(cfg *models.AwgConfig) string {
	return s.HostConfigDir() + "/" + cfg.Iface + ".conf"
}

func (s *Service) ContainerName() string {
	return s.Cfg.AWGContainer
}

func (s *Service) HostGUIDir() string {
	return strings.TrimRight(s.Cfg.HostAWGGUIDir, "/")
}

func (s *Service) IsContainerRunning(ctx context.Context) bool {
	if s.Docker == nil {
		return false
	}
	return s.Docker.ContainerRunning(ctx, s.ContainerName())
}

func (s *Service) ClientImportNameStyles() []string {
	return []string{ClientImportNamePeer, ClientImportNameVersionHost}
}

func (s *Service) ResolveClientImportNameStyle(cfg *models.AwgConfig, style string) string {
	style = strings.TrimSpace(style)
	if style != "" {
		if style == ClientImportNamePeer || style == ClientImportNameVersionHost {
			return style
		}
		return ClientImportNamePeer
	}
	if cfg != nil {
		st := strings.TrimSpace(cfg.ClientImportNameStyle)
		if st == ClientImportNamePeer || st == ClientImportNameVersionHost {
			return st
		}
	}
	return ClientImportNamePeer
}

func (s *Service) DefaultSettings() map[string]string {
	return map[string]string{
		"server_endpoint":                 s.Cfg.ServerEndpoint,
		"panel_domain":                    "",
		"endpoint_use_domain":             "0",
		"redirect_ip_to_domain":           "0",
		"panel_port":                      s.Cfg.PanelPort,
		"panel_https_port":                s.Cfg.PanelHTTPSPort,
		"ssl_email":                       "",
		"ssl_enabled":                     "0",
		"ssl_status":                      "disabled",
		"ssl_error":                       "",
		"ssl_expires_at":                  "",
		"ssl_pending_challenge":           "",
		"failure_webhook_url":             "",
		"timezone":                        s.Cfg.TZ,
		"telegram_bot_token":              "",
		"telegram_admin_id":               "",
		"telegram_mode":                   "polling",
		"telegram_proxies":                "[]",
		"telegram_proxy_strategy":         "fastest",
		"telegram_notifications_enabled":  "1",
		"telegram_daily_report_enabled":   "1",
		"telegram_webhook_secret":         "",
		"telegram_mixed_auth_user":        "",
		"telegram_mixed_auth_pass":        "",
		"singbox_egress_interface":        "auto",
	}
}

func (s *Service) DefaultConfigAttributes() map[string]any {
	subnet := s.Cfg.InternalSubnet
	serverAddress := "10.66.66.1/24"
	if m := subnetRE.FindStringSubmatch(subnet); m != nil {
		serverAddress = m[1] + ".1/" + m[3]
	}
	return map[string]any{
		"type":                      "server",
		"internal_subnet":           subnet,
		"server_address":            serverAddress,
		"peer_dns":                  s.Cfg.PeerDNS,
		"client_allowed_ips":        s.Cfg.AllowedIPs,
		"persistent_keepalive":      s.Cfg.PersistentKeepalive,
		"enabled":                   true,
		"client_import_name_style":  ClientImportNamePeer,
	}
}

func (s *Service) GatewayIP(cfg *models.AwgConfig) string {
	addr := cfg.ServerAddress
	if i := strings.Index(addr, "/"); i >= 0 {
		return addr[:i]
	}
	if addr != "" {
		return addr
	}
	return "10.66.66.1"
}

func (s *Service) NeedsServerKeys(cfg *models.AwgConfig) bool {
	return strings.TrimSpace(cfg.ServerPrivateKey) == "" || strings.TrimSpace(cfg.ServerPublicKey) == ""
}

func (s *Service) EnsureServerKeys(ctx context.Context, cfg *models.AwgConfig) (bool, error) {
	if !s.NeedsServerKeys(cfg) {
		return false, nil
	}
	keys, err := s.GenerateKeyPair(ctx)
	if err != nil {
		return false, err
	}
	cfg.ServerPrivateKey = keys.Private
	cfg.ServerPublicKey = keys.Public
	return true, s.Configs.Update(ctx, cfg)
}

func (s *Service) NeedsPeerKeys(m *models.AwgConfigPeer) bool {
	return strings.TrimSpace(m.PrivateKey) == "" || strings.TrimSpace(m.PublicKey) == ""
}

func (s *Service) EnsurePeerKeys(ctx context.Context, m *models.AwgConfigPeer) (bool, error) {
	if !s.NeedsPeerKeys(m) {
		return false, nil
	}
	keys, err := s.GenerateKeyPair(ctx)
	if err != nil {
		return false, err
	}
	m.PrivateKey = keys.Private
	m.PublicKey = keys.Public
	if m.PresharedKey == nil || *m.PresharedKey == "" {
		psk, err := GeneratePresharedKey()
		if err != nil {
			return false, err
		}
		m.PresharedKey = &psk
	}
	return true, s.Peers.Update(ctx, m)
}

func (s *Service) ApplyObfuscationParams(ctx context.Context, cfg *models.AwgConfig) (bool, error) {
	if !s.ProfileFor(cfg).NeedsObfuscationParams(cfg) {
		return false, nil
	}
	cfg.ApplyJunk(s.ProfileFor(cfg).GenerateJunkParams())
	return true, s.Configs.Update(ctx, cfg)
}

func (s *Service) SyncServerAddressFromSubnet(ctx context.Context, cfg *models.AwgConfig) error {
	if m := subnetRE.FindStringSubmatch(cfg.InternalSubnet); m != nil {
		cfg.ServerAddress = m[1] + ".1/" + m[3]
	}
	return s.Configs.Update(ctx, cfg)
}

func (s *Service) NextClientAddress(ctx context.Context, cfg *models.AwgConfig) (string, error) {
	m := subnetRE.FindStringSubmatch(cfg.InternalSubnet)
	if m == nil {
		return "", ErrInvalidSubnet
	}
	prefix := m[1]
	addrs, err := s.Peers.Addresses(ctx, cfg.ID)
	if err != nil {
		return "", err
	}
	used := map[int]bool{}
	lastOctet := regexp.MustCompile(`\.(\d+)/`)
	for _, addr := range addrs {
		if mm := lastOctet.FindStringSubmatch(addr); mm != nil {
			n, _ := strconv.Atoi(mm[1])
			if n > 0 {
				used[n] = true
			}
		}
	}
	for i := 2; i < 254; i++ {
		if !used[i] {
			return prefix + "." + strconv.Itoa(i) + "/32", nil
		}
	}
	return "", ErrNoFreeAddresses
}

func (s *Service) AllocateIface(ctx context.Context) (string, error) {
	used, err := s.Configs.Ifaces(ctx)
	if err != nil {
		return "", err
	}
	set := map[string]bool{}
	for _, i := range used {
		set[i] = true
	}
	for i := 0; i <= PortMax-PortMin; i++ {
		iface := "awg" + strconv.Itoa(i)
		if !set[iface] {
			return iface, nil
		}
	}
	return "", ErrConfigLimit
}

func (s *Service) NextFreeListenPort(ctx context.Context) (int, error) {
	used, err := s.Configs.ListenPorts(ctx)
	if err != nil {
		return 0, err
	}
	set := map[int]bool{}
	for _, p := range used {
		set[p] = true
	}
	for p := PortMin; p <= PortMax; p++ {
		if !set[p] {
			return p, nil
		}
	}
	return 0, ErrConfigLimit
}

func (s *Service) ApplyAfterClientChange(ctx context.Context, clientID int64) error {
	ids, err := s.Peers.ConfigIDsForClient(ctx, clientID)
	if err != nil {
		return err
	}
	if len(ids) == 0 {
		return s.ApplyConfig(ctx, nil, false, false)
	}
	for _, id := range ids {
		cfg, err := s.Configs.Find(ctx, id)
		if err != nil {
			return err
		}
		if cfg != nil {
			if err := s.ApplyConfig(ctx, cfg, false, false); err != nil {
				return err
			}
		}
	}
	return nil
}

func (s *Service) ApplyConfig(ctx context.Context, only *models.AwgConfig, withResolver, refreshSubscriptions bool) error {
	dir := s.ConfigDir()
	if err := os.MkdirAll(dir, 0755); err != nil {
		return err
	}

	if only != nil {
		if only.Enabled {
			body, err := s.BuildServerConfig(ctx, only)
			if err != nil {
				return err
			}
			path := s.ConfigPath(only)
			if err := os.WriteFile(path, []byte(body), 0644); err != nil {
				return err
			}
			_ = os.Chtimes(path, time.Now(), time.Now())
			s.syncIfaceLive(ctx, only)
		}
	} else {
		configs, err := s.Configs.ListEnabled(ctx)
		if err != nil {
			return err
		}
		active := map[string]bool{}
		for i := range configs {
			cfg := &configs[i]
			body, err := s.BuildServerConfig(ctx, cfg)
			if err != nil {
				return err
			}
			path := s.ConfigPath(cfg)
			if err := os.WriteFile(path, []byte(body), 0644); err != nil {
				return err
			}
			_ = os.Chtimes(path, time.Now(), time.Now())
			active[cfg.Iface] = true
			s.syncIfaceLive(ctx, cfg)
		}
		matches, _ := filepath.Glob(dir + "/awg*.conf")
		awgNum := regexp.MustCompile(`^awg\d+$`)
		for _, path := range matches {
			iface := strings.TrimSuffix(filepath.Base(path), ".conf")
			if !awgNum.MatchString(iface) {
				continue
			}
			if !active[iface] {
				_ = os.Remove(path)
			}
		}
	}

	if withResolver {
		if s.OnResolverApply != nil {
			if err := s.OnResolverApply(ctx, refreshSubscriptions); err != nil {
				log.Printf("resolver apply after awg config: %v", err)
			}
		}
	}
	_ = refreshSubscriptions
	return nil
}

func (s *Service) syncIfaceLive(ctx context.Context, cfg *models.AwgConfig) {
	if !s.IsContainerRunning(ctx) {
		return
	}
	path := s.ConfigPath(cfg)
	cmd := "awg syncconf " + cfg.Iface + " <(awg-quick strip " + path + ") 2>/dev/null || true"
	_ = s.Docker.Exec(ctx, s.ContainerName(), []string{"sh", "-c", cmd}, 15*time.Second, "")
}

func (s *Service) IsAWGRestarting(ctx context.Context) bool {
	if s.Cache == nil {
		return false
	}
	return s.Cache.Has(ctx, restartLockKey)
}

func (s *Service) RestartAWG(ctx context.Context) map[string]any {
	if s.Cache != nil && !s.Cache.Add(ctx, restartLockKey, store.UnixString(time.Now()), restartLockTTL) {
		return map[string]any{
			"ok":                 false,
			"already_restarting": true,
			"exit_code":          nil,
			"stderr":             "",
		}
	}
	defer func() {
		if s.Cache != nil {
			s.Cache.Forget(ctx, restartLockKey)
		}
	}()

	_ = s.ApplyConfig(ctx, nil, true, true)
	res := s.Docker.Restart(ctx, s.ContainerName(), 60*time.Second)
	return map[string]any{
		"ok":        res.Successful(),
		"exit_code": res.ExitCode,
		"stderr":    strings.TrimSpace(res.Stderr),
	}
}

func (s *Service) RegenerateConfigKeys(ctx context.Context, cfg *models.AwgConfig) (string, error) {
	keys, err := s.GenerateKeyPair(ctx)
	if err != nil {
		return "", err
	}
	cfg.ServerPrivateKey = keys.Private
	cfg.ServerPublicKey = keys.Public
	if err := s.Configs.Update(ctx, cfg); err != nil {
		return "", err
	}
	if err := s.ApplyConfig(ctx, nil, true, true); err != nil {
		return "", err
	}
	return keys.Public, nil
}

func (s *Service) RegenerateConfigJunk(ctx context.Context, cfg *models.AwgConfig) (map[string]string, error) {
	return s.RegenerateConfigJunkWithCPS(ctx, cfg, cps.DefaultProtocol())
}

func (s *Service) RegenerateConfigJunkWithCPS(ctx context.Context, cfg *models.AwgConfig, cpsProtocol string) (map[string]string, error) {
	junk := s.ProfileFor(cfg).GenerateJunkParamsWithCPS(cpsProtocol)
	cfg.ApplyJunk(junk)
	if err := s.Configs.Update(ctx, cfg); err != nil {
		return nil, err
	}
	if err := s.ApplyConfig(ctx, nil, true, true); err != nil {
		return nil, err
	}
	return junk, nil
}

func (s *Service) ForgetEgressCache() {
	s.mu.Lock()
	s.egressCache = ""
	s.mu.Unlock()
}

var (
	ErrInvalidSubnet   = errString("invalid_internal_subnet")
	ErrNoFreeAddresses = errString("no_free_addresses")
	ErrConfigLimit     = errString("config_limit_reached")
	ErrEmptyEndpoint   = errString("endpoint_empty")
	ErrInvalidEndpoint = errString("invalid_endpoint")
	ErrNoConfig        = errString("no_awg_config")
	ErrPortConflict    = errString("port_conflict")
	ErrRestartFailed   = errString("restart_failed")
)

type errString string

func (e errString) Error() string { return string(e) }

var subnetRE = regexp.MustCompile(`^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$`)

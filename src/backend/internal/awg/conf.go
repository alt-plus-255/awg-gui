package awg

import (
	"context"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/awggui/backend/internal/models"
)

func (s *Service) BuildServerConfig(ctx context.Context, cfg *models.AwgConfig) (string, error) {
	if changed, err := s.EnsureServerKeys(ctx, cfg); err != nil {
		return "", err
	} else if changed {
		fresh, err := s.Configs.Find(ctx, cfg.ID)
		if err != nil {
			return "", err
		}
		if fresh != nil {
			*cfg = *fresh
		}
	}

	lines := []string{
		"[Interface]",
		"PrivateKey = " + cfg.ServerPrivateKey,
		"Address = " + cfg.ServerAddress,
		"ListenPort = " + strconv.Itoa(cfg.ListenPort),
	}
	lines = append(lines, s.ProfileFor(cfg).ConfObfuscationLines(cfg)...)
	lines = append(lines, "PostUp = "+s.buildPostUp(ctx, cfg))
	lines = append(lines, "PostDown = "+s.buildPostDown(ctx, cfg))
	lines = append(lines, "")

	memberships, err := s.Peers.ListEnabledByConfig(ctx, cfg.ID)
	if err != nil {
		return "", err
	}
	for i := range memberships {
		m := &memberships[i]
		if m.Config == nil {
			cp := *cfg
			m.Config = &cp
		}
		if changed, err := s.EnsurePeerKeys(ctx, m); err != nil {
			return "", err
		} else if changed {
			fresh, err := s.Peers.Find(ctx, m.ID)
			if err != nil {
				return "", err
			}
			if fresh != nil {
				*m = *fresh
				m.Config = cfg
			}
		}
		name := "peer"
		if cl, err := s.Clients.Find(ctx, m.VpnClientID); err == nil && cl != nil && cl.Name != "" {
			name = cl.Name
		}
		lines = append(lines, "[Peer]")
		lines = append(lines, "# "+name)
		lines = append(lines, "PublicKey = "+m.PublicKey)
		if m.PresharedKey != nil && *m.PresharedKey != "" {
			lines = append(lines, "PresharedKey = "+*m.PresharedKey)
		}
		lines = append(lines, "AllowedIPs = "+s.ServerPeerAllowedIPsString(m))
		lines = append(lines, "")
	}
	return strings.Join(lines, "\n") + "\n", nil
}

func (s *Service) ClientImportLabel(ctx context.Context, membership *models.AwgConfigPeer, endpointHost, style string) (string, error) {
	cfg := membership.Config
	if cfg == nil {
		c, err := s.Configs.Find(ctx, membership.AwgConfigID)
		if err != nil {
			return "", err
		}
		if c == nil {
			return "", ErrNoConfig
		}
		cfg = c
		membership.Config = c
	}
	style = s.ResolveClientImportNameStyle(cfg, style)
	if style == ClientImportNameVersionHost {
		version := strings.TrimSpace(cfg.ProtocolVersion)
		if version == "" {
			version = s.Versions.Latest()
		}
		host := strings.TrimSpace(endpointHost)
		if host == "" {
			host = s.ResolveEndpointHost(ctx, "")
		}
		if host == "" {
			host = "127.0.0.1"
		}
		return "AWG-v" + version + "-" + host, nil
	}
	peerName := "peer"
	if membership.Client != nil && strings.TrimSpace(membership.Client.Name) != "" {
		peerName = strings.TrimSpace(membership.Client.Name)
	} else if cl, err := s.Clients.Find(ctx, membership.VpnClientID); err == nil && cl != nil && strings.TrimSpace(cl.Name) != "" {
		peerName = strings.TrimSpace(cl.Name)
	}
	return "awg-" + peerName, nil
}

func (s *Service) ClientImportFilename(ctx context.Context, membership *models.AwgConfigPeer, endpointHost, style string) string {
	base, err := s.ClientImportLabel(ctx, membership, endpointHost, style)
	if err != nil || base == "" {
		base = "awg-client"
	}
	safe := unsafeName.ReplaceAllString(base, "-")
	if safe == "" {
		safe = "awg-client"
	}
	return safe + ".conf"
}

func (s *Service) BuildClientConfig(ctx context.Context, membership *models.AwgConfigPeer) (string, error) {
	cfg := membership.Config
	if cfg == nil {
		c, err := s.Configs.Find(ctx, membership.AwgConfigID)
		if err != nil {
			return "", err
		}
		if c == nil {
			return "", ErrNoConfig
		}
		cfg = c
		membership.Config = c
	}
	if changed, err := s.EnsurePeerKeys(ctx, membership); err != nil {
		return "", err
	} else if changed {
		fresh, err := s.Peers.Find(ctx, membership.ID)
		if err != nil {
			return "", err
		}
		if fresh != nil {
			*membership = *fresh
			membership.Config = cfg
		}
	}
	if changed, err := s.EnsureServerKeys(ctx, cfg); err != nil {
		return "", err
	} else if changed {
		fresh, err := s.Configs.Find(ctx, cfg.ID)
		if err != nil {
			return "", err
		}
		if fresh != nil {
			*cfg = *fresh
			membership.Config = cfg
		}
	}

	endpointHost := s.ResolveEndpointHost(ctx, "")
	dns := cfg.PeerDNS
	if dns == "" {
		dns = "1.1.1.1"
	}
	if cfg.IsResolverEnabled() {
		dns = s.GatewayIP(cfg)
	}
	allowed := s.ClientAllowedIPsString(ctx, cfg, membership)
	keepalive := 25
	if cfg.PersistentKeepalive > 0 {
		keepalive = cfg.PersistentKeepalive
	}
	if membership.Keepalive != nil {
		keepalive = *membership.Keepalive
	}
	importLabel, err := s.ClientImportLabel(ctx, membership, endpointHost, "")
	if err != nil {
		return "", err
	}

	lines := []string{
		"# Name = " + importLabel,
		"[Interface]",
		"PrivateKey = " + membership.PrivateKey,
		"Address = " + membership.Address,
		"DNS = " + dns,
		"MTU = 1420",
	}
	lines = append(lines, s.ProfileFor(cfg).ConfObfuscationLines(cfg)...)
	lines = append(lines, "", "[Peer]")
	lines = append(lines, "PublicKey = "+cfg.ServerPublicKey)
	if membership.PresharedKey != nil && *membership.PresharedKey != "" {
		lines = append(lines, "PresharedKey = "+*membership.PresharedKey)
	}
	lines = append(lines, "AllowedIPs = "+allowed)
	lines = append(lines, "Endpoint = "+endpointHost+":"+strconv.Itoa(cfg.ListenPort))
	lines = append(lines, "PersistentKeepalive = "+strconv.Itoa(keepalive))
	return strings.Join(lines, "\n") + "\n", nil
}

func (s *Service) buildPostUp(ctx context.Context, cfg *models.AwgConfig) string {
	egress := s.ResolveEgress(ctx)
	parts := []string{}
	if fw := s.peerFirewallPostUpParts(ctx, cfg); len(fw) > 0 {
		parts = append(parts, fw...)
	}
	parts = append(parts,
		"iptables -A FORWARD -i %i -j ACCEPT",
		"iptables -A FORWARD -o %i -j ACCEPT",
		"iptables -t nat -A POSTROUTING -o "+egress+" -j MASQUERADE",
	)
	if cfg.IsResolverEnabled() {
		parts = append(parts,
			"iptables -t nat -A PREROUTING -i %i -p udp --dport 53 -j REDIRECT --to-ports "+strconv.Itoa(DNSListenPort),
			"iptables -t nat -A PREROUTING -i %i -p tcp --dport 53 -j REDIRECT --to-ports "+strconv.Itoa(DNSListenPort),
		)
		parts = append(parts, s.legacyResolverIptablesCleanup()...)
		rejectQuic := "0"
		if cfg.ResolverRejectQuic {
			rejectQuic = "1"
		}
		parts = append(parts, "sh /config/resolver-mark.sh %i "+rejectQuic)
	}
	return strings.Join(parts, "; ")
}

func (s *Service) buildPostDown(ctx context.Context, cfg *models.AwgConfig) string {
	egress := s.ResolveEgress(ctx)
	parts := []string{}
	if fw := s.peerFirewallPostDownParts(cfg); len(fw) > 0 {
		parts = append(parts, fw...)
	}
	parts = append(parts,
		"iptables -D FORWARD -i %i -j ACCEPT",
		"iptables -D FORWARD -o %i -j ACCEPT",
		"iptables -t nat -D POSTROUTING -o "+egress+" -j MASQUERADE",
		"iptables -t nat -D POSTROUTING -o eth+ -j MASQUERADE 2>/dev/null || true",
	)
	if cfg.IsResolverEnabled() {
		parts = append(parts,
			"iptables -t nat -D PREROUTING -i %i -p udp --dport 53 -j REDIRECT --to-ports "+strconv.Itoa(DNSListenPort)+" 2>/dev/null || true",
			"iptables -t nat -D PREROUTING -i %i -p tcp --dport 53 -j REDIRECT --to-ports "+strconv.Itoa(DNSListenPort)+" 2>/dev/null || true",
			"sh /config/resolver-unmark.sh %i 2>/dev/null || true",
		)
		parts = append(parts, s.legacyResolverIptablesCleanup()...)
	}
	return strings.Join(parts, "; ")
}

func (s *Service) legacyResolverIptablesCleanup() []string {
	fakeip := FakeIPCIDR
	tproxy := strconv.Itoa(TProxyPort)
	dnsPort := strconv.Itoa(DNSRedirectPort)
	return []string{
		"iptables -t mangle -D PREROUTING -i %i -d " + fakeip + " -p tcp -j TPROXY --on-port " + tproxy + " --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t mangle -D PREROUTING -i %i -d " + fakeip + " -p udp -j TPROXY --on-port " + tproxy + " --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t mangle -D PREROUTING -i %i -d " + fakeip + " -p tcp -j TPROXY --on-port " + tproxy + " --on-ip 0.0.0.0 --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t mangle -D PREROUTING -i %i -d " + fakeip + " -p udp -j TPROXY --on-port " + tproxy + " --on-ip 0.0.0.0 --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t mangle -D PREROUTING -i %i -d " + fakeip + " -p tcp -j TPROXY --on-port " + tproxy + " --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t mangle -D PREROUTING -i %i -d " + fakeip + " -p udp -j TPROXY --on-port " + tproxy + " --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t mangle -D PREROUTING -i %i -p udp --dport 53 -j TPROXY --on-port " + dnsPort + " --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t mangle -D PREROUTING -i %i -p tcp --dport 53 -j TPROXY --on-port " + dnsPort + " --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
		"iptables -t nat -D PREROUTING -i %i -d " + fakeip + " -p tcp -j REDIRECT --to-ports " + tproxy + " 2>/dev/null || true",
		"iptables -D FORWARD -i %i -o " + TunIface + " -j ACCEPT 2>/dev/null || true",
		"iptables -D FORWARD -i " + TunIface + " -o %i -j ACCEPT 2>/dev/null || true",
	}
}

func (s *Service) ResolveEgress(ctx context.Context) string {
	s.mu.Lock()
	if s.egressCache != "" {
		v := s.egressCache
		s.mu.Unlock()
		return v
	}
	s.mu.Unlock()

	setting := "auto"
	if s.Settings != nil {
		setting = strings.TrimSpace(s.Settings.GetValue(ctx, "singbox_egress_interface", "auto"))
	}
	if setting != "" && setting != "auto" && IsValidIfaceName(setting) {
		s.mu.Lock()
		s.egressCache = setting
		s.mu.Unlock()
		return setting
	}
	if detected := s.detectEgress(ctx); detected != "" {
		s.mu.Lock()
		s.egressCache = detected
		s.mu.Unlock()
		return detected
	}
	return EgressFallback
}

func (s *Service) detectEgress(ctx context.Context) string {
	if !s.IsContainerRunning(ctx) {
		return ""
	}
	script := `iface=$(ip -4 route show default 0.0.0.0/0 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}'); ` +
		`if [ -z "$iface" ]; then iface=$(ip -o -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}'); fi; echo "$iface"`
	r := s.Docker.Exec(ctx, s.ContainerName(), []string{"sh", "-c", script}, 8*time.Second, "")
	iface := strings.TrimSpace(r.Stdout)
	if IsValidIfaceName(iface) && !isTunnelIface(iface) {
		return iface
	}
	return ""
}

func (s *Service) EgressStatus(ctx context.Context) map[string]any {
	setting := "auto"
	if s.Settings != nil {
		setting = strings.TrimSpace(s.Settings.GetValue(ctx, "singbox_egress_interface", "auto"))
		if setting == "" {
			setting = "auto"
		}
	}
	detected := s.detectEgress(ctx)
	var detectedAny any
	if detected != "" {
		detectedAny = detected
	} else {
		detectedAny = nil
	}
	return map[string]any{
		"setting":  setting,
		"resolved": s.ResolveEgress(ctx),
		"detected": detectedAny,
		"options":  s.listEgressCandidates(ctx, setting, detected),
	}
}

func (s *Service) listEgressCandidates(ctx context.Context, setting, detected string) []string {
	var out []string
	if s.IsContainerRunning(ctx) {
		r := s.Docker.Exec(ctx, s.ContainerName(), []string{"sh", "-c",
			"ip -o link show 2>/dev/null | awk -F': ' '{print $2}' | cut -d'@' -f1"}, 8*time.Second, "")
		for _, line := range strings.Split(strings.TrimSpace(r.Stdout), "\n") {
			iface := strings.TrimSpace(line)
			if !IsValidIfaceName(iface) || iface == "lo" || isTunnelIface(iface) {
				continue
			}
			out = append(out, iface)
		}
	}
	if detected != "" && !contains(out, detected) {
		out = append(out, detected)
	}
	if setting != "auto" && IsValidIfaceName(setting) && !contains(out, setting) {
		out = append(out, setting)
	}
	return out
}

func IsValidIfaceName(iface string) bool {
	return ifaceNameRE.MatchString(iface)
}

func isTunnelIface(iface string) bool {
	return tunnelIfaceRE.MatchString(iface) || iface == TunIface
}

var (
	unsafeName    = regexp.MustCompile(`[^a-zA-Z0-9._-]+`)
	ifaceNameRE   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,14}$`)
	tunnelIfaceRE = regexp.MustCompile(`^(awg|awgc)\d+$`)
)

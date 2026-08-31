package awg

import (
	"context"
	"net"
	"strings"
	"time"

	"github.com/awggui/backend/internal/models"
)

const peerFirewallChainPrefix = "AWGGUI-FWD-"

func PeerFirewallChainName(iface string) string {
	return peerFirewallChainPrefix + iface
}

func NormalizeForwardPolicy(v string) string {
	v = strings.TrimSpace(v)
	if v == "restricted" {
		return "restricted"
	}
	return "allow_all"
}

func IsRestrictedForwardPolicy(v string) bool {
	return NormalizeForwardPolicy(v) == "restricted"
}

func PeerSourceIP(address string) string {
	ipStr, _, ok := strings.Cut(strings.TrimSpace(address), "/")
	if !ok || ipStr == "" {
		return strings.TrimSpace(address)
	}
	if ip := net.ParseIP(ipStr); ip != nil {
		if v4 := ip.To4(); v4 != nil {
			return v4.String()
		}
		return ip.String()
	}
	return ipStr
}

func (s *Service) restrictedFirewallPeers(ctx context.Context, cfg *models.AwgConfig) []models.AwgConfigPeer {
	if cfg == nil || cfg.Type != "server" {
		return nil
	}
	peers, err := s.enabledPeersForConfig(ctx, cfg)
	if err != nil {
		return nil
	}
	var out []models.AwgConfigPeer
	for _, p := range peers {
		if !IsRestrictedForwardPolicy(p.ForwardPolicy) {
			continue
		}
		if len(p.ForwardAllowedCIDRs) == 0 {
			continue
		}
		src := PeerSourceIP(p.Address)
		if src == "" {
			continue
		}
		out = append(out, p)
	}
	return out
}

func peerFirewallChainPlaceholder() string {
	return peerFirewallChainPrefix + "%i"
}

func (s *Service) peerFirewallRuleParts(ctx context.Context, cfg *models.AwgConfig, ifacePlaceholder string) []string {
	restricted := s.restrictedFirewallPeers(ctx, cfg)
	if len(restricted) == 0 {
		return nil
	}
	chain := peerFirewallChainPrefix + ifacePlaceholder
	var parts []string
	parts = append(parts,
		"iptables -N "+chain+" 2>/dev/null || iptables -F "+chain,
	)
	for _, p := range restricted {
		src := PeerSourceIP(p.Address)
		parts = append(parts,
			"iptables -A "+chain+" -i "+ifacePlaceholder+" -s "+src+" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT",
		)
		for _, cidr := range uniqueNonEmpty(p.ForwardAllowedCIDRs) {
			canonical := CanonicalNetworkCIDR(cidr)
			if canonical == "" {
				canonical = strings.TrimSpace(cidr)
			}
			if canonical == "" {
				continue
			}
			parts = append(parts,
				"iptables -A "+chain+" -i "+ifacePlaceholder+" -s "+src+" -d "+canonical+" -j ACCEPT",
			)
		}
		parts = append(parts,
			"iptables -A "+chain+" -i "+ifacePlaceholder+" -s "+src+" -j DROP",
		)
	}
	return parts
}

func (s *Service) peerFirewallPostUpParts(ctx context.Context, cfg *models.AwgConfig) []string {
	if cfg.Type != "server" {
		return nil
	}
	parts := s.peerFirewallRuleParts(ctx, cfg, "%i")
	if len(parts) == 0 {
		return nil
	}
	chain := peerFirewallChainPlaceholder()
	return append(parts, "iptables -A FORWARD -i %i -j "+chain)
}

func (s *Service) peerFirewallPostDownParts(cfg *models.AwgConfig) []string {
	if cfg.Type != "server" {
		return nil
	}
	chain := peerFirewallChainPlaceholder()
	return []string{
		"iptables -D FORWARD -i %i -j " + chain + " 2>/dev/null || true",
		"iptables -F " + chain + " 2>/dev/null || true",
		"iptables -X " + chain + " 2>/dev/null || true",
	}
}

func (s *Service) buildPeerFirewallApplyScript(ctx context.Context, cfg *models.AwgConfig, iface string) string {
	if cfg == nil || cfg.Type != "server" || !IsValidIfaceName(iface) {
		return ""
	}
	chain := PeerFirewallChainName(iface)
	var parts []string
	parts = append(parts,
		"iptables -D FORWARD -i "+iface+" -j "+chain+" 2>/dev/null || true",
		"iptables -F "+chain+" 2>/dev/null || true",
		"iptables -X "+chain+" 2>/dev/null || true",
	)
	ruleParts := s.peerFirewallRuleParts(ctx, cfg, iface)
	if len(ruleParts) == 0 {
		return strings.Join(parts, "; ")
	}
	parts = append(parts, ruleParts...)
	parts = append(parts, "iptables -C FORWARD -i "+iface+" -j "+chain+" 2>/dev/null || iptables -A FORWARD -i "+iface+" -j "+chain)
	return strings.Join(parts, "; ")
}

func (s *Service) ApplyPeerFirewall(ctx context.Context, cfg *models.AwgConfig) {
	if cfg == nil || !cfg.Enabled || cfg.Type != "server" {
		return
	}
	if !s.IsContainerRunning(ctx) {
		return
	}
	script := s.buildPeerFirewallApplyScript(ctx, cfg, cfg.Iface)
	if script == "" {
		return
	}
	_ = s.Docker.Exec(ctx, s.ContainerName(), []string{"sh", "-c", script}, 15*time.Second, "")
}

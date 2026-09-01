package awg

import (
	"context"
	"net"
	"strconv"
	"strings"

	"github.com/awggui/backend/internal/models"
)

func (s *Service) enabledPeersForConfig(ctx context.Context, cfg *models.AwgConfig) ([]models.AwgConfigPeer, error) {
	s.mu.Lock()
	if cached, ok := s.enabledPeersCache[cfg.ID]; ok {
		s.mu.Unlock()
		return cached, nil
	}
	s.mu.Unlock()

	peers, err := s.Peers.ListEnabledByConfig(ctx, cfg.ID)
	if err != nil {
		return nil, err
	}
	s.mu.Lock()
	s.enabledPeersCache[cfg.ID] = peers
	s.mu.Unlock()
	return peers, nil
}

func (s *Service) ServerPeerAllowedIPs(membership *models.AwgConfigPeer) []string {
	ips := []string{membership.Address}
	if membership.Config != nil && membership.Config.Type == "server" {
		return uniqueNonEmpty(ips)
	}
	for _, cidr := range membership.ExtraAllowedIPs {
		cidr = strings.TrimSpace(cidr)
		if cidr == "" || cidr == membership.Address {
			continue
		}
		ips = append(ips, cidr)
	}
	return uniqueNonEmpty(ips)
}

func (s *Service) ServerPeerAllowedIPsString(membership *models.AwgConfigPeer) string {
	return strings.Join(s.ServerPeerAllowedIPs(membership), ", ")
}

func (s *Service) isPeerExcluded(membership, other models.AwgConfigPeer) bool {
	for _, id := range membership.ExcludedClientIDs {
		if id == other.VpnClientID {
			return true
		}
	}
	if other.ExclusionsMutual {
		for _, id := range other.ExcludedClientIDs {
			if id == membership.VpnClientID {
				return true
			}
		}
	}
	return false
}

func (s *Service) ruleDirection(cfg *models.AwgConfig, membership, other models.AwgConfigPeer) string {
	ownID := membership.VpnClientID
	otherID := other.VpnClientID
	forward, reply := false, false
	for _, rule := range cfg.VnZones().Rules {
		if containsInt(rule.SrcClientIDs, ownID) && containsInt(rule.DestClientIDs, otherID) {
			forward = true
		}
		if containsInt(rule.DestClientIDs, ownID) && containsInt(rule.SrcClientIDs, otherID) {
			reply = true
		}
	}
	if forward {
		return "forward"
	}
	if reply {
		return "reply"
	}
	return ""
}

func (s *Service) ClientAllowedIPs(ctx context.Context, cfg *models.AwgConfig, membership *models.AwgConfigPeer) []string {
	if cfg.Type == "virtual_network" {
		denyAll := cfg.VnPolicy == "deny_all"
		ips := []string{membership.Address}
		others, err := s.enabledPeersForConfig(ctx, cfg)
		if err != nil {
			return uniqueNonEmpty(ips)
		}
		for _, other := range others {
			if other.ID == membership.ID {
				continue
			}
			if denyAll {
				switch s.ruleDirection(cfg, *membership, other) {
				case "forward":
					for _, cidr := range other.ExtraAllowedIPs {
						cidr = strings.TrimSpace(cidr)
						if cidr != "" {
							ips = append(ips, cidr)
						}
					}
				case "reply":
					if other.Address != "" {
						ips = append(ips, other.Address)
					}
				}
				continue
			}
			if s.isPeerExcluded(*membership, other) {
				continue
			}
			for _, cidr := range other.ExtraAllowedIPs {
				cidr = strings.TrimSpace(cidr)
				if cidr != "" {
					ips = append(ips, cidr)
				}
			}
		}
		return uniqueNonEmpty(ips)
	}

	if cfg.IsResolverEnabled() {
		return []string{"0.0.0.0/0", "::/0"}
	}

	var splitCidrs []string
	for _, cidr := range membership.ExtraAllowedIPs {
		cidr = strings.TrimSpace(cidr)
		if cidr == "" || cidr == "0.0.0.0/0" || cidr == "::/0" {
			continue
		}
		canonical := CanonicalNetworkCIDR(cidr)
		if canonical == "" {
			canonical = cidr
		}
		if canonical == "0.0.0.0/0" || canonical == "::/0" {
			continue
		}
		splitCidrs = append(splitCidrs, canonical)
	}
	splitCidrs = uniqueNonEmpty(splitCidrs)
	if membership.SplitTunnel {
		var ips []string
		tunnel := strings.TrimSpace(cfg.InternalSubnet)
		if tunnel == "" {
			tunnel = strings.TrimSpace(cfg.ServerAddress)
		}
		if tunnelCIDR := CanonicalNetworkCIDR(tunnel); tunnelCIDR != "" && tunnelCIDR != "0.0.0.0/0" && tunnelCIDR != "::/0" {
			ips = append(ips, tunnelCIDR)
		}
		for _, cidr := range splitCidrs {
			if !contains(ips, cidr) {
				ips = append(ips, cidr)
			}
		}
		return ips
	}

	raw := cfg.ClientAllowedIPs
	if raw == "" {
		raw = "0.0.0.0/0, ::/0"
	}
	var ips []string
	for _, p := range strings.Split(raw, ",") {
		p = strings.TrimSpace(p)
		if p != "" {
			ips = append(ips, p)
		}
	}
	return ips
}

func (s *Service) PeerClientConfigOmitsDNS(cfg *models.AwgConfig, membership *models.AwgConfigPeer) bool {
	if cfg.IsResolverEnabled() {
		return false
	}
	return membership.SplitTunnel
}

func allowedIPCacheKey(configID, membershipID int64) string {
	return strconv.FormatInt(configID, 10) + ":" + strconv.FormatInt(membershipID, 10)
}

func (s *Service) InvalidateAllowedIPCache(configID, membershipID int64) {
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.clientAllowedIPCache, allowedIPCacheKey(configID, membershipID))
	delete(s.enabledPeersCache, configID)
}

func (s *Service) InvalidateConfigPeerCaches(configID int64) {
	prefix := strconv.FormatInt(configID, 10) + ":"
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.enabledPeersCache, configID)
	for k := range s.clientAllowedIPCache {
		if strings.HasPrefix(k, prefix) {
			delete(s.clientAllowedIPCache, k)
		}
	}
}

func (s *Service) ClientAllowedIPsString(ctx context.Context, cfg *models.AwgConfig, membership *models.AwgConfigPeer) string {
	key := allowedIPCacheKey(cfg.ID, membership.ID)
	s.mu.Lock()
	if v, ok := s.clientAllowedIPCache[key]; ok {
		s.mu.Unlock()
		return v
	}
	s.mu.Unlock()
	v := strings.Join(s.ClientAllowedIPs(ctx, cfg, membership), ", ")
	s.mu.Lock()
	s.clientAllowedIPCache[key] = v
	s.mu.Unlock()
	return v
}

func CanonicalNetworkCIDR(cidr string) string {
	cidr = strings.TrimSpace(cidr)
	if cidr == "" || !strings.Contains(cidr, "/") {
		return ""
	}
	ipStr, prefixStr, ok := strings.Cut(cidr, "/")
	if !ok {
		return ""
	}
	ipStr = strings.TrimSpace(ipStr)
	prefix, err := strconv.Atoi(strings.TrimSpace(prefixStr))
	if err != nil {
		return ""
	}
	ip := net.ParseIP(ipStr)
	if ip == nil {
		return ""
	}
	if v4 := ip.To4(); v4 != nil {
		if prefix < 0 || prefix > 32 {
			return ""
		}
		mask := net.CIDRMask(prefix, 32)
		network := net.IPv4(v4[0], v4[1], v4[2], v4[3]).Mask(mask)
		return network.String() + "/" + strconv.Itoa(prefix)
	}
	if prefix < 0 || prefix > 128 {
		return ""
	}
	ip16 := ip.To16()
	if ip16 == nil {
		return ""
	}
	bytes := make([]byte, 16)
	copy(bytes, ip16)
	for i := 0; i < 16; i++ {
		bitStart := i * 8
		if prefix >= bitStart+8 {
			continue
		}
		if prefix <= bitStart {
			bytes[i] = 0
			continue
		}
		keep := prefix - bitStart
		bytes[i] = bytes[i] & (0xFF << (8 - keep))
	}
	return net.IP(bytes).String() + "/" + strconv.Itoa(prefix)
}

func NormalizeSubnetKey(subnet string) string {
	subnet = strings.TrimSpace(subnet)
	ipStr, prefixStr, ok := strings.Cut(subnet, "/")
	if !ok {
		return ""
	}
	prefix, err := strconv.Atoi(prefixStr)
	if err != nil || prefix < 0 || prefix > 32 {
		return ""
	}
	ip := net.ParseIP(strings.TrimSpace(ipStr))
	if ip == nil {
		return ""
	}
	v4 := ip.To4()
	if v4 == nil {
		return ""
	}
	mask := net.CIDRMask(prefix, 32)
	network := v4.Mask(mask)
	return network.String() + "/" + strconv.Itoa(prefix)
}

func uniqueNonEmpty(in []string) []string {
	seen := map[string]bool{}
	var out []string
	for _, v := range in {
		v = strings.TrimSpace(v)
		if v == "" || seen[v] {
			continue
		}
		seen[v] = true
		out = append(out, v)
	}
	return out
}

func containsInt(list []int64, v int64) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}


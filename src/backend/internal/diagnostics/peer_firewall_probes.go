package diagnostics

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/models"
)

func (s *Service) appendPeerFirewallChecks(ctx context.Context, locale string, configs []models.AwgConfig, checks *[]map[string]any, hints *[]string) {
	if !s.Stats.IsContainerRunning(ctx, "") {
		return
	}
	for _, cfg := range configs {
		if cfg.Type != "server" || !cfg.Enabled {
			continue
		}
		peers, err := s.Peers.ListEnabledByConfig(ctx, cfg.ID)
		if err != nil {
			continue
		}
		var restricted []models.AwgConfigPeer
		for _, p := range peers {
			if awg.IsRestrictedForwardPolicy(p.ForwardPolicy) && len(p.ForwardAllowedCIDRs) > 0 {
				restricted = append(restricted, p)
			}
		}
		if len(restricted) == 0 {
			continue
		}
		chain := awg.PeerFirewallChainName(cfg.Iface)
		hasChain := s.hasIPTablesChain(ctx, chain)
		detail := i18n.Tf(locale, "system.peer_firewall_active_detail", map[string]string{
			"count": fmt.Sprintf("%d", len(restricted)),
			"chain": chain,
		})
		if !hasChain {
			detail = i18n.Tf(locale, "system.peer_firewall_chain_missing_detail", map[string]string{"chain": chain})
			*hints = append(*hints, i18n.Tf(locale, "system.peer_firewall_chain_missing_hint", map[string]string{"name": cfg.Name}))
		}
		*checks = append(*checks, map[string]any{
			"id":     "peer_firewall_" + cfg.Iface,
			"ok":     hasChain,
			"label":  cfg.Name + " · " + i18n.T(locale, "system.peer_firewall_label"),
			"detail": detail,
		})
	}
}

func (s *Service) hasIPTablesChain(ctx context.Context, chain string) bool {
	if chain == "" || !strings.HasPrefix(chain, "AWGGUI-FWD-") {
		return false
	}
	iface := strings.TrimPrefix(chain, "AWGGUI-FWD-")
	if !diagIfaceRE.MatchString(iface) {
		return false
	}
	script := `iptables -L ` + chain + ` -n >/dev/null 2>&1 && echo yes || echo no`
	r := s.Docker.Exec(ctx, s.Stats.ContainerName(), []string{"sh", "-c", script}, 5*time.Second, "")
	return strings.TrimSpace(r.Stdout) == "yes"
}

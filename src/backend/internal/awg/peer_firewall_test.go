package awg

import (
	"context"
	"strings"
	"testing"

	"github.com/awggui/backend/internal/models"
)

func testPeerFirewallService() *Service {
	return &Service{
		enabledPeersCache:    map[int64][]models.AwgConfigPeer{},
		clientAllowedIPCache: map[string]string{},
	}
}

func TestPeerSourceIP(t *testing.T) {
	if got := PeerSourceIP("10.66.66.3/32"); got != "10.66.66.3" {
		t.Fatalf("PeerSourceIP = %q", got)
	}
}

func TestNormalizeForwardPolicy(t *testing.T) {
	if got := NormalizeForwardPolicy("restricted"); got != "restricted" {
		t.Fatalf("restricted = %q", got)
	}
	if got := NormalizeForwardPolicy(""); got != "allow_all" {
		t.Fatalf("empty = %q", got)
	}
}

func TestPeerFirewallPostUpPartsAllowAll(t *testing.T) {
	s := testPeerFirewallService()
	cfg := &models.AwgConfig{ID: 1, Type: "server", Iface: "awg0"}
	s.enabledPeersCache[1] = []models.AwgConfigPeer{{
		Address: "10.66.66.2/32", ForwardPolicy: "allow_all",
	}}
	parts := s.peerFirewallPostUpParts(context.Background(), cfg)
	if len(parts) != 0 {
		t.Fatalf("expected no firewall rules for allow_all, got %v", parts)
	}
}

func TestPeerFirewallPostUpPartsRestricted(t *testing.T) {
	s := testPeerFirewallService()
	cfg := &models.AwgConfig{ID: 1, Type: "server", Iface: "awg0"}
	s.enabledPeersCache[1] = []models.AwgConfigPeer{{
		Address:             "10.66.66.3/32",
		ForwardPolicy:       "restricted",
		ForwardAllowedCIDRs: []string{"192.168.1.13/32"},
		Enabled:             true,
	}}
	parts := s.peerFirewallPostUpParts(context.Background(), cfg)
	joined := strings.Join(parts, "; ")
	for _, want := range []string{
		"iptables -N AWGGUI-FWD-%i",
		"-s 10.66.66.3 -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT",
		"-s 10.66.66.3 -d 192.168.1.13/32 -j ACCEPT",
		"-s 10.66.66.3 -j DROP",
		"iptables -A FORWARD -i %i -j AWGGUI-FWD-%i",
	} {
		if !strings.Contains(joined, want) {
			t.Fatalf("missing %q in %q", want, joined)
		}
	}
}

func TestPeerFirewallChainName(t *testing.T) {
	if got := PeerFirewallChainName("awg0"); got != "AWGGUI-FWD-awg0" {
		t.Fatalf("chain = %q", got)
	}
}

func TestBuildPeerFirewallApplyScriptClearsChain(t *testing.T) {
	s := testPeerFirewallService()
	cfg := &models.AwgConfig{ID: 1, Type: "server", Iface: "awg0"}
	s.enabledPeersCache[1] = []models.AwgConfigPeer{}
	script := s.buildPeerFirewallApplyScript(context.Background(), cfg, "awg0")
	if !strings.Contains(script, "iptables -X AWGGUI-FWD-awg0") {
		t.Fatalf("expected chain cleanup in %q", script)
	}
}

func TestNormalizeForwardPolicyRejectsUnknown(t *testing.T) {
	if got := NormalizeForwardPolicy("restricted"); got != "restricted" {
		t.Fatalf("restricted = %q", got)
	}
	if got := NormalizeForwardPolicy("bogus"); got != "allow_all" {
		t.Fatalf("bogus should map to allow_all, got %q", got)
	}
}

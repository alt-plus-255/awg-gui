package awg

import (
	"context"
	"strings"
	"testing"

	"github.com/awggui/backend/internal/models"
)

func testAllowedIPsService() *Service {
	return &Service{
		enabledPeersCache:    map[int64][]models.AwgConfigPeer{},
		clientAllowedIPCache: map[string]string{},
	}
}

func TestClientAllowedIPsSplitTunnelMultipleCIDRs(t *testing.T) {
	s := testAllowedIPsService()
	cfg := &models.AwgConfig{
		ID:             1,
		Type:           "server",
		InternalSubnet: "10.66.66.1/24",
	}
	membership := &models.AwgConfigPeer{
		ID: 10,
		ExtraAllowedIPs: []string{
			"192.168.1.13/32",
			"77.88.8.8/32",
			"77.88.8.1/32",
		},
	}

	got := s.ClientAllowedIPs(context.Background(), cfg, membership)
	want := []string{
		"10.66.66.0/24",
		"192.168.1.13/32",
		"77.88.8.8/32",
		"77.88.8.1/32",
	}
	if len(got) != len(want) {
		t.Fatalf("got %d CIDRs %v, want %d %v", len(got), got, len(want), want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("CIDR[%d] = %q, want %q (full: %v)", i, got[i], want[i], got)
		}
	}
}

func TestClientAllowedIPsStringCacheInvalidation(t *testing.T) {
	s := testAllowedIPsService()
	ctx := context.Background()
	cfg := &models.AwgConfig{
		ID:             1,
		Type:           "server",
		InternalSubnet: "10.66.66.1/24",
	}
	membership := &models.AwgConfigPeer{
		ID:              10,
		ExtraAllowedIPs: []string{"192.168.1.13/32"},
	}

	first := s.ClientAllowedIPsString(ctx, cfg, membership)
	if !strings.Contains(first, "192.168.1.13/32") {
		t.Fatalf("first = %q", first)
	}
	if strings.Contains(first, "77.88.8.8/32") {
		t.Fatalf("first should not contain new CIDR yet: %q", first)
	}

	membership.ExtraAllowedIPs = append(membership.ExtraAllowedIPs, "77.88.8.8/32", "77.88.8.1/32")
	stale := s.ClientAllowedIPsString(ctx, cfg, membership)
	if stale != first {
		t.Fatalf("expected stale cache hit %q, got %q", first, stale)
	}

	s.InvalidateAllowedIPCache(cfg.ID, membership.ID)
	fresh := s.ClientAllowedIPsString(ctx, cfg, membership)
	if fresh == first {
		t.Fatalf("expected refreshed AllowedIPs after invalidation, still %q", fresh)
	}
	for _, cidr := range []string{"10.66.66.0/24", "192.168.1.13/32", "77.88.8.8/32", "77.88.8.1/32"} {
		if !strings.Contains(fresh, cidr) {
			t.Fatalf("fresh = %q, missing %q", fresh, cidr)
		}
	}
}

func TestInvalidateConfigPeerCachesClearsAllMembershipEntries(t *testing.T) {
	s := testAllowedIPsService()
	s.clientAllowedIPCache["1:10"] = "10.66.66.0/24, 192.168.1.13/32"
	s.clientAllowedIPCache["1:11"] = "10.66.66.0/24, 77.88.8.8/32"
	s.clientAllowedIPCache["2:20"] = "10.0.0.0/24"
	s.enabledPeersCache[1] = []models.AwgConfigPeer{{ID: 10}}

	s.InvalidateConfigPeerCaches(1)

	if len(s.clientAllowedIPCache) != 1 {
		t.Fatalf("clientAllowedIPCache = %v, want only config 2 entry", s.clientAllowedIPCache)
	}
	if _, ok := s.clientAllowedIPCache["2:20"]; !ok {
		t.Fatalf("config 2 cache entry should remain: %v", s.clientAllowedIPCache)
	}
	if _, ok := s.enabledPeersCache[1]; ok {
		t.Fatal("enabledPeersCache[1] should be cleared")
	}
}

func TestClientAllowedIPsVirtualNetworkAggregatesPeerRoutes(t *testing.T) {
	s := testAllowedIPsService()
	cfg := &models.AwgConfig{
		ID:       5,
		Type:     "virtual_network",
		VnPolicy: "allow_all",
	}
	self := &models.AwgConfigPeer{
		ID:          100,
		VpnClientID: 1,
		Address:     "10.66.66.2/32",
	}
	other := models.AwgConfigPeer{
		ID:              101,
		VpnClientID:     2,
		Address:         "10.66.66.3/32",
		ExtraAllowedIPs: []string{"192.168.1.0/24", "77.88.8.8/32"},
	}
	s.enabledPeersCache[cfg.ID] = []models.AwgConfigPeer{*self, other}

	got := s.ClientAllowedIPs(context.Background(), cfg, self)
	want := []string{"10.66.66.2/32", "192.168.1.0/24", "77.88.8.8/32"}
	if len(got) != len(want) {
		t.Fatalf("got %v, want %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("CIDR[%d] = %q, want %q (full: %v)", i, got[i], want[i], got)
		}
	}
}

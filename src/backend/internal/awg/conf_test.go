package awg

import (
	"testing"

	"github.com/awggui/backend/internal/models"
)

func TestPeerClientConfigOmitsDNS(t *testing.T) {
	s := testAllowedIPsService()
	cfg := &models.AwgConfig{Type: "server", PeerDNS: "1.1.1.1"}

	if s.PeerClientConfigOmitsDNS(cfg, &models.AwgConfigPeer{SplitTunnel: true}) {
		// split tunnel on server config omits DNS
	} else {
		t.Fatal("split tunnel peer should omit DNS")
	}
	if s.PeerClientConfigOmitsDNS(cfg, &models.AwgConfigPeer{SplitTunnel: false}) {
		t.Fatal("full tunnel peer should include DNS")
	}

	resolverCfg := &models.AwgConfig{Type: "server", ResolverEnabled: true}
	if s.PeerClientConfigOmitsDNS(resolverCfg, &models.AwgConfigPeer{SplitTunnel: true}) {
		t.Fatal("resolver config should always include DNS")
	}
}

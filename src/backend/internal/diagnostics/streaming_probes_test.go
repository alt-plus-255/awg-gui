package diagnostics

import "testing"

func TestParseClashConnectionsUDP(t *testing.T) {
	body := map[string]any{
		"connections": []any{
			map[string]any{
				"metadata": map[string]any{"network": "udp"},
				"chains":   []any{"conn_1", "direct"},
			},
			map[string]any{
				"metadata": map[string]any{"network": "tcp"},
				"chains":   []any{"conn_1_2", "conn_1"},
			},
			map[string]any{
				"metadata": map[string]any{"network": "udp"},
				"chains":   []any{"conn_2"},
			},
		},
	}
	stats := parseClashConnections(body)
	if stats.UDPByTag["conn_1"] != 1 {
		t.Fatalf("conn_1 udp=%d want 1", stats.UDPByTag["conn_1"])
	}
	if stats.TCPByTag["conn_1"] != 1 {
		t.Fatalf("conn_1 tcp=%d want 1", stats.TCPByTag["conn_1"])
	}
	if stats.UDPByTag["conn_2"] != 1 {
		t.Fatalf("conn_2 udp=%d want 1", stats.UDPByTag["conn_2"])
	}
}

func TestRollupConnTag(t *testing.T) {
	tag := rollupConnTag(map[string]any{
		"chains": []any{"conn_3_7", "conn_3"},
	})
	if tag != "conn_3" {
		t.Fatalf("rollup=%q want conn_3", tag)
	}
}

func TestParseIptablesPkts(t *testing.T) {
	out := `120 nat
45 udp
10 nat`
	nat, udp := parseIptablesPkts(out)
	if nat != 130 || udp != 45 {
		t.Fatalf("nat=%d udp=%d want 130/45", nat, udp)
	}
}

func TestStreamingRTTOK(t *testing.T) {
	cases := []struct {
		ms   int
		want bool
	}{
		{0, false},
		{199, true},
		{200, false},
		{591, false},
	}
	for _, c := range cases {
		if got := streamingRTTOK(c.ms); got != c.want {
			t.Fatalf("streamingRTTOK(%d)=%v want %v", c.ms, got, c.want)
		}
	}
}

func TestStreamingRTTThreshold(t *testing.T) {
	if streamingRTTThresholdMS != 200 {
		t.Fatalf("threshold=%d want 200", streamingRTTThresholdMS)
	}
}

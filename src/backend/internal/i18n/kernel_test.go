package i18n

import "testing"

func TestLocalizeKernelMessage(t *testing.T) {
	got := LocalizeKernelMessage("ru", "Recovering AmneziaWG kernel datapath...")
	if got == "Recovering AmneziaWG kernel datapath..." {
		t.Fatalf("expected Russian translation, got %q", got)
	}
	if LocalizeKernelMessage("en", "panel-ops unavailable") != T("en", "settings.panel_ops_unavailable") {
		t.Fatal("panel-ops unavailable not localized")
	}
	if LocalizeKernelMessage("ru", "unknown message") != "unknown message" {
		t.Fatal("unknown message should pass through")
	}
}

package i18n

import "testing"

func TestTFallbackAndLocale(t *testing.T) {
	en := T("en", "auth.invalid_credentials")
	ru := T("ru", "auth.invalid_credentials")
	if en == "" || ru == "" || en == ru {
		t.Fatalf("en/ru auth.invalid_credentials should differ: en=%q ru=%q", en, ru)
	}
	if got := T("fr", "auth.invalid_credentials"); got != en {
		t.Fatalf("unknown locale should fall back to en, got %q", got)
	}
	if got := T("en", "does.not.exist"); got != "does.not.exist" {
		t.Fatalf("missing key should return key, got %q", got)
	}
}

func TestTfSubstitutesVars(t *testing.T) {
	got := Tf("en", "configs.invalid_cidr", map[string]string{"cidr": "10.0.0.0/8"})
	if got != "Invalid CIDR: 10.0.0.0/8" {
		t.Fatalf("Tf = %q", got)
	}
}

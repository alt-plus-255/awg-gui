package awg

import (
	"reflect"
	"testing"
)

func TestVersionRegistryJunkProfiles(t *testing.T) {
	r := NewVersionRegistry()
	wantIDs := []string{"1.0", "1.5", "2.0"}
	if got := r.IDs(); !reflect.DeepEqual(got, wantIDs) {
		t.Fatalf("IDs = %v, want %v", got, wantIDs)
	}
	if r.Latest() != "2.0" {
		t.Fatalf("Latest = %q, want 2.0", r.Latest())
	}

	base := []string{"jc", "jmin", "jmax", "s1", "s2", "h1", "h2", "h3", "h4"}
	cases := []struct {
		id     string
		label  string
		vpnVer string
		params []string
	}{
		{"1.0", "AmneziaWG 1.0", "1", base},
		{"1.5", "AmneziaWG 1.5", "1", append(append([]string{}, base...), "i1", "i2", "i3", "i4", "i5")},
		{"2.0", "AmneziaWG 2.0", "2", append(append([]string{}, base...), "s3", "s4", "i1", "i2", "i3", "i4", "i5")},
	}
	for _, tc := range cases {
		p := r.ProfileForConfig(tc.id)
		if p.ID() != tc.id || p.Label() != tc.label {
			t.Fatalf("%s: ID/Label = %q %q", tc.id, p.ID(), p.Label())
		}
		if p.VpnURIProtocolVersion() != tc.vpnVer {
			t.Fatalf("%s: VpnURIProtocolVersion = %q, want %q", tc.id, p.VpnURIProtocolVersion(), tc.vpnVer)
		}
		if !reflect.DeepEqual(p.SupportedParams(), tc.params) {
			t.Fatalf("%s: SupportedParams = %v, want %v", tc.id, p.SupportedParams(), tc.params)
		}
	}
}

func TestNormalizeForPersistDropsUnsupportedJunk(t *testing.T) {
	r := NewVersionRegistry()
	full := map[string]string{
		"jc": "7", "jmin": "100", "jmax": "200",
		"s1": "10", "s2": "20", "s3": "30", "s4": "15",
		"h1": "1", "h2": "2", "h3": "3", "h4": "4",
		"i1": "<b 10><t>", "i2": "x", "i3": "y", "i4": "z", "i5": "w",
	}

	p10 := r.ProfileForConfig("1.0").NormalizeForPersist(full)
	if p10["s3"] != "0" || p10["s4"] != "0" {
		t.Fatalf("1.0 must zero s3/s4, got s3=%q s4=%q", p10["s3"], p10["s4"])
	}
	for _, k := range []string{"i1", "i2", "i3", "i4", "i5"} {
		if p10[k] != "" {
			t.Fatalf("1.0 must clear %s, got %q", k, p10[k])
		}
	}
	if p10["jc"] != "7" || p10["s1"] != "10" {
		t.Fatalf("1.0 must keep base junk, got jc=%q s1=%q", p10["jc"], p10["s1"])
	}

	p15 := r.ProfileForConfig("1.5").NormalizeForPersist(full)
	if p15["s3"] != "0" || p15["s4"] != "0" {
		t.Fatalf("1.5 must zero s3/s4, got s3=%q s4=%q", p15["s3"], p15["s4"])
	}
	if p15["i1"] != "<b 10><t>" {
		t.Fatalf("1.5 must keep i-params, got i1=%q", p15["i1"])
	}

	p20 := r.ProfileForConfig("2.0").NormalizeForPersist(full)
	if p20["s3"] != "30" || p20["s4"] != "15" || p20["i1"] != "<b 10><t>" {
		t.Fatalf("2.0 must keep s3/s4/i1, got %+v", p20)
	}
}

func TestConfObfuscationLinesMatchProfile(t *testing.T) {
	r := NewVersionRegistry()
	params := map[string]string{
		"jc": "4", "jmin": "64", "jmax": "80",
		"s1": "0", "s2": "0", "s3": "1", "s4": "2",
		"h1": "1", "h2": "2", "h3": "3", "h4": "4",
		"i1": "<b 10><t>",
	}
	p10 := r.ProfileForConfig("1.0").ConfObfuscationLinesFromParams(params)
	for _, line := range p10 {
		if len(line) >= 2 && (line[:2] == "S3" || line[:2] == "S4" || line[:2] == "I1") {
			t.Fatalf("1.0 conf must not emit s3/s4/i1, got %v", p10)
		}
	}
	p20 := r.ProfileForConfig("2.0").ConfObfuscationLinesFromParams(params)
	joined := ""
	for _, line := range p20 {
		joined += line + "\n"
	}
	for _, need := range []string{"S3 = 1", "S4 = 2", "I1 = <b 10><t>"} {
		if !containsString(joined, need) {
			t.Fatalf("2.0 conf missing %q in %v", need, p20)
		}
	}
}

func containsString(s, sub string) bool {
	return len(s) >= len(sub) && (s == sub || len(sub) == 0 ||
		(len(s) > 0 && (indexOf(s, sub) >= 0)))
}

func indexOf(s, sub string) int {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return i
		}
	}
	return -1
}

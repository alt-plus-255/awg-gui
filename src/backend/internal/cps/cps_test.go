package cps

import (
	"strings"
	"testing"
)

func TestParseAndLengthQUICLike(t *testing.T) {
	cps := "<b 0xc0ff00000001><rc 8><t><r 20>"
	tags, err := ParseTags(cps)
	if err != nil {
		t.Fatal(err)
	}
	if len(tags) != 4 {
		t.Fatalf("tags=%d want 4", len(tags))
	}
	n, err := CalculateLength(cps)
	if err != nil {
		t.Fatal(err)
	}
	// 6 bytes + 8 + 4 + 20 = 38
	if n != 38 {
		t.Fatalf("length=%d want 38", n)
	}
}

func TestRejectLegacyC(t *testing.T) {
	fr := ValidateSyntax("<c>", false)
	if fr.OK {
		t.Fatal("expected <c> rejected")
	}
}

func TestRejectDuplicateT(t *testing.T) {
	fr := ValidateSyntax("<t><r 4><t>", true)
	if fr.OK {
		t.Fatal("expected duplicate <t> rejected")
	}
}

func TestRejectInvalidHex(t *testing.T) {
	fr := ValidateSyntax("<b 0xabc>", true)
	if fr.OK {
		t.Fatal("expected odd hex rejected")
	}
}

func TestForbiddenCollision(t *testing.T) {
	// Build a CPS of exact length 148 (S1=0 forbidden)
	// <r 148> length = 148
	c := Constraints{S1: 0, S2: 0, S3: 0, S4: 0, MTU: 1420, AllowD: true}
	fr := ValidateField("<r 148>", c)
	if fr.OK {
		t.Fatal("expected collision with 148+S1")
	}
}

func TestGenerateAllProtocols(t *testing.T) {
	c := ConstraintsFromStrings("10", "20", "5", "4", DefaultMTU, true)
	for _, p := range TemplatesCatalog() {
		gen := Generate(GenerateOpts{Protocol: p.ID, Constraints: c})
		if gen.I1 == "" {
			t.Fatalf("%s: empty I1", p.ID)
		}
		for _, field := range []string{gen.I1, gen.I2, gen.I3, gen.I4, gen.I5} {
			if field == "" {
				continue
			}
			fr := ValidateField(field, c)
			if !fr.OK {
				t.Fatalf("%s: invalid %q errors=%v", p.ID, field, fr.Errors)
			}
			if strings.Contains(field, "<c>") {
				t.Fatalf("%s: must not emit <c>", p.ID)
			}
		}
	}
}

func TestValidateAllEmptyOK(t *testing.T) {
	c := ConstraintsFromStrings("0", "0", "0", "0", 0, false)
	res := ValidateAll(map[string]string{}, c)
	if !res.Valid {
		t.Fatal("empty fields should be valid")
	}
}

func TestDWarningWhenAllowed(t *testing.T) {
	fr := ValidateSyntax("<b 0xc0ff><d><r 4>", true)
	if !fr.OK {
		t.Fatalf("expected OK, got %v", fr.Errors)
	}
	if len(fr.Warnings) == 0 {
		t.Fatal("expected kernel warning for <d>")
	}
}

func TestMaxISize(t *testing.T) {
	n := MaxISize(1420, 10)
	want := 1420 - reserveBytes - handshakeReserve - 10
	if n != want {
		t.Fatalf("MaxISize=%d want %d", n, want)
	}
}

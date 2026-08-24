package awg

import (
	"encoding/base64"
	"testing"

	"golang.org/x/crypto/curve25519"
)

func TestGenerateKeyPairViaCurve25519(t *testing.T) {
	kp, err := generateKeyPairViaCurve25519()
	if err != nil {
		t.Fatal(err)
	}
	priv, err := base64.StdEncoding.DecodeString(kp.Private)
	if err != nil || len(priv) != 32 {
		t.Fatalf("private key: %v len=%d", err, len(priv))
	}
	pub, err := base64.StdEncoding.DecodeString(kp.Public)
	if err != nil || len(pub) != 32 {
		t.Fatalf("public key: %v len=%d", err, len(pub))
	}
	want, err := curve25519.X25519(priv, curve25519.Basepoint)
	if err != nil {
		t.Fatal(err)
	}
	if string(want) != string(pub) {
		t.Fatal("public key does not match Curve25519(private, basepoint)")
	}
}

func TestGeneratePresharedKey(t *testing.T) {
	k, err := GeneratePresharedKey()
	if err != nil {
		t.Fatal(err)
	}
	raw, err := base64.StdEncoding.DecodeString(k)
	if err != nil || len(raw) != 32 {
		t.Fatalf("preshared key: %v len=%d", err, len(raw))
	}
}

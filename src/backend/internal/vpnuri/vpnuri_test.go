package vpnuri

import (
	"bytes"
	"compress/zlib"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"testing"
)

func TestDecodeRejectsInvalidURI(t *testing.T) {
	s := New(nil, nil)
	cases := []string{"", "vpn://", "not-a-uri", "vpn://!!!!"}
	for _, in := range cases {
		if _, err := s.Decode(in); err != ErrInvalidURI {
			t.Fatalf("Decode(%q) err = %v, want ErrInvalidURI", in, err)
		}
	}
}

func TestDecodeRoundTripOuterJSON(t *testing.T) {
	s := New(nil, nil)
	outer := map[string]any{
		"description":      "peer-1",
		"defaultContainer": "amnezia-awg",
		"dns1":             "1.1.1.1",
		"hostName":         "vpn.example",
	}
	raw, err := json.Marshal(outer)
	if err != nil {
		t.Fatal(err)
	}
	var buf bytes.Buffer
	w := zlib.NewWriter(&buf)
	if _, err := w.Write(raw); err != nil {
		t.Fatal(err)
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	payload := make([]byte, 4+buf.Len())
	binary.BigEndian.PutUint32(payload[0:4], uint32(len(raw)))
	copy(payload[4:], buf.Bytes())
	uri := "vpn://" + base64.RawURLEncoding.EncodeToString(payload)

	got, err := s.Decode(uri)
	if err != nil {
		t.Fatal(err)
	}
	if got["description"] != "peer-1" || got["hostName"] != "vpn.example" {
		t.Fatalf("decoded %+v", got)
	}
}

func TestParseEndpointHost(t *testing.T) {
	cases := map[string]string{
		"vpn.example:51820":     "vpn.example",
		"1.2.3.4:443":           "1.2.3.4",
		"[2001:db8::1]:51820":   "2001:db8::1",
		"":                      "",
	}
	for in, want := range cases {
		if got := parseEndpointHost(in); got != want {
			t.Fatalf("parseEndpointHost(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestMatchConf(t *testing.T) {
	conf := "[Interface]\nPrivateKey = abc\nAddress = 10.0.0.2/32\n\n[Peer]\nAllowedIPs = 0.0.0.0/0\n"
	if got := matchConf(conf, "PrivateKey"); got != "abc" {
		t.Fatalf("PrivateKey = %q", got)
	}
	if got := matchConf(conf, "AllowedIPs"); got != "0.0.0.0/0" {
		t.Fatalf("AllowedIPs = %q", got)
	}
	if got := matchConf(conf, "Missing"); got != "" {
		t.Fatalf("Missing = %q", got)
	}
}

func TestBuildAmneziaQRPackFromOuterJSON(t *testing.T) {
	s := New(nil, nil)
	pack, err := s.BuildAmneziaQRPackFromOuterJSON(`{"description":"x"}`)
	if err != nil {
		t.Fatal(err)
	}
	raw, err := base64.RawURLEncoding.DecodeString(pack)
	if err != nil {
		t.Fatal(err)
	}
	if len(raw) < 12 {
		t.Fatalf("pack too short: %d", len(raw))
	}
	magic := binary.BigEndian.Uint32(raw[0:4])
	if magic != amneziaQRMagic {
		t.Fatalf("magic = 0x%X, want 0x%X", magic, amneziaQRMagic)
	}
}

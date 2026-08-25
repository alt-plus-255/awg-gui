package cps

// TagSpec describes a template tag before rendering.
type TagSpec struct {
	Type  string // bytes, random, random_chars, random_digits, timestamp
	Value string
}

// I1I5Template holds five intervals.
type I1I5Template struct {
	I1, I2, I3, I4, I5 []TagSpec
}

// TemplateInfo is exposed via API.
type TemplateInfo struct {
	ID          string `json:"id"`
	Label       string `json:"label"`
	Description string `json:"description"`
	MinVersion  string `json:"min_version"`
}

// Protocol IDs.
const (
	ProtocolQUIC   = "quic"
	ProtocolDNS    = "dns"
	ProtocolSTUN   = "stun"
	ProtocolSIP    = "sip"
	ProtocolDTLS   = "dtls"
	ProtocolRTP    = "rtp"
	ProtocolRandom = "random"
)

// TemplatesCatalog returns selectable CPS templates.
func TemplatesCatalog() []TemplateInfo {
	return []TemplateInfo{
		{ID: ProtocolQUIC, Label: "QUIC", Description: "QUIC Initial long-header mimic (default)", MinVersion: "1.5"},
		{ID: ProtocolDNS, Label: "DNS", Description: "DNS-like UDP query/response pattern", MinVersion: "1.5"},
		{ID: ProtocolSTUN, Label: "STUN", Description: "STUN binding request magic cookie", MinVersion: "1.5"},
		{ID: ProtocolSIP, Label: "SIP", Description: "SIP-like text protocol entropy", MinVersion: "1.5"},
		{ID: ProtocolDTLS, Label: "DTLS", Description: "DTLS record header mimic", MinVersion: "1.5"},
		{ID: ProtocolRTP, Label: "RTP", Description: "RTP-like size distribution", MinVersion: "1.5"},
		{ID: ProtocolRandom, Label: "Random", Description: "Mixed CPS tags without fixed protocol header", MinVersion: "1.5"},
	}
}

// DefaultProtocol is used when none specified.
func DefaultProtocol() string { return ProtocolQUIC }

// HasProtocol reports whether id is a known template.
func HasProtocol(id string) bool {
	for _, t := range TemplatesCatalog() {
		if t.ID == id {
			return true
		}
	}
	return false
}

func getTemplate(protocol string) I1I5Template {
	switch protocol {
	case ProtocolDNS:
		return dnsTemplate()
	case ProtocolSTUN:
		return stunTemplate()
	case ProtocolSIP:
		return sipTemplate()
	case ProtocolDTLS:
		return dtlsTemplate()
	case ProtocolRTP:
		return rtpTemplate()
	case ProtocolQUIC:
		return quicTemplate()
	default:
		return quicTemplate()
	}
}

func quicTemplate() I1I5Template {
	return I1I5Template{
		I1: []TagSpec{
			{Type: "bytes", Value: "c0ff"},
			{Type: "bytes", Value: "00000001"},
			{Type: "bytes", Value: "08"},
			{Type: "random", Value: "8"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "0040"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "01"},
			{Type: "timestamp"},
			{Type: "random", Value: "40"},
		},
		I2: []TagSpec{
			{Type: "bytes", Value: "c0ff"},
			{Type: "bytes", Value: "00000001"},
			{Type: "bytes", Value: "08"},
			{Type: "random", Value: "8"}, // use random instead of <d> for kernel compatibility
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "0020"},
			{Type: "bytes", Value: "01"},
			{Type: "timestamp"},
			{Type: "random", Value: "20"},
		},
		I3: []TagSpec{
			{Type: "bytes", Value: "c0ff"},
			{Type: "bytes", Value: "00000001"},
			{Type: "bytes", Value: "08"},
			{Type: "random", Value: "8"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "0010"},
			{Type: "bytes", Value: "01"},
			{Type: "timestamp"},
			{Type: "random", Value: "10"},
		},
		I4: []TagSpec{
			{Type: "bytes", Value: "c0ff"},
			{Type: "bytes", Value: "00000001"},
			{Type: "bytes", Value: "08"},
			{Type: "random", Value: "8"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "0005"},
			{Type: "bytes", Value: "01"},
			{Type: "timestamp"},
			{Type: "random", Value: "5"},
		},
		I5: []TagSpec{
			{Type: "bytes", Value: "c0ff"},
			{Type: "bytes", Value: "00000001"},
			{Type: "timestamp"},
			{Type: "random", Value: "8"},
		},
	}
}

func dnsTemplate() I1I5Template {
	return I1I5Template{
		I1: []TagSpec{
			{Type: "random", Value: "2"}, // TXID
			{Type: "bytes", Value: "0100"},
			{Type: "bytes", Value: "0001"},
			{Type: "bytes", Value: "0000"},
			{Type: "bytes", Value: "0000"},
			{Type: "bytes", Value: "0000"},
			{Type: "random_digits", Value: "3"},
			{Type: "bytes", Value: "03"},
			{Type: "random_chars", Value: "3"},
			{Type: "bytes", Value: "00"},
			{Type: "bytes", Value: "0001"},
			{Type: "bytes", Value: "0001"},
			{Type: "timestamp"},
			{Type: "random", Value: "16"},
		},
		I2: []TagSpec{
			{Type: "random", Value: "2"},
			{Type: "bytes", Value: "8180"},
			{Type: "bytes", Value: "0001"},
			{Type: "bytes", Value: "0001"},
			{Type: "timestamp"},
			{Type: "random", Value: "24"},
		},
		I3: []TagSpec{
			{Type: "random", Value: "2"},
			{Type: "bytes", Value: "0100"},
			{Type: "timestamp"},
			{Type: "random", Value: "12"},
		},
		I4: []TagSpec{
			{Type: "bytes", Value: "0000"},
			{Type: "timestamp"},
			{Type: "random", Value: "8"},
		},
		I5: []TagSpec{
			{Type: "timestamp"},
			{Type: "random", Value: "6"},
		},
	}
}

func stunTemplate() I1I5Template {
	// STUN magic cookie 0x2112A442
	return I1I5Template{
		I1: []TagSpec{
			{Type: "bytes", Value: "0001"},
			{Type: "bytes", Value: "0000"},
			{Type: "bytes", Value: "2112a442"},
			{Type: "random", Value: "12"},
			{Type: "timestamp"},
			{Type: "random", Value: "20"},
		},
		I2: []TagSpec{
			{Type: "bytes", Value: "0101"},
			{Type: "bytes", Value: "0000"},
			{Type: "bytes", Value: "2112a442"},
			{Type: "random", Value: "12"},
			{Type: "timestamp"},
			{Type: "random", Value: "12"},
		},
		I3: []TagSpec{
			{Type: "bytes", Value: "0001"},
			{Type: "bytes", Value: "2112a442"},
			{Type: "timestamp"},
			{Type: "random", Value: "8"},
		},
		I4: []TagSpec{
			{Type: "bytes", Value: "2112a442"},
			{Type: "timestamp"},
			{Type: "random", Value: "6"},
		},
		I5: []TagSpec{
			{Type: "timestamp"},
			{Type: "random", Value: "4"},
		},
	}
}

func sipTemplate() I1I5Template {
	return I1I5Template{
		I1: []TagSpec{
			{Type: "random_chars", Value: "7"}, // OPTIONS /
			{Type: "bytes", Value: "20"},
			{Type: "random_chars", Value: "12"},
			{Type: "bytes", Value: "0d0a"},
			{Type: "timestamp"},
			{Type: "random_digits", Value: "8"},
			{Type: "random", Value: "24"},
		},
		I2: []TagSpec{
			{Type: "random_chars", Value: "3"},
			{Type: "bytes", Value: "20"},
			{Type: "random_digits", Value: "3"},
			{Type: "timestamp"},
			{Type: "random", Value: "16"},
		},
		I3: []TagSpec{
			{Type: "random_chars", Value: "8"},
			{Type: "timestamp"},
			{Type: "random", Value: "10"},
		},
		I4: []TagSpec{
			{Type: "random_digits", Value: "6"},
			{Type: "timestamp"},
			{Type: "random", Value: "6"},
		},
		I5: []TagSpec{
			{Type: "timestamp"},
			{Type: "random_chars", Value: "4"},
		},
	}
}

func dtlsTemplate() I1I5Template {
	return I1I5Template{
		I1: []TagSpec{
			{Type: "bytes", Value: "16"}, // handshake
			{Type: "bytes", Value: "fefd"}, // DTLS 1.2
			{Type: "bytes", Value: "0000"},
			{Type: "bytes", Value: "0000"},
			{Type: "bytes", Value: "0000"},
			{Type: "bytes", Value: "0000"},
			{Type: "random", Value: "2"},
			{Type: "timestamp"},
			{Type: "random", Value: "32"},
		},
		I2: []TagSpec{
			{Type: "bytes", Value: "16"},
			{Type: "bytes", Value: "fefd"},
			{Type: "timestamp"},
			{Type: "random", Value: "20"},
		},
		I3: []TagSpec{
			{Type: "bytes", Value: "14"}, // change_cipher_spec
			{Type: "bytes", Value: "fefd"},
			{Type: "timestamp"},
			{Type: "random", Value: "8"},
		},
		I4: []TagSpec{
			{Type: "bytes", Value: "17"}, // application_data
			{Type: "bytes", Value: "fefd"},
			{Type: "timestamp"},
			{Type: "random", Value: "6"},
		},
		I5: []TagSpec{
			{Type: "timestamp"},
			{Type: "random", Value: "4"},
		},
	}
}

func rtpTemplate() I1I5Template {
	return I1I5Template{
		I1: []TagSpec{
			{Type: "bytes", Value: "80"}, // V=2
			{Type: "bytes", Value: "60"}, // PT
			{Type: "random", Value: "2"}, // seq
			{Type: "random", Value: "4"}, // timestamp
			{Type: "random", Value: "4"}, // SSRC
			{Type: "timestamp"},
			{Type: "random", Value: "72"},
		},
		I2: []TagSpec{
			{Type: "bytes", Value: "80"},
			{Type: "bytes", Value: "60"},
			{Type: "random", Value: "2"},
			{Type: "random", Value: "4"},
			{Type: "random", Value: "4"},
			{Type: "timestamp"},
			{Type: "random", Value: "40"},
		},
		I3: []TagSpec{
			{Type: "bytes", Value: "80"},
			{Type: "random", Value: "2"},
			{Type: "timestamp"},
			{Type: "random", Value: "24"},
		},
		I4: []TagSpec{
			{Type: "bytes", Value: "80"},
			{Type: "timestamp"},
			{Type: "random", Value: "12"},
		},
		I5: []TagSpec{
			{Type: "timestamp"},
			{Type: "random", Value: "8"},
		},
	}
}

func mapTagType(typ string) string {
	switch typ {
	case "bytes":
		return "b"
	case "random":
		return "r"
	case "random_chars":
		return "rc"
	case "random_digits":
		return "rd"
	case "timestamp":
		return "t"
	case "data":
		return "d"
	default:
		return ""
	}
}

func buildCPSFromTemplate(specs []TagSpec) string {
	var parts []string
	for _, s := range specs {
		parts = append(parts, BuildTag(mapTagType(s.Type), s.Value))
	}
	return BuildCPS(parts)
}

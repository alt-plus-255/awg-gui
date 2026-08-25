package cps

import (
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

const (
	MaxTagLength     = 1000
	TimestampBytes   = 4
	DefaultMTU       = 1420
	reserveBytes     = 49 // IP + UDP (+1)
	handshakeReserve = 149
	maxByteLen       = 16
	minByteLen       = 4
	maxRandSize      = 40
	minRandSize      = 5
	minTagCount      = 3
	maxTagCount      = 6
)

var (
	tagRE = regexp.MustCompile(`<(rc|rd|b|r|t|d|c)(?:\s+([^>]*))?>`)
)

// Tag is one CPS element.
type Tag struct {
	Type  string // b, r, rc, rd, t, d
	Value string // hex for b, length for r/rc/rd, empty for t/d
	Raw   string
}

// Constraints for size / workability checks.
type Constraints struct {
	S1, S2, S3, S4 int
	MTU            int
	AllowD         bool // allow <d> passthrough (2.0+/3.1 userspace)
}

// FieldResult is validation output for one I-field.
type FieldResult struct {
	OK       bool     `json:"ok"`
	Length   int      `json:"length"`
	Errors   []string `json:"errors,omitempty"`
	Warnings []string `json:"warnings,omitempty"`
}

// ValidateResult covers I1-I5.
type ValidateResult struct {
	Valid  bool                    `json:"valid"`
	Fields map[string]FieldResult  `json:"fields"`
}

// Config holds generated I1-I5 strings.
type Config struct {
	I1, I2, I3, I4, I5 string
}

// ParseTags parses a CPS string into tags. Returns error on malformed syntax.
func ParseTags(cps string) ([]Tag, error) {
	cps = strings.TrimSpace(cps)
	if cps == "" {
		return nil, nil
	}
	var tags []Tag
	rest := cps
	for len(rest) > 0 {
		loc := tagRE.FindStringSubmatchIndex(rest)
		if loc == nil {
			if strings.TrimSpace(rest) != "" {
				return nil, fmt.Errorf("invalid CPS syntax near %q", truncate(rest, 40))
			}
			break
		}
		if loc[0] != 0 {
			gap := strings.TrimSpace(rest[:loc[0]])
			if gap != "" {
				return nil, fmt.Errorf("unexpected text before tag: %q", truncate(gap, 40))
			}
		}
		raw := rest[loc[0]:loc[1]]
		typ := rest[loc[2]:loc[3]]
		val := ""
		if loc[4] >= 0 {
			val = strings.TrimSpace(rest[loc[4]:loc[5]])
		}
		tags = append(tags, Tag{Type: typ, Value: val, Raw: raw})
		rest = rest[loc[1]:]
	}
	return tags, nil
}

// CalculateLength estimates expanded byte length of a CPS string.
func CalculateLength(cps string) (int, error) {
	tags, err := ParseTags(cps)
	if err != nil {
		return 0, err
	}
	total := 0
	for _, t := range tags {
		n, err := tagLength(t)
		if err != nil {
			return 0, err
		}
		total += n
	}
	return total, nil
}

func tagLength(t Tag) (int, error) {
	switch t.Type {
	case "b":
		hex := strings.TrimPrefix(strings.ToLower(t.Value), "0x")
		if hex == "" || len(hex)%2 != 0 {
			return 0, fmt.Errorf("invalid <b> hex length")
		}
		for _, c := range hex {
			if !((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f')) {
				return 0, fmt.Errorf("invalid <b> hex digit")
			}
		}
		return len(hex) / 2, nil
	case "r", "rc", "rd":
		n, err := strconv.Atoi(t.Value)
		if err != nil || n < 0 {
			return 0, fmt.Errorf("invalid <%s> length", t.Type)
		}
		if n > MaxTagLength {
			return 0, fmt.Errorf("<%s> length exceeds %d", t.Type, MaxTagLength)
		}
		return n, nil
	case "t":
		return TimestampBytes, nil
	case "d":
		return 0, nil
	case "c":
		return 0, fmt.Errorf("legacy <c> tag is not supported by amneziawg-go / Amnezia clients")
	default:
		return 0, fmt.Errorf("unknown tag <%s>", t.Type)
	}
}

// ForbiddenSizes returns handshake packet sizes that I-packets must avoid.
func ForbiddenSizes(c Constraints) []int {
	out := []int{148 + c.S1, 92 + c.S2}
	if c.S3 > 0 || true {
		out = append(out, 64+c.S3)
	}
	// S4 pads data packets; include payload+S4 lower bound as soft avoid when S4 set
	if c.S4 > 0 {
		out = append(out, c.S4) // avoid matching bare S4-sized junk
	}
	return out
}

// MaxISize returns max allowed I-packet size for MTU/S1.
func MaxISize(mtu, s1 int) int {
	if mtu <= 0 {
		mtu = DefaultMTU
	}
	n := mtu - reserveBytes - handshakeReserve - s1
	if n < TimestampBytes {
		return TimestampBytes
	}
	return n
}

// ValidateSyntax validates tags without size constraints.
func ValidateSyntax(cps string, allowD bool) FieldResult {
	res := FieldResult{OK: true}
	if strings.TrimSpace(cps) == "" {
		return res
	}
	tags, err := ParseTags(cps)
	if err != nil {
		res.OK = false
		res.Errors = append(res.Errors, err.Error())
		return res
	}
	seenT := false
	for _, t := range tags {
		switch t.Type {
		case "c":
			res.OK = false
			res.Errors = append(res.Errors, "legacy <c> tag is not supported")
		case "d":
			if !allowD {
				res.OK = false
				res.Errors = append(res.Errors, "<d> requires AmneziaWG 2.0+/3.1 userspace")
			} else {
				res.Warnings = append(res.Warnings, "<d> may be rejected by kernel AmneziaWG module")
			}
		case "t":
			if seenT {
				res.OK = false
				res.Errors = append(res.Errors, "duplicate <t> in one CPS packet")
			}
			seenT = true
			if t.Value != "" {
				res.OK = false
				res.Errors = append(res.Errors, "<t> takes no value")
			}
		case "b", "r", "rc", "rd":
			if _, err := tagLength(t); err != nil {
				res.OK = false
				res.Errors = append(res.Errors, err.Error())
			}
		default:
			res.OK = false
			res.Errors = append(res.Errors, fmt.Sprintf("unknown tag <%s>", t.Type))
		}
	}
	if n, err := CalculateLength(cps); err == nil {
		res.Length = n
	}
	return res
}

// ValidateField validates one CPS string against constraints.
func ValidateField(cps string, c Constraints) FieldResult {
	res := ValidateSyntax(cps, c.AllowD)
	if strings.TrimSpace(cps) == "" {
		return res
	}
	if !res.OK {
		return res
	}
	n := res.Length
	max := MaxISize(c.MTU, c.S1)
	if n >= max {
		res.OK = false
		res.Errors = append(res.Errors, fmt.Sprintf("packet length %d exceeds max %d (MTU/S1)", n, max))
	}
	for _, f := range ForbiddenSizes(c) {
		if f > 0 && n == f {
			res.OK = false
			res.Errors = append(res.Errors, fmt.Sprintf("packet length %d collides with handshake size", n))
			break
		}
	}
	return res
}

// ValidateAll validates I1-I5 map.
func ValidateAll(fields map[string]string, c Constraints) ValidateResult {
	out := ValidateResult{Valid: true, Fields: map[string]FieldResult{}}
	for _, k := range []string{"i1", "i2", "i3", "i4", "i5"} {
		fr := ValidateField(fields[k], c)
		out.Fields[k] = fr
		if !fr.OK {
			out.Valid = false
		}
	}
	return out
}

// BuildTag formats a single CPS tag.
func BuildTag(typ, value string) string {
	switch typ {
	case "b":
		if !strings.HasPrefix(strings.ToLower(value), "0x") {
			value = "0x" + value
		}
		return "<b " + value + ">"
	case "r", "rc", "rd":
		return "<" + typ + " " + value + ">"
	case "t":
		return "<t>"
	case "d":
		return "<d>"
	default:
		return ""
	}
}

// BuildCPS concatenates tags.
func BuildCPS(tags []string) string {
	return strings.Join(tags, "")
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}

func atoiDefault(s string, def int) int {
	s = strings.TrimSpace(s)
	if s == "" {
		return def
	}
	n, err := strconv.Atoi(s)
	if err != nil {
		return def
	}
	return n
}

// ConstraintsFromStrings builds Constraints from junk field strings.
func ConstraintsFromStrings(s1, s2, s3, s4 string, mtu int, allowD bool) Constraints {
	if mtu <= 0 {
		mtu = DefaultMTU
	}
	return Constraints{
		S1: atoiDefault(s1, 0), S2: atoiDefault(s2, 0),
		S3: atoiDefault(s3, 0), S4: atoiDefault(s4, 0),
		MTU: mtu, AllowD: allowD,
	}
}

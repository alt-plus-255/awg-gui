package cps

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"math/big"
	"strconv"
)

// GenerateOpts controls CPS generation.
type GenerateOpts struct {
	Protocol string
	Constraints
}

// GenerateResult is API-facing generate output.
type GenerateResult struct {
	I1, I2, I3, I4, I5 string
	Lengths            map[string]int      `json:"lengths"`
	Warnings           []string            `json:"warnings,omitempty"`
	Protocol           string              `json:"protocol"`
}

// Generate builds I1-I5 for the given protocol and constraints.
func Generate(opts GenerateOpts) GenerateResult {
	proto := opts.Protocol
	if proto == "" || !HasProtocol(proto) {
		proto = DefaultProtocol()
	}
	if opts.MTU <= 0 {
		opts.MTU = DefaultMTU
	}
	var cfg Config
	if proto == ProtocolRandom {
		cfg = generateRandomConfig(opts.Constraints)
	} else {
		cfg = generateProtocolConfig(proto, opts.Constraints)
	}
	out := GenerateResult{
		I1: cfg.I1, I2: cfg.I2, I3: cfg.I3, I4: cfg.I4, I5: cfg.I5,
		Lengths:  map[string]int{},
		Protocol: proto,
	}
	for k, v := range map[string]string{"i1": cfg.I1, "i2": cfg.I2, "i3": cfg.I3, "i4": cfg.I4, "i5": cfg.I5} {
		if n, err := CalculateLength(v); err == nil {
			out.Lengths[k] = n
		}
		fr := ValidateField(v, opts.Constraints)
		out.Warnings = append(out.Warnings, fr.Warnings...)
	}
	return out
}

// MapToJunk returns i1-i5 keys for ApplyJunk.
func (r GenerateResult) MapToJunk() map[string]string {
	return map[string]string{
		"i1": r.I1, "i2": r.I2, "i3": r.I3, "i4": r.I4, "i5": r.I5,
	}
}

func generateProtocolConfig(protocol string, c Constraints) Config {
	tmpl := getTemplate(protocol)
	maxI := MaxISize(c.MTU, c.S1)
	forbidden := ForbiddenSizes(c)
	return Config{
		I1: buildAndValidateCPS(tmpl.I1, maxI, forbidden),
		I2: buildAndValidateCPS(tmpl.I2, maxI, forbidden),
		I3: buildAndValidateCPS(tmpl.I3, maxI, forbidden),
		I4: buildAndValidateCPS(tmpl.I4, maxI, forbidden),
		I5: buildAndValidateCPS(tmpl.I5, maxI, forbidden),
	}
}

func buildAndValidateCPS(specs []TagSpec, maxSize int, forbidden []int) string {
	tags := append([]TagSpec{}, specs...)
	cps := buildCPSFromTemplate(tags)
	if cpsAcceptable(cps, maxSize, forbidden) {
		return cps
	}
	for len(tags) > 0 {
		tags = tags[:len(tags)-1]
		cps = buildCPSFromTemplate(tags)
		if cpsAcceptable(cps, maxSize, forbidden) {
			return cps
		}
	}
	for n := 1; n <= 8; n++ {
		perturbed := BuildTag("t", "") + BuildTag("r", strconv.Itoa(n))
		if cpsAcceptable(perturbed, maxSize, forbidden) {
			return perturbed
		}
	}
	return BuildTag("t", "")
}

func cpsAcceptable(cps string, maxSize int, forbidden []int) bool {
	n, err := CalculateLength(cps)
	if err != nil || n >= maxSize {
		return false
	}
	for _, f := range forbidden {
		if f > 0 && n == f {
			return false
		}
	}
	return true
}

func generateRandomConfig(c Constraints) Config {
	maxI := MaxISize(c.MTU, c.S1)
	forbidden := ForbiddenSizes(c)
	return Config{
		I1: generateSimpleI(maxI, forbidden),
		I2: generateSimpleI(maxI, forbidden),
		I3: generateSimpleI(maxI, forbidden),
		I4: generateSimpleI(maxI, forbidden),
		I5: generateSimpleI(maxI, forbidden),
	}
}

func generateSimpleI(maxSize int, forbidden []int) string {
	for attempt := 0; attempt < 32; attempt++ {
		cps := tagsToCPS(generateRandomTags())
		if cpsAcceptable(cps, maxSize, forbidden) {
			return cps
		}
	}
	for n := 1; n <= 8; n++ {
		perturbed := BuildTag("t", "") + BuildTag("r", strconv.Itoa(n))
		if cpsAcceptable(perturbed, maxSize, forbidden) {
			return perturbed
		}
	}
	return BuildTag("t", "")
}

type simpleTag struct {
	Type, Value string
}

func generateRandomTags() []simpleTag {
	all := []string{"b", "r", "rc", "rd", "t"}
	usedT := false
	count := minTagCount + cryptoRandInt(maxTagCount-minTagCount+1)
	tags := make([]simpleTag, 0, count)
	for i := 0; i < count; i++ {
		avail := make([]string, 0, len(all))
		for _, t := range all {
			if t == "t" && usedT {
				continue
			}
			avail = append(avail, t)
		}
		if len(avail) == 0 {
			break
		}
		typ := avail[cryptoRandInt(len(avail))]
		if typ == "t" {
			usedT = true
		}
		var value string
		switch typ {
		case "b":
			byteLen := minByteLen + cryptoRandInt(maxByteLen-minByteLen+1)
			buf := make([]byte, byteLen)
			_, _ = rand.Read(buf)
			value = "0x" + hex.EncodeToString(buf)
		case "r", "rc", "rd":
			size := minRandSize + cryptoRandInt(maxRandSize-minRandSize+1)
			value = strconv.Itoa(size)
		}
		tags = append(tags, simpleTag{Type: typ, Value: value})
	}
	return tags
}

func tagsToCPS(tags []simpleTag) string {
	parts := make([]string, 0, len(tags))
	for _, t := range tags {
		parts = append(parts, BuildTag(t.Type, t.Value))
	}
	return BuildCPS(parts)
}

func cryptoRandInt(n int) int {
	if n <= 1 {
		return 0
	}
	v, err := rand.Int(rand.Reader, big.NewInt(int64(n)))
	if err != nil {
		return 0
	}
	return int(v.Int64())
}

// MergeJunkWithCPS merges base junk map with generated CPS fields for supported profiles.
func MergeJunkWithCPS(junk map[string]string, protocol string, allowCPS bool) map[string]string {
	out := map[string]string{}
	for k, v := range junk {
		out[k] = v
	}
	if !allowCPS {
		out["i1"], out["i2"], out["i3"], out["i4"], out["i5"] = "", "", "", "", ""
		return out
	}
	c := ConstraintsFromStrings(out["s1"], out["s2"], out["s3"], out["s4"], DefaultMTU, true)
	gen := Generate(GenerateOpts{Protocol: protocol, Constraints: c})
	for k, v := range gen.MapToJunk() {
		out[k] = v
	}
	return out
}

// EnsureString is a tiny helper for tests / debug.
func EnsureString(v string) string {
	if v == "" {
		return fmt.Sprint(v)
	}
	return v
}

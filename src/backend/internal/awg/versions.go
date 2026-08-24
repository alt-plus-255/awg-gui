package awg

import (
	"crypto/rand"
	"math/big"
	"strings"

	"github.com/awggui/backend/internal/models"
)

var (
	baseParams = []string{"jc", "jmin", "jmax", "s1", "s2", "h1", "h2", "h3", "h4"}
	s34Params  = []string{"s3", "s4"}
	iParams    = []string{"i1", "i2", "i3", "i4", "i5"}
	allJunk    = []string{
		"jc", "jmin", "jmax",
		"s1", "s2", "s3", "s4",
		"h1", "h2", "h3", "h4",
		"i1", "i2", "i3", "i4", "i5",
	}
)

type VersionProfile interface {
	ID() string
	Label() string
	VpnURIProtocolVersion() string
	SupportedParams() []string
	GenerateJunkParams() map[string]string
	NormalizeForPersist(params map[string]string) map[string]string
	ConfObfuscationLines(cfg *models.AwgConfig) []string
	ConfObfuscationLinesFromParams(params map[string]string) []string
	VpnURIInnerParams(cfg *models.AwgConfig) map[string]string
	NeedsObfuscationParams(cfg *models.AwgConfig) bool
}

type versionProfile struct {
	id, label, vpnVer string
	params            []string
}

func (p *versionProfile) ID() string                    { return p.id }
func (p *versionProfile) Label() string                 { return p.label }
func (p *versionProfile) VpnURIProtocolVersion() string { return p.vpnVer }
func (p *versionProfile) SupportedParams() []string     { return append([]string{}, p.params...) }

func (p *versionProfile) GenerateJunkParams() map[string]string {
	return p.NormalizeForPersist(generateBaseJunk())
}

func (p *versionProfile) NormalizeForPersist(params map[string]string) map[string]string {
	supported := map[string]bool{}
	for _, k := range p.params {
		supported[k] = true
	}
	out := map[string]string{}
	for k, v := range params {
		if !contains(allJunk, k) {
			out[k] = v
			continue
		}
		if supported[k] {
			out[k] = v
		}
	}
	for _, k := range allJunk {
		if supported[k] {
			continue
		}
		if contains(iParams, k) {
			out[k] = ""
		} else {
			out[k] = "0"
		}
	}
	return out
}

func (p *versionProfile) ConfObfuscationLines(cfg *models.AwgConfig) []string {
	params := map[string]string{}
	for _, k := range allJunk {
		params[k] = cfg.JunkField(k)
	}
	return p.ConfObfuscationLinesFromParams(params)
}

func (p *versionProfile) ConfObfuscationLinesFromParams(params map[string]string) []string {
	var lines []string
	mapping := [][2]string{
		{"jc", "Jc"}, {"jmin", "Jmin"}, {"jmax", "Jmax"},
		{"s1", "S1"}, {"s2", "S2"}, {"s3", "S3"}, {"s4", "S4"},
		{"h1", "H1"}, {"h2", "H2"}, {"h3", "H3"}, {"h4", "H4"},
	}
	for _, pair := range mapping {
		if !contains(p.params, pair[0]) {
			continue
		}
		lines = append(lines, pair[1]+" = "+params[pair[0]])
	}
	for _, ikey := range iParams {
		if !contains(p.params, ikey) {
			continue
		}
		val := strings.TrimSpace(params[ikey])
		if val != "" {
			lines = append(lines, strings.ToUpper(ikey)+" = "+val)
		}
	}
	return lines
}

func (p *versionProfile) VpnURIInnerParams(cfg *models.AwgConfig) map[string]string {
	inner := map[string]string{}
	mapping := [][2]string{
		{"h1", "H1"}, {"h2", "H2"}, {"h3", "H3"}, {"h4", "H4"},
		{"jc", "Jc"}, {"jmin", "Jmin"}, {"jmax", "Jmax"},
		{"s1", "S1"}, {"s2", "S2"}, {"s3", "S3"}, {"s4", "S4"},
	}
	for _, pair := range mapping {
		if !contains(p.params, pair[0]) {
			continue
		}
		inner[pair[1]] = cfg.JunkField(pair[0])
	}
	if contains(p.params, "i1") {
		if i1 := strings.TrimSpace(cfg.JunkField("i1")); i1 != "" {
			inner["I1"] = i1
		}
	}
	return inner
}

func (p *versionProfile) NeedsObfuscationParams(cfg *models.AwgConfig) bool {
	for _, field := range p.params {
		if contains(iParams, field) {
			continue
		}
		if strings.TrimSpace(cfg.JunkField(field)) == "" {
			return true
		}
	}
	return cfg.Jc == "4" && cfg.Jmin == "64" && cfg.Jmax == "80" &&
		cfg.S1 == "0" && cfg.S2 == "0" &&
		cfg.H1 == "1" && cfg.H2 == "2" && cfg.H3 == "3" && cfg.H4 == "4" &&
		(!contains(p.params, "s3") || (cfg.S3 == "0" && cfg.S4 == "0"))
}

type VersionRegistry struct {
	order    []string
	profiles map[string]VersionProfile
}

func NewVersionRegistry() *VersionRegistry {
	r := &VersionRegistry{profiles: map[string]VersionProfile{}}
	r.Register(&versionProfile{id: "1.0", label: "AmneziaWG 1.0", vpnVer: "1", params: append([]string{}, baseParams...)})
	r.Register(&versionProfile{id: "1.5", label: "AmneziaWG 1.5", vpnVer: "1", params: append(append([]string{}, baseParams...), iParams...)})
	r.Register(&versionProfile{id: "2.0", label: "AmneziaWG 2.0", vpnVer: "2", params: append(append(append([]string{}, baseParams...), s34Params...), iParams...)})
	return r
}

func (r *VersionRegistry) Register(p VersionProfile) {
	id := p.ID()
	if _, ok := r.profiles[id]; !ok {
		r.order = append(r.order, id)
	}
	r.profiles[id] = p
}

func (r *VersionRegistry) All() []VersionProfile {
	out := make([]VersionProfile, 0, len(r.order))
	for _, id := range r.order {
		out = append(out, r.profiles[id])
	}
	return out
}

func (r *VersionRegistry) IDs() []string {
	return append([]string{}, r.order...)
}

func (r *VersionRegistry) Has(id string) bool {
	_, ok := r.profiles[id]
	return ok
}

func (r *VersionRegistry) Latest() string {
	if len(r.order) == 0 {
		return "2.0"
	}
	return r.order[len(r.order)-1]
}

func (r *VersionRegistry) LatestProfile() VersionProfile {
	return r.ProfileForConfig(r.Latest())
}

func (r *VersionRegistry) ProfileForConfig(protocolVersion string) VersionProfile {
	id := protocolVersion
	if id == "" {
		id = r.Latest()
	}
	if p, ok := r.profiles[id]; ok {
		return p
	}
	return r.profiles[r.Latest()]
}

func generateBaseJunk() map[string]string {
	jc := randInt(1, 10)
	jmin := randInt(64, 1023)
	jmax := randInt(jmin+1, 1024)
	s1 := randInt(0, 64)
	s2 := randInt(0, 64)
	for s1+56 == s2 {
		s2 = randInt(0, 64)
	}
	hs := make([]int, 0, 4)
	for len(hs) < 4 {
		h := randInt(1, 2147483647)
		dup := false
		for _, x := range hs {
			if x == h {
				dup = true
				break
			}
		}
		if !dup {
			hs = append(hs, h)
		}
	}
	return map[string]string{
		"jc": itoa(jc), "jmin": itoa(jmin), "jmax": itoa(jmax),
		"s1": itoa(s1), "s2": itoa(s2),
		"s3": itoa(randInt(0, 64)), "s4": itoa(randInt(0, 32)),
		"h1": itoa(hs[0]), "h2": itoa(hs[1]), "h3": itoa(hs[2]), "h4": itoa(hs[3]),
		"i1": "", "i2": "", "i3": "", "i4": "", "i5": "",
	}
}

func randInt(min, max int) int {
	if max < min {
		max = min
	}
	n, err := rand.Int(rand.Reader, big.NewInt(int64(max-min+1)))
	if err != nil {
		return min
	}
	return min + int(n.Int64())
}

func contains(list []string, v string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var buf [16]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		i--
		buf[i] = '-'
	}
	return string(buf[i:])
}

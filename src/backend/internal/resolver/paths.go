package resolver

import (
	"fmt"
	"os"
	"path/filepath"
)

type Paths struct {
	ConfigDir string
}

func (p Paths) RulesetDir() string {
	dir := filepath.Join(p.ConfigDir, "rulesets")
	_ = os.MkdirAll(dir, 0o755)
	return dir
}

func (p Paths) CommunityRulesetPath(tag string) string {
	return filepath.Join(p.RulesetDir(), tag+".srs")
}

func (p Paths) MergedRulesetPath(id int64) string {
	return filepath.Join(p.RulesetDir(), fmt.Sprintf("merged_cfg_%d.json", id))
}

func (p Paths) MergedRulesetTag(id int64) string {
	return fmt.Sprintf("merged_cfg_%d", id)
}

func (p Paths) MergedIPRulesetPath(id int64) string {
	return filepath.Join(p.RulesetDir(), fmt.Sprintf("merged_cfg_%d_ip.json", id))
}

func (p Paths) MergedIPRulesetTag(id int64) string {
	return fmt.Sprintf("merged_cfg_%d_ip", id)
}

func (p Paths) ProxyCIDRsAllPath() string {
	return filepath.Join(p.RulesetDir(), "proxy_cidrs_all.lst")
}

func (p Paths) DecompiledCachePath(tag string) string {
	return filepath.Join(p.RulesetDir(), ".decompile_"+tag+".json")
}

func (p Paths) DecompiledMetaPath(tag string) string {
	return filepath.Join(p.RulesetDir(), ".decompile_"+tag+".meta.json")
}

func (p Paths) SingBoxConfigPath() string {
	return filepath.Join(p.ConfigDir, "sing-box.json")
}

func (p Paths) SingBoxPingConfigPath() string {
	return filepath.Join(p.ConfigDir, "sing-box-ping.json")
}

func (p Paths) SingBoxSpeedConfigPath() string {
	return filepath.Join(p.ConfigDir, "sing-box-speed.json")
}

func (p Paths) ResolverIfacesPath() string {
	return filepath.Join(p.ConfigDir, "resolver-ifaces.txt")
}

func (p Paths) ResolverStatusPath() string {
	return filepath.Join(p.ConfigDir, "resolver-status.json")
}

func (p Paths) CustomRulesetPath(slug string) string {
	return filepath.Join(p.RulesetDir(), slug+".json")
}

func (p Paths) CustomSRSPath(slug string) string {
	return filepath.Join(p.RulesetDir(), slug+".srs")
}

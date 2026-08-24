package resolver

import (
	"context"
	"encoding/json"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

type Merged struct {
	Docker              Docker
	Container           string
	Paths               Paths
	Files               FileHelper
	ApplyProxyCIDRsChg  bool
	ApplyMergedChanged  bool
	decompileCache      map[string]map[string]any
	mu                  sync.Mutex
}

func (m *Merged) ResetChangeFlags() {
	m.ApplyProxyCIDRsChg = false
	m.ApplyMergedChanged = false
}

func (m *Merged) ForgetDecompileCache(tag string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.decompileCache != nil {
		delete(m.decompileCache, tag)
	}
}

func (m *Merged) DecompileCommunityRuleset(ctx context.Context, tag string) (map[string]any, error) {
	m.mu.Lock()
	if m.decompileCache == nil {
		m.decompileCache = map[string]map[string]any{}
	}
	if v, ok := m.decompileCache[tag]; ok {
		m.mu.Unlock()
		return v, nil
	}
	m.mu.Unlock()

	srs := m.Paths.CommunityRulesetPath(tag)
	if strings.HasPrefix(tag, "custom_") {
		srs = m.Paths.CustomSRSPath(tag)
	}
	if !fileExists(srs) {
		return nil, runtimeKeyParams("resolver.ruleset_not_on_disk_settings", map[string]string{"tag": tag})
	}
	st, _ := os.Stat(srs)
	srsSize := int(st.Size())
	srsMtime := int(st.ModTime().Unix())
	cachePath := m.Paths.DecompiledCachePath(tag)
	metaPath := m.Paths.DecompiledMetaPath(tag)
	if fileExists(cachePath) && fileExists(metaPath) {
		rawMeta, _ := os.ReadFile(metaPath)
		var meta map[string]any
		_ = json.Unmarshal(rawMeta, &meta)
		if atoiDef(strVal(meta["size"]), -1) == srsSize && atoiDef(strVal(meta["mtime"]), -1) == srsMtime {
			raw, _ := os.ReadFile(cachePath)
			var decoded map[string]any
			if json.Unmarshal(raw, &decoded) == nil && decoded != nil {
				m.mu.Lock()
				m.decompileCache[tag] = decoded
				m.mu.Unlock()
				return decoded, nil
			}
		}
	}

	outName := ".decompile_" + tag + ".json"
	r, err := m.Docker.Exec(ctx, m.Container, []string{
		"sing-box", "rule-set", "decompile",
		"-o", "/config/rulesets/" + outName,
		"/config/rulesets/" + tag + ".srs",
	}, 60*time.Second)
	if err != nil || !r.Successful() || !fileExists(cachePath) {
		_ = os.Remove(cachePath)
		_ = os.Remove(metaPath)
		msg := strings.TrimSpace(r.Stderr + " " + r.Stdout)
		if msg == "" && err != nil {
			msg = err.Error()
		}
		return nil, runtimeKeyParams("resolver.decompile_failed", map[string]string{"tag": tag, "error": msg})
	}
	raw, _ := os.ReadFile(cachePath)
	var decoded map[string]any
	if json.Unmarshal(raw, &decoded) != nil || decoded == nil {
		_ = os.Remove(cachePath)
		_ = os.Remove(metaPath)
		return nil, runtimeKeyParams("resolver.invalid_json_after_decompile", map[string]string{"tag": tag})
	}
	metaJSON, _ := json.Marshal(map[string]any{"size": srsSize, "mtime": srsMtime, "tag": tag})
	_ = os.WriteFile(metaPath, append(metaJSON, '\n'), 0o644)
	m.mu.Lock()
	m.decompileCache[tag] = decoded
	m.mu.Unlock()
	return decoded, nil
}

func (m *Merged) LoadRulesForTag(ctx context.Context, tag string) ([]map[string]any, error) {
	if strings.HasPrefix(tag, "custom_") {
		srs := m.Paths.CustomSRSPath(tag)
		if fileExists(srs) && fileSize(srs) > 16 {
			decoded, err := m.DecompileCommunityRuleset(ctx, tag)
			if err != nil {
				return nil, err
			}
			return rulesFromDecoded(decoded), nil
		}
		path := m.Paths.CustomRulesetPath(tag)
		if !fileExists(path) {
			return nil, runtimeKeyParams("resolver.custom_list_not_on_disk_short", map[string]string{"tag": tag})
		}
		raw, _ := os.ReadFile(path)
		var decoded map[string]any
		_ = json.Unmarshal(raw, &decoded)
		return rulesFromDecoded(decoded), nil
	}
	decoded, err := m.DecompileCommunityRuleset(ctx, tag)
	if err != nil {
		return nil, err
	}
	return rulesFromDecoded(decoded), nil
}

func rulesFromDecoded(decoded map[string]any) []map[string]any {
	raw, _ := decoded["rules"].([]any)
	var rules []map[string]any
	for _, r := range raw {
		if m, ok := r.(map[string]any); ok {
			rules = append(rules, m)
		}
	}
	return rules
}

func (m *Merged) CollectRuleField(rules []map[string]any, key string, lowercase bool) []string {
	var out []string
	for _, rule := range rules {
		raw := rule[key]
		var vals []string
		switch t := raw.(type) {
		case string:
			if t != "" {
				vals = []string{t}
			}
		case []any:
			for _, x := range t {
				if s := strVal(x); s != "" {
					vals = append(vals, s)
				}
			}
		case []string:
			vals = t
		}
		for _, v := range vals {
			if lowercase {
				v = strings.ToLower(v)
			}
			out = append(out, v)
		}
	}
	return out
}

func (m *Merged) WriteMergedRulesetForConfig(ctx context.Context, cfg *AWGConfig) (tag string, ipTag *string, ipCIDRs []string, err error) {
	var domainSuffix, domainExact, domainKeyword, domainRegex, ipCidrs []string
	for _, t := range cfg.CommunityLists {
		if t == "" {
			continue
		}
		rules, e := m.LoadRulesForTag(ctx, t)
		if e != nil {
			return "", nil, nil, e
		}
		domainSuffix = append(domainSuffix, m.CollectRuleField(rules, "domain_suffix", true)...)
		domainExact = append(domainExact, m.CollectRuleField(rules, "domain", true)...)
		domainKeyword = append(domainKeyword, m.CollectRuleField(rules, "domain_keyword", true)...)
		domainRegex = append(domainRegex, m.CollectRuleField(rules, "domain_regex", false)...)
		ipCidrs = append(ipCidrs, m.CollectRuleField(rules, "ip_cidr", false)...)
	}
	for _, d := range cfg.UserDomains {
		d = strings.ToLower(strings.TrimSpace(d))
		if d != "" {
			domainSuffix = append(domainSuffix, d)
		}
	}
	for _, c := range cfg.UserSubnets {
		c = strings.TrimSpace(c)
		if c != "" {
			ipCidrs = append(ipCidrs, c)
		}
	}
	domainSuffix = uniqueStrings(domainSuffix)
	domainExact = uniqueStrings(domainExact)
	domainKeyword = uniqueStrings(domainKeyword)
	domainRegex = uniqueStrings(domainRegex)
	ipCidrs = uniqueStrings(m.NormalizeIPv4CIDRs(ipCidrs))

	domainRule := map[string]any{}
	if len(domainSuffix) > 0 {
		domainRule["domain_suffix"] = domainSuffix
	}
	if len(domainExact) > 0 {
		domainRule["domain"] = domainExact
	}
	if len(domainKeyword) > 0 {
		domainRule["domain_keyword"] = domainKeyword
	}
	if len(domainRegex) > 0 {
		domainRule["domain_regex"] = domainRegex
	}
	rules := []map[string]any{domainRule}
	if len(domainRule) == 0 {
		rules = []map[string]any{{"domain_suffix": []string{"invalid.invalid"}}}
	}
	payload := map[string]any{"version": 3, "rules": rules}
	js, _ := json.MarshalIndent(payload, "", "    ")
	changed, err := m.Files.WriteIfChanged(m.Paths.MergedRulesetPath(cfg.ID), string(js)+"\n")
	if err != nil {
		return "", nil, nil, err
	}
	var ipT *string
	if len(ipCidrs) > 0 {
		ipPayload := map[string]any{"version": 3, "rules": []map[string]any{{"ip_cidr": ipCidrs}}}
		ipJS, _ := json.MarshalIndent(ipPayload, "", "    ")
		ch, err := m.Files.WriteIfChanged(m.Paths.MergedIPRulesetPath(cfg.ID), string(ipJS)+"\n")
		if err != nil {
			return "", nil, nil, err
		}
		if ch {
			changed = true
		}
		t := m.Paths.MergedIPRulesetTag(cfg.ID)
		ipT = &t
	} else {
		legacy := m.Paths.MergedIPRulesetPath(cfg.ID)
		if fileExists(legacy) {
			_ = os.Remove(legacy)
			changed = true
		}
	}
	if changed {
		m.ApplyMergedChanged = true
	}
	return m.Paths.MergedRulesetTag(cfg.ID), ipT, ipCidrs, nil
}

func (m *Merged) NormalizeIPv4CIDRs(cidrs []string) []string {
	var out []string
	for _, cidr := range cidrs {
		cidr = strings.TrimSpace(cidr)
		if cidr == "" {
			continue
		}
		if !strings.Contains(cidr, "/") {
			if isIPv4(cidr) {
				cidr += "/32"
			} else {
				continue
			}
		}
		host, mask, ok := strings.Cut(cidr, "/")
		if !ok || !isIPv4(host) {
			continue
		}
		n, err := strconv.Atoi(mask)
		if err != nil || n < 0 || n > 32 {
			continue
		}
		if host == "198.18.0.0" && n == 15 {
			continue
		}
		out = append(out, host+"/"+strconv.Itoa(n))
	}
	return uniqueStrings(out)
}

func (m *Merged) WriteProxyCIDRsAll(cidrs []string) (bool, error) {
	cidrs = uniqueStrings(m.NormalizeIPv4CIDRs(cidrs))
	sort.Strings(cidrs)
	contents := ""
	if len(cidrs) > 0 {
		contents = strings.Join(cidrs, "\n") + "\n"
	}
	changed, err := m.Files.WriteIfChanged(m.Paths.ProxyCIDRsAllPath(), contents)
	if changed {
		m.ApplyProxyCIDRsChg = true
	}
	return changed, err
}

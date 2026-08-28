package resolver

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/awggui/backend/internal/i18n"
)

type Lists struct {
	Store  *Store
	KV     *KV
	Paths  Paths
	Files  FileHelper
	Merged *Merged
	HTTP   *http.Client
	Svc    *Service
}

func (l *Lists) client() *http.Client {
	if l.HTTP != nil {
		return l.HTTP
	}
	return &http.Client{Timeout: 90 * time.Second}
}

func (l *Lists) SyncIntervalMinutes(ctx context.Context) int {
	v := atoiDef(l.KV.Get(ctx, SettingInterval, strconv.Itoa(DefaultInterval)), DefaultInterval)
	if v < 5 {
		v = 5
	}
	if v > 10080 {
		v = 10080
	}
	return v
}

func (l *Lists) SetSyncIntervalMinutes(ctx context.Context, minutes int) error {
	if minutes < 5 {
		minutes = 5
	}
	if minutes > 10080 {
		minutes = 10080
	}
	return l.KV.Set(ctx, SettingInterval, strconv.Itoa(minutes))
}

func (l *Lists) LastSyncAt(ctx context.Context) *string {
	v := strings.TrimSpace(l.KV.Get(ctx, SettingLastSync, ""))
	if v == "" {
		return nil
	}
	return &v
}

func (l *Lists) NeedsInitialSync(ctx context.Context) bool {
	if l.LastSyncAt(ctx) == nil {
		return true
	}
	for _, item := range CommunityListCatalog() {
		tag := strVal(item["tag"])
		path := l.Paths.CommunityRulesetPath(tag)
		if !fileExists(path) || fileSize(path) <= 16 {
			return true
		}
	}
	return false
}

func (l *Lists) ListMeta(ctx context.Context) map[string]map[string]any {
	out := map[string]map[string]any{}
	raw := l.KV.Get(ctx, SettingListMeta, "{}")
	_ = json.Unmarshal([]byte(raw), &out)
	if out == nil {
		out = map[string]map[string]any{}
	}
	return out
}

func (l *Lists) SaveListMeta(ctx context.Context, meta map[string]map[string]any) error {
	return l.KV.SetJSON(ctx, SettingListMeta, meta)
}

func (l *Lists) IsCustomTag(tag string) bool { return strings.HasPrefix(tag, "custom_") }

func (l *Lists) CustomDiskPath(slug string) string {
	srs := l.Paths.CustomSRSPath(slug)
	if fileExists(srs) && fileSize(srs) > 16 {
		return srs
	}
	js := l.Paths.CustomRulesetPath(slug)
	if fileExists(js) && fileSize(js) > 0 {
		return js
	}
	return ""
}

func (l *Lists) AssertListFilePresent(ctx context.Context, tag string) error {
	locale := Locale(ctx)
	if l.IsCustomTag(tag) {
		if l.CustomDiskPath(tag) == "" {
			return runtimeKeyParams("resolver.custom_list_not_on_disk", map[string]string{"tag": tag})
		}
		return nil
	}
	path := l.Paths.CommunityRulesetPath(tag)
	if !fileExists(path) || fileSize(path) <= 16 {
		return fmt.Errorf("%s", i18n.Tf(locale, "resolver.list_not_downloaded", map[string]string{"tag": tag}))
	}
	return nil
}

func (l *Lists) AssertSelectedListsOnDisk(ctx context.Context, tags []string) error {
	for _, tag := range tags {
		if tag == "" {
			continue
		}
		if err := l.AssertListFilePresent(ctx, tag); err != nil {
			return err
		}
	}
	return nil
}

func (l *Lists) KnownListTags(ctx context.Context) []string {
	tags := append([]string{}, CommunityLists...)
	lists, _ := l.Store.ListCustomLists(ctx)
	for _, c := range lists {
		tags = append(tags, c.Slug)
	}
	return uniqueStrings(tags)
}

func (l *Lists) CustomListCatalog(ctx context.Context) []map[string]any {
	locale := Locale(ctx)
	lists, _ := l.Store.ListCustomLists(ctx)
	out := make([]map[string]any, 0, len(lists))
	suffix := i18n.T(locale, "api.custom_list_suffix")
	for _, c := range lists {
		var src any
		if c.IsRemote() {
			src = *c.SourceURL
		}
		out = append(out, map[string]any{
			"tag":             c.Slug,
			"label":           c.Name + " " + suffix,
			"kind":            "custom",
			"exclusive_group": nil,
			"id":              c.ID,
			"source_url":      src,
			"domains_count":   len(c.Domains),
			"cidrs_count":     len(c.CIDRs),
		})
	}
	return out
}

func (l *Lists) ListsTableRows(ctx context.Context) []map[string]any {
	meta := l.ListMeta(ctx)
	var rows []map[string]any
	for _, item := range CommunityListCatalog() {
		tag := strVal(item["tag"])
		path := l.Paths.CommunityRulesetPath(tag)
		exists := fileExists(path) && fileSize(path) > 16
		size := 0
		var mtime *string
		if exists {
			size = int(fileSize(path))
			mtime = fileMTimeISO(path)
		}
		downloaded := mtime
		if m, ok := meta[tag]; ok {
			if s, ok := m["downloaded_at"].(string); ok && s != "" {
				downloaded = &s
			}
		}
		rows = append(rows, map[string]any{
			"tag": tag, "label": item["label"], "kind": "community",
			"source_url": item["source_url"], "on_disk": exists, "size": size,
			"downloaded_at": downloaded, "can_sync": true, "can_edit": false, "can_delete": false,
		})
	}
	customs, _ := l.Store.ListCustomLists(ctx)
	for _, list := range customs {
		path := l.CustomDiskPath(list.Slug)
		exists := path != ""
		size := 0
		var mtime *string
		if exists {
			size = int(fileSize(path))
			mtime = fileMTimeISO(path)
		} else if list.UpdatedAt != nil {
			s := list.UpdatedAt.UTC().Format(time.RFC3339)
			mtime = &s
		}
		downloaded := mtime
		if m, ok := meta[list.Slug]; ok {
			if s, ok := m["downloaded_at"].(string); ok && s != "" {
				downloaded = &s
			}
		}
		var src any
		if list.IsRemote() {
			src = *list.SourceURL
		}
		rows = append(rows, map[string]any{
			"tag": list.Slug, "label": list.Name, "kind": "custom", "id": list.ID,
			"source_url": src, "on_disk": exists, "size": size, "downloaded_at": downloaded,
			"domains": list.Domains, "cidrs": list.CIDRs,
			"can_sync": list.IsRemote(), "can_edit": true, "can_delete": true,
		})
	}
	return rows
}

func (l *Lists) SettingsPayload(ctx context.Context) map[string]any {
	bootstrap := DefaultBootstrapDNS
	if l.Svc != nil {
		bootstrap = l.Svc.BootstrapDNS(ctx)
	}
	return map[string]any{
		"sync_interval_minutes": l.SyncIntervalMinutes(ctx),
		"bootstrap_dns":         bootstrap,
		"last_sync_at":          l.LastSyncAt(ctx),
		"needs_initial_sync":    l.NeedsInitialSync(ctx),
		"lists":                 l.ListsTableRows(ctx),
	}
}

func (l *Lists) SyncCommunity(ctx context.Context, tags []string, force bool) error {
	if tags == nil {
		tags = CommunityLists
	}
	meta := l.ListMeta(ctx)
	var errors []string
	downloadedAny := false
	cli := l.client()
	for _, tag := range uniqueStrings(tags) {
		if !IsCommunityTag(tag) {
			continue
		}
		path := l.Paths.CommunityRulesetPath(tag)
		if !force && fileExists(path) && fileSize(path) > 16 {
			continue
		}
		tmp := path + ".tmp"
		u := CommunitySourceURL(tag)
		resp, err := cli.Get(u)
		if err != nil {
			_ = os.Remove(tmp)
			if !fileExists(path) || fileSize(path) <= 16 {
				errors = append(errors, tag+": "+err.Error())
			} else {
				log.Printf("ruleset refresh failed for %s, keeping cached file: %v", tag, err)
			}
			continue
		}
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 50_000_000))
		resp.Body.Close()
		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			errMsg := fmt.Sprintf("HTTP %d", resp.StatusCode)
			if !fileExists(path) || fileSize(path) <= 16 {
				errors = append(errors, tag+": "+errMsg)
			}
			continue
		}
		if len(body) < 16 {
			errors = append(errors, tag+": "+i18n.T(Locale(ctx), "resolver.empty_or_tiny_file"))
			continue
		}
		if err := os.WriteFile(tmp, body, 0o644); err != nil {
			errors = append(errors, tag+": "+err.Error())
			continue
		}
		if err := os.Rename(tmp, path); err != nil {
			_ = os.Remove(tmp)
			errors = append(errors, tag+": "+err.Error())
			continue
		}
		_ = os.Remove(l.Paths.DecompiledCachePath(tag))
		_ = os.Remove(l.Paths.DecompiledMetaPath(tag))
		if l.Merged != nil {
			l.Merged.ForgetDecompileCache(tag)
		}
		meta[tag] = map[string]any{"downloaded_at": isoNow(), "size": int(fileSize(path))}
		downloadedAny = true
	}
	_ = l.SaveListMeta(ctx, meta)
	if force || downloadedAny {
		_ = l.KV.Set(ctx, SettingLastSync, isoNow())
	}
	if len(errors) > 0 {
		return runtimeKeyParams("resolver.ruleset_download_failed", map[string]string{"errors": strings.Join(errors, "; ")})
	}
	return nil
}

func (l *Lists) SyncIfDue(ctx context.Context) (bool, error) {
	last := l.LastSyncAt(ctx)
	interval := l.SyncIntervalMinutes(ctx)
	due := last == nil
	if !due {
		t, err := time.Parse(time.RFC3339, *last)
		if err != nil {
			t, err = time.Parse(time.RFC3339Nano, *last)
		}
		if err != nil {
			due = true
		} else {
			due = !time.Now().Before(t.Add(time.Duration(interval) * time.Minute))
		}
	}
	var needed []string
	cfgs, _ := l.Store.EnabledServerConfigs(ctx)
	for _, cfg := range cfgs {
		for _, tag := range cfg.CommunityLists {
			if tag == "" || l.IsCustomTag(tag) {
				continue
			}
			path := l.Paths.CommunityRulesetPath(tag)
			if !fileExists(path) || fileSize(path) <= 16 {
				needed = append(needed, tag)
			}
		}
	}
	needed = uniqueStrings(needed)
	if !due && len(needed) == 0 {
		return false, nil
	}
	if due {
		if err := l.SyncCommunity(ctx, nil, true); err != nil {
			return true, err
		}
		if err := l.SyncAllRemoteCustoms(ctx, true); err != nil {
			return true, err
		}
	} else {
		if err := l.SyncCommunity(ctx, needed, true); err != nil {
			return true, err
		}
		lists, _ := l.Store.ListCustomLists(ctx)
		for _, list := range lists {
			if !list.IsRemote() {
				continue
			}
			if l.CustomDiskPath(list.Slug) == "" {
				_ = l.SyncCustomRemote(ctx, list, true)
			}
		}
	}
	return true, nil
}

func (l *Lists) SyncOneTag(ctx context.Context, tag string, force bool) error {
	if l.IsCustomTag(tag) {
		list, err := l.Store.GetCustomListBySlug(ctx, tag)
		if err != nil {
			return err
		}
		if list == nil || !list.IsRemote() {
			return runtimeKey("resolver.sync_custom_url_only")
		}
		return l.SyncCustomRemote(ctx, list, force)
	}
	return l.SyncCommunity(ctx, []string{tag}, force)
}

func (l *Lists) SyncAllRemoteCustoms(ctx context.Context, force bool) error {
	var errors []string
	lists, _ := l.Store.ListCustomLists(ctx)
	for _, list := range lists {
		if !list.IsRemote() {
			continue
		}
		if err := l.SyncCustomRemote(ctx, list, force); err != nil {
			errors = append(errors, list.Slug+": "+TranslateErr(Locale(ctx), err))
		}
	}
	if len(errors) > 0 {
		return runtimeKeyParams("resolver.custom_url_lists_download_failed", map[string]string{"errors": strings.Join(errors, "; ")})
	}
	return nil
}

func (l *Lists) SyncCustomRemote(ctx context.Context, list *CustomList, force bool) error {
	if !list.IsRemote() {
		return runtimeKey("resolver.list_has_no_url")
	}
	u := strings.TrimSpace(*list.SourceURL)
	srsPath := l.Paths.CustomSRSPath(list.Slug)
	jsonPath := l.Paths.CustomRulesetPath(list.Slug)
	if !force && l.CustomDiskPath(list.Slug) != "" {
		return nil
	}
	resp, err := l.client().Get(u)
	if err != nil {
		return err
	}
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 50_000_000))
	resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return runtimeKeyParams("resolver.http_status_for_url", map[string]string{"status": strconv.Itoa(resp.StatusCode), "url": u})
	}
	if len(body) < 4 {
		return runtimeKey("resolver.empty_url_response")
	}
	if looksLikeSRS(body, u) {
		tmp := srsPath + ".tmp"
		if err := os.WriteFile(tmp, body, 0o644); err != nil {
			return err
		}
		if err := os.Rename(tmp, srsPath); err != nil {
			_ = os.Remove(tmp)
			return err
		}
		_ = os.Remove(jsonPath)
		_ = os.Remove(l.Paths.DecompiledCachePath(list.Slug))
		_ = os.Remove(l.Paths.DecompiledMetaPath(list.Slug))
		if l.Merged != nil {
			l.Merged.ForgetDecompileCache(list.Slug)
		}
		list.Domains = []string{}
		list.CIDRs = []string{}
		if err := l.Store.UpdateCustomList(ctx, list); err != nil {
			return err
		}
	} else {
		parsed, err := l.parseRemoteTextList(string(body))
		if err != nil {
			return err
		}
		list.Domains = parsed["domains"]
		list.CIDRs = parsed["cidrs"]
		if err := l.Store.UpdateCustomList(ctx, list); err != nil {
			return err
		}
		if err := l.WriteCustomRulesetFile(list); err != nil {
			return err
		}
		_ = os.Remove(srsPath)
		_ = os.Remove(l.Paths.DecompiledCachePath(list.Slug))
		_ = os.Remove(l.Paths.DecompiledMetaPath(list.Slug))
	}
	meta := l.ListMeta(ctx)
	disk := l.CustomDiskPath(list.Slug)
	sz := 0
	if disk != "" {
		sz = int(fileSize(disk))
	}
	meta[list.Slug] = map[string]any{"downloaded_at": isoNow(), "size": sz}
	return l.SaveListMeta(ctx, meta)
}

func looksLikeSRS(body []byte, u string) bool {
	path := strings.ToLower(u)
	if i := strings.Index(path, "?"); i >= 0 {
		path = path[:i]
	}
	if strings.HasSuffix(path, ".srs") {
		return true
	}
	if strings.Contains(string(body), "\x00") {
		return true
	}
	return !utf8.Valid(body)
}

func (l *Lists) parseRemoteTextList(body string) (map[string][]string, error) {
	var domains, cidrs []string
	for _, line := range splitLines(body) {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, "//") {
			continue
		}
		line = strings.TrimPrefix(strings.TrimPrefix(line, "http://"), "https://")
		if i := strings.Index(line, "#"); i >= 0 {
			line = strings.TrimSpace(line[:i])
		}
		if line == "" {
			continue
		}
		if strings.Contains(line, "/") || isIP(line) {
			n, err := l.NormalizeCustomEntries(nil, []string{line})
			if err != nil {
				continue
			}
			cidrs = append(cidrs, n["cidrs"]...)
			continue
		}
		n, err := l.NormalizeCustomEntries([]string{line}, nil)
		if err != nil {
			continue
		}
		domains = append(domains, n["domains"]...)
	}
	domains = uniqueStrings(domains)
	cidrs = uniqueStrings(cidrs)
	if len(domains) == 0 && len(cidrs) == 0 {
		return nil, runtimeKey("resolver.text_list_no_domains_cidrs")
	}
	return map[string][]string{"domains": domains, "cidrs": cidrs}, nil
}

func (l *Lists) NormalizeSourceURL(raw *string) (*string, error) {
	u := strings.TrimSpace(strPtrVal(raw))
	if u == "" {
		return nil, nil
	}
	if !strings.HasPrefix(strings.ToLower(u), "http://") && !strings.HasPrefix(strings.ToLower(u), "https://") {
		return nil, FieldErr("source_url", "resolver.source_url_required", nil)
	}
	return &u, nil
}

func (l *Lists) NormalizeCustomEntries(domains, cidrs []string) (map[string][]string, error) {
	var outD, outC []string
	for _, raw := range domains {
		for _, part := range splitTokens(raw) {
			part = strings.ToLower(strings.TrimSpace(part))
			if part == "" || strings.HasPrefix(part, "//") {
				continue
			}
			part = strings.TrimPrefix(strings.TrimPrefix(part, "http://"), "https://")
			if i := strings.IndexAny(part, "/:"); i >= 0 {
				part = part[:i]
			}
			part = strings.TrimLeft(part, ".")
			if !domainOK(part) {
				return nil, FieldErr("domains", "resolver.invalid_domain", map[string]string{"raw": raw})
			}
			outD = append(outD, part)
		}
	}
	for _, raw := range cidrs {
		part := strings.TrimSpace(raw)
		if part == "" || strings.HasPrefix(part, "//") {
			continue
		}
		if !strings.Contains(part, "/") {
			if isIPv4(part) {
				part += "/32"
			} else if isIPv6(part) {
				part += "/128"
			}
		}
		if !validCIDR(part) {
			return nil, FieldErr("cidrs", "resolver.invalid_subnet", map[string]string{"raw": raw})
		}
		outC = append(outC, part)
	}
	return map[string][]string{"domains": uniqueStrings(outD), "cidrs": uniqueStrings(outC)}, nil
}

func splitTokens(raw string) []string {
	return strings.FieldsFunc(raw, func(r rune) bool {
		return r == ' ' || r == '\t' || r == ',' || r == ';' || r == '\n'
	})
}

func (l *Lists) CreateCustomList(ctx context.Context, name string, domains, cidrs []string, sourceURL *string) (*CustomList, error) {
	name = strings.TrimSpace(name)
	if name == "" {
		return nil, FieldErr("name", "resolver.list_name_required", nil)
	}
	src, err := l.NormalizeSourceURL(sourceURL)
	if err != nil {
		return nil, err
	}
	list := &CustomList{Name: name, Slug: "tmp_" + sha16(isoNow()+name), Domains: []string{}, CIDRs: []string{}, SourceURL: src}
	id, err := l.Store.InsertCustomList(ctx, list)
	if err != nil {
		return nil, err
	}
	list.ID = id
	list.Slug = CustomSlug(id)
	if err := l.Store.UpdateCustomList(ctx, list); err != nil {
		return nil, err
	}
	if src != nil {
		if err := l.SyncCustomRemote(ctx, list, true); err != nil {
			return nil, err
		}
	} else {
		n, err := l.NormalizeCustomEntries(domains, cidrs)
		if err != nil {
			return nil, err
		}
		if len(n["domains"]) == 0 && len(n["cidrs"]) == 0 {
			_ = l.Store.DeleteCustomList(ctx, list.ID)
			return nil, FieldErr("domains", "resolver.add_domains_or_url", nil)
		}
		list.Domains = n["domains"]
		list.CIDRs = n["cidrs"]
		if err := l.Store.UpdateCustomList(ctx, list); err != nil {
			return nil, err
		}
		if err := l.WriteCustomRulesetFile(list); err != nil {
			return nil, err
		}
	}
	return l.Store.GetCustomList(ctx, list.ID)
}

func (l *Lists) UpdateCustomList(ctx context.Context, list *CustomList, name string, domains, cidrs []string, sourceURL *string) (*CustomList, error) {
	name = strings.TrimSpace(name)
	if name == "" {
		return nil, FieldErr("name", "resolver.list_name_required", nil)
	}
	src, err := l.NormalizeSourceURL(sourceURL)
	if err != nil {
		return nil, err
	}
	list.Name = name
	if !strings.HasPrefix(list.Slug, "custom_") {
		list.Slug = CustomSlug(list.ID)
	}
	list.SourceURL = src
	if src != nil {
		list.Domains = []string{}
		list.CIDRs = []string{}
		if err := l.Store.UpdateCustomList(ctx, list); err != nil {
			return nil, err
		}
		if err := l.SyncCustomRemote(ctx, list, true); err != nil {
			return nil, err
		}
	} else {
		n, err := l.NormalizeCustomEntries(domains, cidrs)
		if err != nil {
			return nil, err
		}
		if len(n["domains"]) == 0 && len(n["cidrs"]) == 0 {
			return nil, FieldErr("domains", "resolver.add_domains_or_url", nil)
		}
		list.Domains = n["domains"]
		list.CIDRs = n["cidrs"]
		if err := l.Store.UpdateCustomList(ctx, list); err != nil {
			return nil, err
		}
		_ = os.Remove(l.Paths.CustomSRSPath(list.Slug))
		_ = os.Remove(l.Paths.DecompiledCachePath(list.Slug))
		_ = os.Remove(l.Paths.DecompiledMetaPath(list.Slug))
		if err := l.WriteCustomRulesetFile(list); err != nil {
			return nil, err
		}
	}
	return l.Store.GetCustomList(ctx, list.ID)
}

func (l *Lists) DeleteCustomList(ctx context.Context, list *CustomList) error {
	_ = l.Store.DetachCommunityTag(ctx, list.Slug)
	l.DeleteCustomRulesetFile(list.Slug)
	return l.Store.DeleteCustomList(ctx, list.ID)
}

func (l *Lists) WriteCustomRulesetFile(list *CustomList) error {
	domains := uniqueStrings(list.Domains)
	for i := range domains {
		domains[i] = strings.ToLower(strings.TrimSpace(domains[i]))
	}
	cidrs := uniqueStrings(list.CIDRs)
	var rules []map[string]any
	if len(domains) > 0 {
		rules = append(rules, map[string]any{"domain_suffix": domains})
	}
	if len(cidrs) > 0 {
		rules = append(rules, map[string]any{"ip_cidr": cidrs})
	}
	if len(rules) == 0 {
		rules = append(rules, map[string]any{"domain_suffix": []string{"invalid.invalid"}})
	}
	payload := map[string]any{"version": 3, "rules": rules}
	js, _ := json.MarshalIndent(payload, "", "    ")
	_, err := l.Files.WriteIfChanged(l.Paths.CustomRulesetPath(list.Slug), string(js)+"\n")
	return err
}

func (l *Lists) DeleteCustomRulesetFile(slug string) {
	_ = os.Remove(l.Paths.CustomRulesetPath(slug))
	_ = os.Remove(l.Paths.CustomSRSPath(slug))
	_ = os.Remove(l.Paths.DecompiledCachePath(slug))
	_ = os.Remove(l.Paths.DecompiledMetaPath(slug))
	if l.Merged != nil {
		l.Merged.ForgetDecompileCache(slug)
	}
}

func (l *Lists) CustomPayload(list *CustomList) map[string]any {
	return map[string]any{
		"id": list.ID, "name": list.Name, "slug": list.Slug, "tag": list.Slug,
		"source_url": list.SourceURL, "domains": list.Domains, "cidrs": list.CIDRs,
		"updated_at": isoTime(list.UpdatedAt),
	}
}

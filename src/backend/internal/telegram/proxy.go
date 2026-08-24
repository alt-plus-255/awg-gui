package telegram

import (
	"context"
	"crypto/sha1"
	"encoding/hex"
	"strings"
	"time"

	"github.com/awggui/backend/internal/store"
)

const (
	cacheKeyWinner     = "telegram.proxy.winner"
	failPrefix         = "telegram.proxy.fail."
	probeTTL           = 120 * time.Second
	failTTL            = 90 * time.Second
	hotProbeTimeoutSec = 3
)

type ProxyPool struct {
	Settings *Settings
	Bot      *Client
	Cache    *store.Cache
}

type candidateMeta struct {
	ID      string
	URL     string
	Display string
	Source  string
}

func (p *ProxyPool) ResolveProxyURL(ctx context.Context, exclude []string) *string {
	if p.Settings.Mode(ctx) != ModePolling {
		return nil
	}
	candidates := p.candidateURLs(ctx)
	if len(candidates) == 0 {
		return nil
	}
	if cached, ok := p.Cache.Get(ctx, cacheKeyWinner); ok && cached != "" && !contains(exclude, cached) && !p.isFailed(ctx, cached) {
		if contains(candidates, cached) {
			return &cached
		}
	}
	if winner := p.probeFirstOK(ctx, exclude); winner != nil {
		p.Cache.Put(ctx, cacheKeyWinner, *winner, probeTTL)
		return winner
	}
	for _, u := range candidates {
		if !contains(exclude, u) {
			v := u
			return &v
		}
	}
	return nil
}

func (p *ProxyPool) ProbeStatus(ctx context.Context) []map[string]any {
	var rows []map[string]any
	for _, meta := range p.candidateMeta(ctx) {
		u := meta.URL
		latency := p.Bot.ProbeLatency(ctx, &u, 8*time.Second, "")
		row := map[string]any{
			"url":        meta.Display,
			"latency_ms": nil,
			"ok":         latency != nil,
			"source":     meta.Source,
			"id":         meta.ID,
		}
		if latency != nil {
			row["latency_ms"] = *latency
		}
		rows = append(rows, row)
	}
	return rows
}

func (p *ProxyPool) MarkFailed(ctx context.Context, proxyURL string) {
	p.Cache.Put(ctx, failPrefix+sha1hex(proxyURL), "1", failTTL)
	if cached, ok := p.Cache.Get(ctx, cacheKeyWinner); ok && cached == proxyURL {
		p.Cache.Forget(ctx, cacheKeyWinner)
	}
}

func (p *ProxyPool) MarkSuccess(ctx context.Context, proxyURL string) {
	p.Cache.Forget(ctx, failPrefix+sha1hex(proxyURL))
	p.Cache.Put(ctx, cacheKeyWinner, proxyURL, probeTTL)
}

func (p *ProxyPool) ClearCache(ctx context.Context) {
	p.Cache.Forget(ctx, cacheKeyWinner)
}

func (p *ProxyPool) candidateURLs(ctx context.Context) []string {
	seen := map[string]bool{}
	var out []string
	for _, m := range p.candidateMeta(ctx) {
		if seen[m.URL] {
			continue
		}
		seen[m.URL] = true
		out = append(out, m.URL)
	}
	return out
}

func (p *ProxyPool) candidateMeta(ctx context.Context) []candidateMeta {
	var meta []candidateMeta
	hasConnection := false
	for _, proxy := range p.Settings.Proxies(ctx) {
		if !proxy.Enabled {
			continue
		}
		if proxy.Type == "url" {
			u := strings.TrimSpace(proxy.URL)
			if u == "" {
				continue
			}
			meta = append(meta, candidateMeta{ID: proxy.ID, URL: u, Display: p.Settings.MaskProxyURL(u), Source: "url"})
		}
		if proxy.Type == "connection" {
			hasConnection = true
		}
	}
	if hasConnection {
		mixed := p.Settings.MixedProxyURL(ctx)
		meta = append(meta, candidateMeta{ID: "resolver-mixed", URL: mixed, Display: p.Settings.MaskProxyURL(mixed), Source: "connection"})
	}
	return meta
}

func (p *ProxyPool) probeFirstOK(ctx context.Context, exclude []string) *string {
	for _, meta := range p.candidateMeta(ctx) {
		u := meta.URL
		if contains(exclude, u) || p.isFailed(ctx, u) {
			continue
		}
		latency := p.Bot.ProbeLatency(ctx, &u, hotProbeTimeoutSec*time.Second, "")
		if latency == nil {
			p.MarkFailed(ctx, u)
			continue
		}
		return &u
	}
	return nil
}

func (p *ProxyPool) isFailed(ctx context.Context, proxyURL string) bool {
	return p.Cache.Has(ctx, failPrefix+sha1hex(proxyURL))
}

func sha1hex(s string) string {
	sum := sha1.Sum([]byte(s))
	return hex.EncodeToString(sum[:])
}

func contains(list []string, v string) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

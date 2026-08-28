package api

import (
	"context"
	"net"
	"net/http"
	"strings"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/awg"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/resolver"
)

type ResolverController struct {
	Svc *resolver.Service
	AWG *awg.Service
}

func (c *ResolverController) ctx(r *http.Request) *http.Request {
	locale := auth.LocaleFromContext(r.Context())
	return r.WithContext(resolver.WithLocale(r.Context(), locale))
}

func (c *ResolverController) Show(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	writeJSON(w, http.StatusOK, c.Svc.Status(r.Context()))
}

func (c *ResolverController) UpdateConfig(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	ctx := r.Context()
	locale := auth.LocaleFromContext(ctx)
	id, ok := pathID(r, "config")
	if !ok {
		writeNotFound(w, r)
		return
	}
	cfg, err := c.Svc.Store.GetConfig(ctx, id)
	if err != nil || cfg == nil {
		writeNotFound(w, r)
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	enabled, ok := asBool(req["resolver_enabled"])
	if !ok {
		writeValidation(w, r, "resolver_enabled", "api.http_422", nil)
		return
	}
	if v, ok := req["resolver_reject_quic"]; ok {
		if b, ok := asBool(v); ok {
			cfg.ResolverRejectQUIC = b
		}
	}
	if _, has := req["resolver_dns"]; has {
		dns := strings.TrimSpace(asString(req["resolver_dns"]))
		if dns == "" {
			dns = "1.1.1.1"
		}
		if net.ParseIP(dns) == nil && !resolver.ValidDNSServer(dns) {
			writeValidation(w, r, "resolver_dns", "resolver.dns_required", nil)
			return
		}
		cfg.ResolverDNS = &dns
	}

	wasEnabled := cfg.ResolverEnabled
	if enabled {
		if err := c.Svc.AssertCanEnable(cfg); err != nil {
			writeResolverErr(w, r, err)
			return
		}
	} else if cfg.Type == "virtual_network" {
		cfg.ResolverEnabled = false
		cfg.ConnectionID = nil
		cfg.ResolverLastError = nil
		if err := c.Svc.Store.UpdateConfigResolver(ctx, cfg); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
			return
		}
		c.applyAWG(ctx, cfg.ID)
		_ = c.Svc.Apply(ctx, resolver.ApplyOpts{})
		writeJSON(w, http.StatusOK, map[string]any{
			"ok": true, "status": c.Svc.Status(ctx), "warning": nil,
		})
		return
	}

	if enabled {
		lists := cfg.CommunityLists
		domains := cfg.UserDomains
		subnets := cfg.UserSubnets
		if v, ok := req["community_lists"]; ok {
			lists = asStringSlice(v)
		}
		if v, ok := req["user_domains"]; ok {
			domains = asStringSlice(v)
		}
		if v, ok := req["user_subnets"]; ok {
			subnets = asStringSlice(v)
		}
		norm, err := c.Svc.NormalizeLists(ctx, lists, domains, subnets)
		if err != nil {
			writeResolverErr(w, r, err)
			return
		}
		var connID *int64
		if _, has := req["connection_id"]; has {
			if req["connection_id"] != nil && asString(req["connection_id"]) != "" {
				if n, ok := asInt64(req["connection_id"]); ok {
					connID = &n
				}
			}
		} else {
			connID = cfg.ConnectionID
		}
		conn, err := c.Svc.AssertConnectionSelected(ctx, connID)
		if err != nil {
			writeResolverErr(w, r, err)
			return
		}
		id := conn.ID
		cfg.ResolverEnabled = true
		cfg.ConnectionID = &id
		cfg.CommunityLists = norm["community_lists"]
		cfg.UserDomains = norm["user_domains"]
		cfg.UserSubnets = norm["user_subnets"]
	} else {
		cfg.ResolverEnabled = false
		if _, has := req["connection_id"]; has {
			if req["connection_id"] == nil || asString(req["connection_id"]) == "" {
				cfg.ConnectionID = nil
			} else if n, ok := asInt64(req["connection_id"]); ok {
				cfg.ConnectionID = &n
			}
		}
		if _, ok1 := req["community_lists"]; ok1 {
			lists := asStringSlice(req["community_lists"])
			domains := cfg.UserDomains
			subnets := cfg.UserSubnets
			if v, ok := req["user_domains"]; ok {
				domains = asStringSlice(v)
			}
			if v, ok := req["user_subnets"]; ok {
				subnets = asStringSlice(v)
			}
			norm, err := c.Svc.NormalizeLists(ctx, lists, domains, subnets)
			if err != nil {
				writeResolverErr(w, r, err)
				return
			}
			cfg.CommunityLists = norm["community_lists"]
			cfg.UserDomains = norm["user_domains"]
			cfg.UserSubnets = norm["user_subnets"]
		}
		cfg.ResolverLastError = nil
	}

	if err := c.Svc.Store.UpdateConfigResolver(ctx, cfg); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	c.applyAWG(ctx, cfg.ID)
	_ = c.Svc.Apply(ctx, resolver.ApplyOpts{})

	status := c.Svc.Status(ctx)
	var applyError any
	if enabled {
		if items, ok := status["configs"].([]map[string]any); ok {
			for _, item := range items {
				if int64(atoiSafe(item["id"])) == cfg.ID {
					if errStr := asString(item["resolver_last_error"]); errStr != "" {
						applyError = errStr
					}
				}
			}
		}
	}
	var warning any
	if wasEnabled != cfg.ResolverEnabled {
		warning = i18n.T(locale, "resolver.clients_need_reimport")
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": applyError == nil, "status": status, "apply_error": applyError, "warning": warning,
	})
}

func (c *ResolverController) applyAWG(ctx context.Context, configID int64) {
	if c.AWG == nil {
		return
	}
	cfg, err := c.AWG.Configs.Find(ctx, configID)
	if err != nil || cfg == nil {
		return
	}
	_ = c.AWG.ApplyConfig(ctx, cfg, false, false)
}

func atoiSafe(v any) int {
	n, _ := asInt(v)
	return n
}

func (c *ResolverController) Refresh(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	if err := c.Svc.Apply(r.Context(), resolver.ApplyOpts{ForceSyncLists: true}); err != nil {
		writeResolverErr(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": c.Svc.Status(r.Context())})
}

func (c *ResolverController) Diagnose(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	writeJSON(w, http.StatusOK, c.Svc.Diagnose(r.Context()))
}

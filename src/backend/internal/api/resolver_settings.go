package api

import (
	"net/http"
	"strings"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/resolver"
	"github.com/go-chi/chi/v5"
)

type ResolverSettingsController struct {
	Svc *resolver.Service
}

func (c *ResolverSettingsController) ctx(r *http.Request) *http.Request {
	return r.WithContext(resolver.WithLocale(r.Context(), auth.LocaleFromContext(r.Context())))
}

func (c *ResolverSettingsController) Show(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	writeJSON(w, http.StatusOK, c.Svc.Lists.SettingsPayload(r.Context()))
}

func (c *ResolverSettingsController) Update(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	ctx := r.Context()
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	changed := false
	applySingbox := false

	if v, ok := req["sync_interval_minutes"]; ok {
		minutes, ok := asInt(v)
		if !ok || minutes < 5 || minutes > 10080 {
			writeValidation(w, r, "sync_interval_minutes", "api.http_422", nil)
			return
		}
		if err := c.Svc.Lists.SetSyncIntervalMinutes(ctx, minutes); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
			return
		}
		changed = true
	}

	if v, ok := req["bootstrap_dns"]; ok {
		dns := strings.TrimSpace(asString(v))
		if dns == "" {
			dns = resolver.DefaultBootstrapDNS
		}
		if !resolver.ValidDNSServer(dns) {
			writeValidation(w, r, "bootstrap_dns", "resolver.dns_required", nil)
			return
		}
		if err := c.Svc.SetBootstrapDNS(ctx, dns); err != nil {
			writeResolverErr(w, r, err)
			return
		}
		changed = true
		applySingbox = true
	}

	if !changed {
		writeValidation(w, r, "sync_interval_minutes", "api.http_422", nil)
		return
	}

	if applySingbox {
		if err := c.Svc.Apply(ctx, resolver.ApplyOpts{}); err != nil {
			writeResolverErr(w, r, err)
			return
		}
		if c.Svc.Probe != nil {
			c.Svc.Probe.RebuildAndMaybeReload(ctx)
		}
	}

	writeJSON(w, http.StatusOK, c.Svc.Lists.SettingsPayload(ctx))
}

func (c *ResolverSettingsController) SyncAll(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	ctx := r.Context()
	var errors []string
	if err := c.Svc.Lists.SyncCommunity(ctx, nil, true); err != nil {
		errors = append(errors, resolver.TranslateErr(auth.LocaleFromContext(ctx), err))
	}
	if err := c.Svc.Lists.SyncAllRemoteCustoms(ctx, true); err != nil {
		errors = append(errors, resolver.TranslateErr(auth.LocaleFromContext(ctx), err))
	}
	payload := c.Svc.Lists.SettingsPayload(ctx)
	if len(errors) > 0 {
		out := map[string]any{"ok": false, "message": joinSemi(errors)}
		for k, v := range payload {
			out[k] = v
		}
		writeJSON(w, http.StatusUnprocessableEntity, out)
		return
	}
	out := map[string]any{"ok": true}
	for k, v := range payload {
		out[k] = v
	}
	writeJSON(w, http.StatusOK, out)
}

func (c *ResolverSettingsController) SyncOne(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	tag := chi.URLParam(r, "tag")
	if tag == "" {
		writeMessage(w, r, http.StatusUnprocessableEntity, "resolver.empty_tag", nil)
		return
	}
	if err := c.Svc.Lists.SyncOneTag(r.Context(), tag, true); err != nil {
		payload := c.Svc.Lists.SettingsPayload(r.Context())
		out := map[string]any{"ok": false, "message": resolver.TranslateErr(auth.LocaleFromContext(r.Context()), err)}
		for k, v := range payload {
			out[k] = v
		}
		writeJSON(w, http.StatusUnprocessableEntity, out)
		return
	}
	payload := c.Svc.Lists.SettingsPayload(r.Context())
	out := map[string]any{"ok": true}
	for k, v := range payload {
		out[k] = v
	}
	writeJSON(w, http.StatusOK, out)
}

func joinSemi(parts []string) string {
	out := ""
	for i, p := range parts {
		if i > 0 {
			out += "; "
		}
		out += p
	}
	return out
}

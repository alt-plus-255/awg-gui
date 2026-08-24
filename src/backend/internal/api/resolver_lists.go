package api

import (
	"net/http"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/resolver"
)

type ResolverCustomListController struct {
	Svc *resolver.Service
}

func (c *ResolverCustomListController) ctx(r *http.Request) *http.Request {
	return r.WithContext(resolver.WithLocale(r.Context(), auth.LocaleFromContext(r.Context())))
}

func (c *ResolverCustomListController) Index(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	writeJSON(w, http.StatusOK, map[string]any{"lists": c.Svc.Lists.CustomListCatalog(r.Context())})
}

func (c *ResolverCustomListController) Store(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	name := asString(req["name"])
	if name == "" {
		writeValidation(w, r, "name", "resolver.list_name_required", nil)
		return
	}
	var src *string
	if v, ok := req["source_url"]; ok {
		s := asString(v)
		src = &s
	}
	list, err := c.Svc.Lists.CreateCustomList(r.Context(), name, asStringSlice(req["domains"]), asStringSlice(req["cidrs"]), src)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	writeJSON(w, http.StatusCreated, map[string]any{
		"ok": true, "list": c.Svc.Lists.CustomPayload(list),
		"settings": c.Svc.Lists.SettingsPayload(r.Context()),
	})
}

func (c *ResolverCustomListController) Update(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	id, ok := pathID(r, "customList")
	if !ok {
		writeNotFound(w, r)
		return
	}
	list, err := c.Svc.Store.GetCustomList(r.Context(), id)
	if err != nil || list == nil {
		writeNotFound(w, r)
		return
	}
	var req map[string]any
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	name := asString(req["name"])
	if name == "" {
		writeValidation(w, r, "name", "resolver.list_name_required", nil)
		return
	}
	src := list.SourceURL
	if _, has := req["source_url"]; has {
		s := asString(req["source_url"])
		src = &s
	}
	updated, err := c.Svc.Lists.UpdateCustomList(r.Context(), list, name, asStringSlice(req["domains"]), asStringSlice(req["cidrs"]), src)
	if err != nil {
		writeResolverErr(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "list": c.Svc.Lists.CustomPayload(updated),
		"settings": c.Svc.Lists.SettingsPayload(r.Context()),
	})
}

func (c *ResolverCustomListController) Destroy(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	id, ok := pathID(r, "customList")
	if !ok {
		writeNotFound(w, r)
		return
	}
	list, err := c.Svc.Store.GetCustomList(r.Context(), id)
	if err != nil || list == nil {
		writeNotFound(w, r)
		return
	}
	if err := c.Svc.Lists.DeleteCustomList(r.Context(), list); err != nil {
		writeResolverErr(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": true, "settings": c.Svc.Lists.SettingsPayload(r.Context()),
	})
}

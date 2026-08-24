package api

import (
	"net/http"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/resolver"
)

type ResolverSpeedTestController struct {
	Svc *resolver.Service
}

func (c *ResolverSpeedTestController) ctx(r *http.Request) *http.Request {
	return r.WithContext(resolver.WithLocale(r.Context(), auth.LocaleFromContext(r.Context())))
}

func (c *ResolverSpeedTestController) Status(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	writeJSON(w, http.StatusOK, c.Svc.Speed.Status())
}

func (c *ResolverSpeedTestController) RunConnection(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	id, ok := pathID(r, "connection")
	if !ok {
		writeNotFound(w, r)
		return
	}
	conn, err := c.Svc.Store.GetConnection(r.Context(), id)
	if err != nil || conn == nil {
		writeNotFound(w, r)
		return
	}
	var req map[string]any
	_ = decodeJSON(r, &req)
	if req == nil {
		req = map[string]any{}
	}
	var nodeKey *string
	if v := asString(req["node_key"]); v != "" {
		nodeKey = &v
	}
	payload, err := c.Svc.Speed.EnqueueConnection(r.Context(), conn, nodeKey)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"ok": false, "async": false,
			"error": resolver.TranslateErr(auth.LocaleFromContext(r.Context()), err),
			"job":   c.Svc.Speed.GetJob(),
		})
		return
	}
	writeJSON(w, http.StatusAccepted, payload)
}

func (c *ResolverSpeedTestController) RunBatch(w http.ResponseWriter, r *http.Request) {
	r = c.ctx(r)
	conns, err := c.Svc.Store.EnabledConnections(r.Context())
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	payload, err := c.Svc.Speed.EnqueueBatch(r.Context(), conns)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"ok": false, "async": false,
			"error":   resolver.TranslateErr(auth.LocaleFromContext(r.Context()), err),
			"job":     c.Svc.Speed.GetJob(),
			"results": []any{},
		})
		return
	}
	writeJSON(w, http.StatusAccepted, payload)
}

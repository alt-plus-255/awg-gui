package api

import (
	"net/http"
	"strconv"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/system"
)

type SystemController struct {
	Sys  *system.Service
	Host *system.HostMetrics
}

func (c *SystemController) Status(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	auth.WriteJSON(w, http.StatusOK, c.Sys.Status(r.Context(), locale))
}

func (c *SystemController) Processes(w http.ResponseWriter, r *http.Request) {
	sort := r.URL.Query().Get("sort")
	if sort != "mem" {
		sort = "cpu"
	}
	limit := 40
	if v := r.URL.Query().Get("limit"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			limit = n
		}
	}
	data := c.Host.ProcessMonitor(sort, limit)
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"ok":         true,
		"sort":       sort,
		"processes":  data["processes"],
		"containers": data["containers"],
	})
}

func (c *SystemController) RestartAwg(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	code, body := c.Sys.RestartAWG(r.Context(), locale)
	auth.WriteJSON(w, code, body)
}

func (c *SystemController) RestartSingBox(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	code, body := c.Sys.RestartSingBox(r.Context(), locale)
	auth.WriteJSON(w, code, body)
}

func (c *SystemController) RestartAll(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	auth.WriteJSON(w, http.StatusOK, c.Sys.RestartAllLocalized(locale))
}

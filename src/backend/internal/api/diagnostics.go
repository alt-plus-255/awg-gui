package api

import (
	"encoding/json"
	"net/http"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/diagnostics"
	"github.com/awggui/backend/internal/i18n"
)

type DiagnosticsController struct {
	Diag *diagnostics.Service
}

func (c *DiagnosticsController) Status(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	auth.WriteJSON(w, http.StatusOK, c.Diag.Status(r.Context(), locale))
}

func (c *DiagnosticsController) Run(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	var body struct {
		ConfigIDs []int64 `json:"config_ids"`
	}
	if r.Body != nil && r.ContentLength != 0 {
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
				"message": i18n.T(locale, "api.http_422"),
			})
			return
		}
	}
	var ids []int64
	if body.ConfigIDs != nil {
		for _, id := range body.ConfigIDs {
			if id >= 1 {
				ids = append(ids, id)
			}
		}
	}
	auth.WriteJSON(w, http.StatusOK, c.Diag.Run(r.Context(), locale, ids))
}

func (c *DiagnosticsController) SingBoxConfig(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	auth.WriteJSON(w, http.StatusOK, c.Diag.SingBoxConfig(locale))
}

func (c *DiagnosticsController) AwgConfigs(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	auth.WriteJSON(w, http.StatusOK, c.Diag.AwgConfigs(r.Context(), locale))
}

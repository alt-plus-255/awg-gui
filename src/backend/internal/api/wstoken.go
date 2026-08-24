package api

import (
	"net/http"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/ws"
)

type WsTokenController struct {
	Tokens *ws.TokenStore
}

func (c *WsTokenController) Show(w http.ResponseWriter, r *http.Request) {
	user := auth.UserFromContext(r.Context())
	if user == nil {
		locale := auth.LocaleFromContext(r.Context())
		auth.WriteJSON(w, http.StatusUnauthorized, map[string]any{
			"message": i18n.T(locale, "api.unauthenticated"),
			"error":   "unauthenticated",
		})
		return
	}
	token, err := c.Tokens.Issue(user.ID)
	if err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	auth.WriteJSON(w, http.StatusOK, map[string]any{"token": token})
}

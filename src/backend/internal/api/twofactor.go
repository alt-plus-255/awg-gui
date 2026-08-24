package api

import (
	"encoding/json"
	"net/http"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/i18n"
)

type TwoFactorController struct {
	TwoFactor *auth.TwoFactorService
}

func (c *TwoFactorController) Status(w http.ResponseWriter, r *http.Request) {
	user := auth.UserFromContext(r.Context())
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"enabled": c.TwoFactor.IsEnabled(user),
		"pending": auth.PendingTwoFactor(user),
	})
}

func (c *TwoFactorController) Setup(w http.ResponseWriter, r *http.Request) {
	user := auth.UserFromContext(r.Context())
	payload, err := c.TwoFactor.BeginSetup(r.Context(), user)
	if err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"secret":      payload.Secret,
		"otpauth_uri": payload.OtpauthURI,
		"qr":          payload.QR,
		"enabled":     false,
		"pending":     true,
	})
}

func (c *TwoFactorController) Confirm(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	var body struct {
		Code string `json:"code"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.Code == "" {
		auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": i18n.T(locale, "api.http_422"),
			"errors": map[string]any{
				"code": []string{i18n.T(locale, "api.http_422")},
			},
		})
		return
	}
	user := auth.UserFromContext(r.Context())
	ok, err := c.TwoFactor.Confirm(r.Context(), user, body.Code)
	if err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	if !ok {
		auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": i18n.T(locale, "auth.confirm_code_invalid"),
			"errors": map[string]any{
				"code": []string{i18n.T(locale, "auth.confirm_code_invalid")},
			},
		})
		return
	}
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"ok":      true,
		"enabled": true,
		"pending": false,
	})
}

func (c *TwoFactorController) Destroy(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	var body struct {
		Password string `json:"password"`
		Code     string `json:"code"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.Password == "" || body.Code == "" {
		auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": i18n.T(locale, "api.http_422"),
		})
		return
	}
	user := auth.UserFromContext(r.Context())
	if !auth.CheckPassword(user.Password, body.Password) {
		auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": i18n.T(locale, "auth.password_invalid"),
			"errors": map[string]any{
				"password": []string{i18n.T(locale, "auth.password_invalid")},
			},
		})
		return
	}
	if !c.TwoFactor.Verify(user, body.Code) {
		auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": i18n.T(locale, "auth.two_factor_code_invalid"),
			"errors": map[string]any{
				"code": []string{i18n.T(locale, "auth.two_factor_code_invalid")},
			},
		})
		return
	}
	if err := c.TwoFactor.Disable(r.Context(), user); err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"ok":      true,
		"enabled": false,
		"pending": false,
	})
}

package api

import (
	"database/sql"
	"encoding/json"
	"net"
	"net/http"
	"net/url"
	"strings"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/i18n"
)

type AuthController struct {
	Cfg        config.Config
	DB         *sql.DB
	Sessions   *auth.Manager
	Users      *auth.UserStore
	Protection *auth.LoginProtectionService
	Captcha    *auth.CaptchaService
	TwoFactor  *auth.TwoFactorService
}

type loginRequest struct {
	Username      string  `json:"username"`
	Password      string  `json:"password"`
	CaptchaToken  *string `json:"captcha_token"`
	CaptchaAnswer *string `json:"captcha_answer"`
	TOTP          *string `json:"totp"`
}

func (c *AuthController) LoginStatus(w http.ResponseWriter, r *http.Request) {
	st, err := c.Protection.Status(r.Context(), authClientIP(r))
	if err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	auth.WriteJSON(w, http.StatusOK, st)
}

func (c *AuthController) LoginInfo(w http.ResponseWriter, r *http.Request) {
	auth.WriteJSON(w, http.StatusOK, c.panelAccessInfo(r))
}

func (c *AuthController) LoginCaptcha(w http.ResponseWriter, r *http.Request) {
	payload, err := c.Captcha.Generate(r.Context())
	if err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	auth.WriteJSON(w, http.StatusOK, payload)
}

func (c *AuthController) Login(w http.ResponseWriter, r *http.Request) {
	locale := auth.LocaleFromContext(r.Context())
	var req loginRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": i18n.T(locale, "api.http_422"),
		})
		return
	}
	req.Username = strings.TrimSpace(req.Username)
	if req.Username == "" || req.Password == "" {
		auth.WriteJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"message": i18n.T(locale, "api.http_422"),
			"errors": map[string]any{
				"username": []string{i18n.T(locale, "api.http_422")},
			},
		})
		return
	}

	ip := authClientIP(r)
	status, err := c.Protection.Status(r.Context(), ip)
	if err != nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": err.Error()})
		return
	}
	if status.Locked {
		c.authError(w, locale, "locked", i18n.T(locale, "auth.login_locked"), http.StatusTooManyRequests, status)
		return
	}
	if status.CaptchaRequired {
		token, answer := "", ""
		if req.CaptchaToken != nil {
			token = *req.CaptchaToken
		}
		if req.CaptchaAnswer != nil {
			answer = *req.CaptchaAnswer
		}
		if !c.Captcha.Verify(r.Context(), token, answer) {
			status.CaptchaRequired = true
			c.authError(w, locale, "captcha_invalid", i18n.T(locale, "auth.captcha_invalid"), http.StatusUnprocessableEntity, status)
			return
		}
	}

	user, err := c.Users.FindByLogin(r.Context(), req.Username)
	if err != nil || user == nil || !auth.CheckPassword(user.Password, req.Password) {
		status, _ = c.Protection.RecordFailedPassword(r.Context(), ip)
		if status.Locked {
			c.authError(w, locale, "locked", i18n.T(locale, "auth.login_locked"), http.StatusTooManyRequests, status)
			return
		}
		code := "invalid_credentials"
		msg := i18n.T(locale, "auth.invalid_credentials")
		if status.CaptchaRequired {
			code = "captcha_required"
			msg = i18n.T(locale, "auth.invalid_credentials_captcha")
		}
		c.authError(w, locale, code, msg, http.StatusUnprocessableEntity, status)
		return
	}

	if c.TwoFactor.IsEnabled(user) {
		totp := ""
		if req.TOTP != nil {
			totp = *req.TOTP
		}
		if totp == "" {
			c.authError(w, locale, "totp_required", i18n.T(locale, "auth.totp_required"), http.StatusUnprocessableEntity, status)
			return
		}
		if !c.TwoFactor.Verify(user, totp) {
			c.authError(w, locale, "totp_invalid", i18n.T(locale, "auth.totp_invalid"), http.StatusUnprocessableEntity, status)
			return
		}
	}

	sess := auth.SessionFromContext(r.Context())
	if sess == nil {
		auth.WriteJSON(w, http.StatusInternalServerError, map[string]any{"message": "session missing"})
		return
	}
	sess.SetAuthUserID(user.ID)
	_ = c.Protection.Clear(r.Context(), ip)
	_ = c.Sessions.Regenerate(r.Context(), sess)

	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"user": c.userPayload(user),
	})
}

func (c *AuthController) Logout(w http.ResponseWriter, r *http.Request) {
	sess := auth.SessionFromContext(r.Context())
	if sess != nil {
		sess.ClearAuth()
	}
	auth.MarkSessionExpired(r.Context())
	auth.WriteJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (c *AuthController) Me(w http.ResponseWriter, r *http.Request) {
	user := auth.UserFromContext(r.Context())
	if user == nil {
		auth.WriteJSON(w, http.StatusUnauthorized, map[string]any{
			"message": i18n.T(auth.LocaleFromContext(r.Context()), "api.unauthenticated"),
			"error":   "unauthenticated",
		})
		return
	}
	auth.WriteJSON(w, http.StatusOK, map[string]any{
		"user": c.userPayload(user),
	})
}

func (c *AuthController) CsrfCookie(w http.ResponseWriter, r *http.Request) {
	// Session middleware already issued laravel_session + XSRF-TOKEN.
	w.WriteHeader(http.StatusNoContent)
}

func (c *AuthController) userPayload(u *auth.User) map[string]any {
	var username any
	if u.Username.Valid {
		username = u.Username.String
	} else {
		username = nil
	}
	return map[string]any{
		"id":                 u.ID,
		"username":           username,
		"name":               u.Name,
		"email":              u.Email,
		"two_factor_enabled": c.TwoFactor.IsEnabled(u),
	}
}

func (c *AuthController) authError(w http.ResponseWriter, locale, code, message string, httpStatus int, status auth.ProtectionStatus) {
	payload := map[string]any{
		"message": message,
		"code":    code,
		"errors": map[string]any{
			"username": []string{message},
		},
		"attempts":              status.Attempts,
		"captcha_required":      status.CaptchaRequired,
		"locked":                status.Locked,
		"locked_until":          status.LockedUntil,
		"remaining_seconds":     status.RemainingSeconds,
		"lock_duration_minutes": status.LockDurationMinutes,
		"lockout_count":         status.LockoutCount,
	}
	_ = locale
	auth.WriteJSON(w, httpStatus, payload)
}

func (c *AuthController) panelAccessInfo(r *http.Request) map[string]any {
	host := ""
	if u, err := url.Parse(c.Cfg.AppURL); err == nil {
		host = u.Hostname()
	}
	if h := auth.SettingGet(r.Context(), c.DB, "panel_domain", ""); h != "" {
		host = h
	}
	if host == "" {
		host = "localhost"
	}
	port := auth.SettingGet(r.Context(), c.DB, "panel_port", c.Cfg.PanelPort)
	httpsPort := auth.SettingGet(r.Context(), c.DB, "panel_https_port", c.Cfg.PanelHTTPSPort)
	sslEnabled := auth.ParseBoolish(auth.SettingGet(r.Context(), c.DB, "ssl_enabled", "0"))
	panelURL := c.Cfg.AppURL
	if sslEnabled {
		panelURL = "https://" + host
		if httpsPort != "" && httpsPort != "443" {
			panelURL += ":" + httpsPort
		}
	} else {
		panelURL = "http://" + host
		if port != "" && port != "80" {
			panelURL += ":" + port
		}
	}
	username, _ := c.Users.FirstUsername(r.Context())
	return map[string]any{
		"host":        host,
		"port":        port,
		"https_port":  httpsPort,
		"panel_url":   panelURL,
		"ssl_enabled": sslEnabled,
		"username":    username,
	}
}

func authClientIP(r *http.Request) string {
	if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
		parts := strings.Split(xff, ",")
		ip := strings.TrimSpace(parts[0])
		if ip != "" {
			return ip
		}
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err == nil {
		return host
	}
	if r.RemoteAddr != "" {
		return r.RemoteAddr
	}
	return "0.0.0.0"
}

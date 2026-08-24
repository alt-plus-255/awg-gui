package auth

import (
	"context"
	"database/sql"
	"net/http"
	"strings"

	"github.com/awggui/backend/internal/i18n"
)

// Middleware holds shared auth/session middleware dependencies.
type Middleware struct {
	Sessions *Manager
	Users    *UserStore
	DB       *sql.DB
	Locale   string
}

type sessionHolder struct {
	sess   *Session
	expire bool
}

const ctxSessionHolder contextKey = 100

// MarkSessionExpired clears the session cookie on the way out (logout).
func MarkSessionExpired(ctx context.Context) {
	if h, ok := ctx.Value(ctxSessionHolder).(*sessionHolder); ok && h != nil {
		h.expire = true
	}
}

func (m *Middleware) StartSession(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if m.Sessions == nil || r.URL.Path == "/up" {
			next.ServeHTTP(w, r)
			return
		}
		sess, err := m.Sessions.Load(r)
		if err != nil {
			http.Error(w, "session error", http.StatusInternalServerError)
			return
		}
		holder := &sessionHolder{sess: sess}
		ctx := WithSession(r.Context(), sess)
		ctx = context.WithValue(ctx, ctxSessionHolder, holder)
		if uid, ok := sess.AuthUserID(); ok && m.Users != nil {
			if u, err := m.Users.FindByID(r.Context(), uid); err == nil {
				ctx = WithUser(ctx, u)
			}
		}
		rw := &sessionWriter{ResponseWriter: w, m: m, r: r, holder: holder}
		next.ServeHTTP(rw, r.WithContext(ctx))
		if !rw.flushed {
			rw.flush()
		}
	})
}

type sessionWriter struct {
	http.ResponseWriter
	m       *Middleware
	r       *http.Request
	holder  *sessionHolder
	flushed bool
}

func (rw *sessionWriter) WriteHeader(code int) {
	rw.flush()
	rw.ResponseWriter.WriteHeader(code)
}

func (rw *sessionWriter) Write(b []byte) (int, error) {
	rw.flush()
	return rw.ResponseWriter.Write(b)
}

func (rw *sessionWriter) flush() {
	if rw.flushed || rw.m == nil || rw.holder == nil {
		return
	}
	rw.flushed = true
	sess := rw.holder.sess
	if sess == nil {
		sess = SessionFromContext(rw.r.Context())
	}
	if rw.holder.expire {
		if sess != nil {
			_ = rw.m.Sessions.Destroy(context.Background(), sess)
		}
		rw.m.Sessions.ExpireCookies(rw.ResponseWriter, rw.r)
		fresh := rw.m.Sessions.newSession(rw.r)
		_ = rw.m.Sessions.Save(context.Background(), fresh, rw.r)
		rw.m.Sessions.WriteCookies(rw.ResponseWriter, rw.r, fresh)
		return
	}
	if sess != nil {
		_ = rw.m.Sessions.Save(context.Background(), sess, rw.r)
		rw.m.Sessions.WriteCookies(rw.ResponseWriter, rw.r, sess)
	}
}

func (m *Middleware) SetLocale(next http.Handler) http.Handler {
	fallback := m.Locale
	if fallback == "" {
		fallback = "en"
	}
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		locale := ResolveLocale(r.Header.Get("Accept-Language"), fallback)
		next.ServeHTTP(w, r.WithContext(WithLocale(r.Context(), locale)))
	})
}

func (m *Middleware) VerifyCSRF(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case http.MethodGet, http.MethodHead, http.MethodOptions, http.MethodTrace:
			next.ServeHTTP(w, r)
			return
		}
		if r.URL.Path == "/sanctum/csrf-cookie" {
			next.ServeHTTP(w, r)
			return
		}
		if r.Method == http.MethodPost && strings.HasPrefix(r.URL.Path, "/api/telegram/webhook/") {
			next.ServeHTTP(w, r)
			return
		}
		sess := SessionFromContext(r.Context())
		if sess == nil {
			writeCSRFError(w, r)
			return
		}
		expected := sess.CSRFToken()
		got := r.Header.Get("X-XSRF-TOKEN")
		if got == "" {
			got = r.Header.Get("X-CSRF-TOKEN")
		}
		if got == "" {
			if c, err := r.Cookie("XSRF-TOKEN"); err == nil {
				got = c.Value
			}
		}
		if expected == "" || got == "" || !hmacEqual(expected, got) {
			writeCSRFError(w, r)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func (m *Middleware) RequireAuth(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if isPublicAPI(r) {
			next.ServeHTTP(w, r)
			return
		}
		if UserFromContext(r.Context()) != nil {
			next.ServeHTTP(w, r)
			return
		}
		locale := LocaleFromContext(r.Context())
		WriteJSON(w, http.StatusUnauthorized, map[string]any{
			"message": i18n.T(locale, "api.unauthenticated"),
			"error":   "unauthenticated",
		})
	})
}

func isPublicAPI(r *http.Request) bool {
	path := r.URL.Path
	if r.Method == http.MethodPost && path == "/api/login" {
		return true
	}
	if r.Method == http.MethodGet && path == "/api/login/status" {
		return true
	}
	if r.Method == http.MethodGet && path == "/api/login/info" {
		return true
	}
	if r.Method == http.MethodGet && path == "/api/login/captcha" {
		return true
	}
	if r.Method == http.MethodPost && strings.HasPrefix(path, "/api/telegram/webhook/") {
		return true
	}
	return false
}

// ResolveLocale mirrors SetLocale middleware (en/ru).
func ResolveLocale(header, fallback string) string {
	supported := map[string]bool{"en": true, "ru": true}
	if fallback == "" || !supported[fallback] {
		fallback = "en"
	}
	if header == "" {
		return fallback
	}
	for _, part := range strings.Split(header, ",") {
		tag := strings.ToLower(strings.TrimSpace(strings.Split(part, ";")[0]))
		if tag == "" {
			continue
		}
		primary := strings.Split(tag, "-")[0]
		if supported[primary] {
			return primary
		}
	}
	return fallback
}

func writeCSRFError(w http.ResponseWriter, r *http.Request) {
	locale := LocaleFromContext(r.Context())
	WriteJSON(w, 419, map[string]any{
		"message": i18n.T(locale, "api.http_419"),
		"error":   "page_expired",
	})
}

// WriteJSON writes a JSON response.
func WriteJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = encodeJSON(w, v)
}

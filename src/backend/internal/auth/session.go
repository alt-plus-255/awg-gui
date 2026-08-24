package auth

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/awggui/backend/internal/config"
)

const (
	CSRFTokenKey   = "_token"
	SessionUserKey = "auth_user_id"
)

type contextKey int

const (
	ctxSession contextKey = iota + 1
	ctxUser
	ctxLocale
)

// Session is an in-memory view of a DB-backed Laravel-compatible session row.
type Session struct {
	ID           string
	UserID       sql.NullInt64
	IPAddress    string
	UserAgent    string
	Values       map[string]any
	LastActivity int64
	dirty        bool
	isNew        bool
}

func (s *Session) Get(key string) (any, bool) {
	v, ok := s.Values[key]
	return v, ok
}

func (s *Session) Set(key string, value any) {
	if s.Values == nil {
		s.Values = map[string]any{}
	}
	s.Values[key] = value
	s.dirty = true
}

func (s *Session) Forget(key string) {
	if s.Values == nil {
		return
	}
	delete(s.Values, key)
	s.dirty = true
}

func (s *Session) CSRFToken() string {
	if v, ok := s.Values[CSRFTokenKey].(string); ok && v != "" {
		return v
	}
	return ""
}

func (s *Session) EnsureCSRFToken() string {
	if t := s.CSRFToken(); t != "" {
		return t
	}
	t := randomHex(40)
	s.Set(CSRFTokenKey, t)
	return t
}

func (s *Session) AuthUserID() (int64, bool) {
	switch v := s.Values[SessionUserKey].(type) {
	case float64:
		return int64(v), true
	case int64:
		return v, true
	case int:
		return int64(v), true
	case json.Number:
		n, err := v.Int64()
		return n, err == nil
	default:
		return 0, false
	}
}

func (s *Session) SetAuthUserID(id int64) {
	s.Set(SessionUserKey, id)
	s.UserID = sql.NullInt64{Int64: id, Valid: true}
}

func (s *Session) ClearAuth() {
	s.Forget(SessionUserKey)
	s.UserID = sql.NullInt64{}
}

// Manager persists sessions in the Laravel `sessions` table.
type Manager struct {
	db     *sql.DB
	cfg    config.Config
	key    []byte
	cookie string
}

func NewManager(db *sql.DB, cfg config.Config) (*Manager, error) {
	key, err := ParseAppKey(cfg.AppKey)
	if err != nil {
		// Ephemeral key so the server can boot before APP_KEY is set.
		key = make([]byte, 32)
		_, _ = rand.Read(key)
	}
	name := cfg.SessionCookie
	if name == "" {
		name = "laravel_session"
	}
	return &Manager{db: db, cfg: cfg, key: key, cookie: name}, nil
}

func (m *Manager) CookieName() string { return m.cookie }

func (m *Manager) Key() []byte { return m.key }

func (m *Manager) Load(r *http.Request) (*Session, error) {
	id := m.readCookie(r)
	if id == "" {
		return m.newSession(r), nil
	}
	if m.db == nil {
		return m.newSession(r), nil
	}

	var (
		userID   sql.NullInt64
		ip       sql.NullString
		ua       sql.NullString
		payload  string
		activity int64
	)
	err := m.db.QueryRowContext(r.Context(),
		`SELECT user_id, ip_address, user_agent, payload, last_activity FROM sessions WHERE id = ?`, id,
	).Scan(&userID, &ip, &ua, &payload, &activity)
	if errors.Is(err, sql.ErrNoRows) {
		return m.newSession(r), nil
	}
	if err != nil {
		return nil, err
	}

	lifetime := time.Duration(m.cfg.SessionLifetimeMin) * time.Minute
	if lifetime <= 0 {
		lifetime = 120 * time.Minute
	}
	if time.Now().Unix()-activity > int64(lifetime.Seconds()) {
		_, _ = m.db.ExecContext(r.Context(), `DELETE FROM sessions WHERE id = ?`, id)
		return m.newSession(r), nil
	}

	values := map[string]any{}
	if payload != "" {
		_ = json.Unmarshal([]byte(payload), &values)
	}
	s := &Session{
		ID:           id,
		UserID:       userID,
		IPAddress:    ip.String,
		UserAgent:    ua.String,
		Values:       values,
		LastActivity: activity,
	}
	s.EnsureCSRFToken()
	return s, nil
}

func (m *Manager) Save(ctx context.Context, s *Session, r *http.Request) error {
	if s == nil || m.db == nil {
		return nil
	}
	s.LastActivity = time.Now().Unix()
	s.IPAddress = clientIP(r)
	s.UserAgent = truncate(r.UserAgent(), 500)
	if uid, ok := s.AuthUserID(); ok {
		s.UserID = sql.NullInt64{Int64: uid, Valid: true}
	}

	raw, err := json.Marshal(s.Values)
	if err != nil {
		return err
	}

	var user any
	if s.UserID.Valid {
		user = s.UserID.Int64
	} else {
		user = nil
	}

	_, err = m.db.ExecContext(ctx, `
INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity)
VALUES (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  user_id = VALUES(user_id),
  ip_address = VALUES(ip_address),
  user_agent = VALUES(user_agent),
  payload = VALUES(payload),
  last_activity = VALUES(last_activity)`,
		s.ID, user, nullIfEmpty(s.IPAddress), nullIfEmpty(s.UserAgent), string(raw), s.LastActivity,
	)
	s.dirty = false
	s.isNew = false
	return err
}

func (m *Manager) Destroy(ctx context.Context, s *Session) error {
	if s == nil {
		return nil
	}
	if m.db != nil && s.ID != "" {
		_, _ = m.db.ExecContext(ctx, `DELETE FROM sessions WHERE id = ?`, s.ID)
	}
	*s = *m.newSession(nil)
	return nil
}

func (m *Manager) Regenerate(ctx context.Context, s *Session) error {
	old := s.ID
	values := s.Values
	userID := s.UserID
	*s = Session{
		ID:     randomHex(40),
		UserID: userID,
		Values: values,
		isNew:  true,
		dirty:  true,
	}
	s.EnsureCSRFToken()
	if m.db != nil && old != "" {
		_, _ = m.db.ExecContext(ctx, `DELETE FROM sessions WHERE id = ?`, old)
	}
	return nil
}

func (m *Manager) newSession(r *http.Request) *Session {
	s := &Session{
		ID:     randomHex(40),
		Values: map[string]any{},
		isNew:  true,
		dirty:  true,
	}
	if r != nil {
		s.IPAddress = clientIP(r)
		s.UserAgent = truncate(r.UserAgent(), 500)
	}
	s.EnsureCSRFToken()
	return s
}

func (m *Manager) readCookie(r *http.Request) string {
	c, err := r.Cookie(m.cookie)
	if err != nil || c.Value == "" {
		return ""
	}
	return c.Value
}

func (m *Manager) WriteCookies(w http.ResponseWriter, r *http.Request, s *Session) {
	if s == nil {
		return
	}
	token := s.EnsureCSRFToken()
	maxAge := m.cfg.SessionLifetimeMin * 60
	if maxAge <= 0 {
		maxAge = 120 * 60
	}
	sameSite := http.SameSiteLaxMode
	switch strings.ToLower(m.cfg.SessionSameSite) {
	case "strict":
		sameSite = http.SameSiteStrictMode
	case "none":
		sameSite = http.SameSiteNoneMode
	case "lax", "":
		sameSite = http.SameSiteLaxMode
	}

	http.SetCookie(w, &http.Cookie{
		Name:     m.cookie,
		Value:    s.ID,
		Path:     "/",
		MaxAge:   maxAge,
		HttpOnly: true,
		Secure:   m.cfg.SessionSecure || r.TLS != nil || strings.EqualFold(r.Header.Get("X-Forwarded-Proto"), "https"),
		SameSite: sameSite,
	})
	http.SetCookie(w, &http.Cookie{
		Name:     "XSRF-TOKEN",
		Value:    token,
		Path:     "/",
		MaxAge:   maxAge,
		HttpOnly: false,
		Secure:   m.cfg.SessionSecure || r.TLS != nil || strings.EqualFold(r.Header.Get("X-Forwarded-Proto"), "https"),
		SameSite: sameSite,
	})
}

func (m *Manager) ExpireCookies(w http.ResponseWriter, r *http.Request) {
	secure := m.cfg.SessionSecure || r.TLS != nil || strings.EqualFold(r.Header.Get("X-Forwarded-Proto"), "https")
	http.SetCookie(w, &http.Cookie{Name: m.cookie, Value: "", Path: "/", MaxAge: -1, HttpOnly: true, Secure: secure})
	http.SetCookie(w, &http.Cookie{Name: "XSRF-TOKEN", Value: "", Path: "/", MaxAge: -1, Secure: secure})
}

func SessionFromContext(ctx context.Context) *Session {
	s, _ := ctx.Value(ctxSession).(*Session)
	return s
}

func WithSession(ctx context.Context, s *Session) context.Context {
	return context.WithValue(ctx, ctxSession, s)
}

func UserFromContext(ctx context.Context) *User {
	u, _ := ctx.Value(ctxUser).(*User)
	return u
}

func WithUser(ctx context.Context, u *User) context.Context {
	return context.WithValue(ctx, ctxUser, u)
}

func LocaleFromContext(ctx context.Context) string {
	if v, ok := ctx.Value(ctxLocale).(string); ok && v != "" {
		return v
	}
	return "en"
}

func WithLocale(ctx context.Context, locale string) context.Context {
	return context.WithValue(ctx, ctxLocale, locale)
}

func randomHex(nBytes int) string {
	b := make([]byte, nBytes)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func clientIP(r *http.Request) string {
	if r == nil {
		return "0.0.0.0"
	}
	if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
		parts := strings.Split(xff, ",")
		return strings.TrimSpace(parts[0])
	}
	host := r.RemoteAddr
	if i := strings.LastIndex(host, ":"); i > 0 {
		return host[:i]
	}
	return host
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

func nullIfEmpty(s string) any {
	if s == "" {
		return nil
	}
	return s
}

package auth

import (
	"context"
	"database/sql"
	"encoding/base64"
	"fmt"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/pquerna/otp/totp"
	"github.com/skip2/go-qrcode"
)

var spacesRE = regexp.MustCompile(`\s+`)

type User struct {
	ID                    int64
	Username              sql.NullString
	Name                  string
	Email                 string
	Password              string
	TwoFactorSecret       sql.NullString
	TwoFactorConfirmedAt  sql.NullTime
}

func (u *User) DisplayName() string {
	if u.Username.Valid && u.Username.String != "" {
		return u.Username.String
	}
	return u.Email
}

type UserStore struct {
	db *sql.DB
}

func NewUserStore(db *sql.DB) *UserStore {
	return &UserStore{db: db}
}

func (s *UserStore) FindByLogin(ctx context.Context, login string) (*User, error) {
	if s.db == nil {
		return nil, sql.ErrNoRows
	}
	u := &User{}
	err := s.db.QueryRowContext(ctx, `
SELECT id, username, name, email, password, two_factor_secret, two_factor_confirmed_at
FROM users WHERE username = ? OR email = ? LIMIT 1`, login, login).Scan(
		&u.ID, &u.Username, &u.Name, &u.Email, &u.Password, &u.TwoFactorSecret, &u.TwoFactorConfirmedAt,
	)
	if err != nil {
		return nil, err
	}
	return u, nil
}

func (s *UserStore) FindByID(ctx context.Context, id int64) (*User, error) {
	if s.db == nil {
		return nil, sql.ErrNoRows
	}
	u := &User{}
	err := s.db.QueryRowContext(ctx, `
SELECT id, username, name, email, password, two_factor_secret, two_factor_confirmed_at
FROM users WHERE id = ?`, id).Scan(
		&u.ID, &u.Username, &u.Name, &u.Email, &u.Password, &u.TwoFactorSecret, &u.TwoFactorConfirmedAt,
	)
	if err != nil {
		return nil, err
	}
	return u, nil
}

func (s *UserStore) UpdateTwoFactor(ctx context.Context, u *User) error {
	var confirmed any
	if u.TwoFactorConfirmedAt.Valid {
		confirmed = u.TwoFactorConfirmedAt.Time
	}
	var secret any
	if u.TwoFactorSecret.Valid {
		secret = u.TwoFactorSecret.String
	}
	_, err := s.db.ExecContext(ctx, `
UPDATE users SET two_factor_secret = ?, two_factor_confirmed_at = ?, updated_at = NOW() WHERE id = ?`,
		secret, confirmed, u.ID)
	return err
}

func (s *UserStore) FirstUsername(ctx context.Context) (string, error) {
	if s.db == nil {
		return "admin", nil
	}
	var username sql.NullString
	err := s.db.QueryRowContext(ctx, `SELECT username FROM users ORDER BY id ASC LIMIT 1`).Scan(&username)
	if err == sql.ErrNoRows {
		return "admin", nil
	}
	if err != nil {
		return "admin", err
	}
	if username.Valid && username.String != "" {
		return username.String, nil
	}
	return "admin", nil
}

type TwoFactorService struct {
	users *UserStore
	key   []byte
}

func NewTwoFactorService(users *UserStore, appKey []byte) *TwoFactorService {
	return &TwoFactorService{users: users, key: appKey}
}

func (s *TwoFactorService) IsEnabled(u *User) bool {
	return u != nil && u.TwoFactorConfirmedAt.Valid && u.TwoFactorSecret.Valid && u.TwoFactorSecret.String != ""
}

type SetupPayload struct {
	Secret     string `json:"secret"`
	OtpauthURI string `json:"otpauth_uri"`
	QR         string `json:"qr"`
}

func (s *TwoFactorService) BeginSetup(ctx context.Context, u *User) (SetupPayload, error) {
	key, err := totp.Generate(totp.GenerateOpts{
		Issuer:      "AWG-GUI",
		AccountName: u.DisplayName(),
		SecretSize:  20,
	})
	if err != nil {
		return SetupPayload{}, err
	}
	secret := key.Secret()
	enc, err := EncryptString(s.key, secret)
	if err != nil {
		return SetupPayload{}, err
	}
	u.TwoFactorSecret = sql.NullString{String: enc, Valid: true}
	u.TwoFactorConfirmedAt = sql.NullTime{}
	if err := s.users.UpdateTwoFactor(ctx, u); err != nil {
		return SetupPayload{}, err
	}
	uri := otpauthURI(u, secret)
	png, err := qrcode.Encode(uri, qrcode.Medium, 256)
	if err != nil {
		return SetupPayload{}, err
	}
	return SetupPayload{
		Secret:     secret,
		OtpauthURI: uri,
		QR:         "data:image/png;base64," + base64.StdEncoding.EncodeToString(png),
	}, nil
}

func (s *TwoFactorService) Confirm(ctx context.Context, u *User, code string) (bool, error) {
	secret, err := s.PlainSecret(u)
	if err != nil || secret == "" {
		return false, nil
	}
	if !s.VerifyCode(secret, code) {
		return false, nil
	}
	u.TwoFactorConfirmedAt = sql.NullTime{Time: time.Now(), Valid: true}
	if err := s.users.UpdateTwoFactor(ctx, u); err != nil {
		return false, err
	}
	return true, nil
}

func (s *TwoFactorService) Verify(u *User, code string) bool {
	if !s.IsEnabled(u) {
		return true
	}
	secret, err := s.PlainSecret(u)
	if err != nil || secret == "" || code == "" {
		return false
	}
	return s.VerifyCode(secret, code)
}

func (s *TwoFactorService) Disable(ctx context.Context, u *User) error {
	u.TwoFactorSecret = sql.NullString{}
	u.TwoFactorConfirmedAt = sql.NullTime{}
	return s.users.UpdateTwoFactor(ctx, u)
}

func (s *TwoFactorService) PlainSecret(u *User) (string, error) {
	if u == nil || !u.TwoFactorSecret.Valid || u.TwoFactorSecret.String == "" {
		return "", nil
	}
	plain, err := DecryptString(s.key, u.TwoFactorSecret.String)
	if err != nil {
		return "", err
	}
	return plain, nil
}

func (s *TwoFactorService) VerifyCode(secret, code string) bool {
	normalized := spacesRE.ReplaceAllString(code, "")
	if matched, _ := regexp.MatchString(`^\d{6}$`, normalized); !matched {
		return false
	}
	return totp.Validate(normalized, secret)
}

func otpauthURI(u *User, secret string) string {
	issuer := url.QueryEscape("AWG-GUI")
	label := url.PathEscape("AWG-GUI:" + u.DisplayName())
	return fmt.Sprintf("otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30",
		label, secret, issuer)
}

func PendingTwoFactor(u *User) bool {
	return u != nil && u.TwoFactorSecret.Valid && u.TwoFactorSecret.String != "" && !u.TwoFactorConfirmedAt.Valid
}

// SettingGet reads a settings KV value.
func SettingGet(ctx context.Context, db *sql.DB, key, fallback string) string {
	if db == nil {
		return fallback
	}
	var value sql.NullString
	err := db.QueryRowContext(ctx, `SELECT value FROM settings WHERE `+"`key`"+` = ?`, key).Scan(&value)
	if err != nil || !value.Valid || value.String == "" {
		return fallback
	}
	return value.String
}

func ParseBoolish(v string) bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

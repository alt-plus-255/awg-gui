package auth

import (
	"strings"

	"golang.org/x/crypto/bcrypt"
)

// HashPassword returns a bcrypt hash accepted by Laravel ($2a$ / $2y$).
func HashPassword(password string) (string, error) {
	b, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return "", err
	}
	return string(b), nil
}

// CheckPassword verifies a password against a Laravel/PHP bcrypt hash.
func CheckPassword(hash, password string) bool {
	if hash == "" || password == "" {
		return false
	}
	h := hash
	if strings.HasPrefix(h, "$2y$") {
		h = "$2a$" + h[4:]
	}
	return bcrypt.CompareHashAndPassword([]byte(h), []byte(password)) == nil
}

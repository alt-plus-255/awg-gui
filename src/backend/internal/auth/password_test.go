package auth

import (
	"strings"
	"testing"
)

func TestCheckPasswordAcceptsLaravel2yPrefix(t *testing.T) {
	// Laravel UserFactory default hash for "password" (PHP bcrypt $2y$).
	const laravelHash = "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"
	if !strings.HasPrefix(laravelHash, "$2y$") {
		t.Fatal("fixture must be a $2y$ hash")
	}
	if !CheckPassword(laravelHash, "password") {
		t.Fatal("CheckPassword must accept Laravel/PHP $2y$ bcrypt hashes")
	}
	if CheckPassword(laravelHash, "wrong") {
		t.Fatal("wrong password must not verify")
	}
}

func TestHashPasswordRoundTrip(t *testing.T) {
	hash, err := HashPassword("secret")
	if err != nil {
		t.Fatal(err)
	}
	if !CheckPassword(hash, "secret") {
		t.Fatalf("round-trip failed for hash %q", hash)
	}
	yHash := "$2y$" + hash[4:]
	if !CheckPassword(yHash, "secret") {
		t.Fatalf("CheckPassword must treat $2y$ like $2a$: %q", yHash)
	}
}

func TestCheckPasswordRejectsEmpty(t *testing.T) {
	if CheckPassword("", "x") || CheckPassword("x", "") {
		t.Fatal("empty hash or password must fail")
	}
}

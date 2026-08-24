package auth

import (
	"context"
	"testing"
)

func TestLoginProtectionThresholds(t *testing.T) {
	if CaptchaAfter != 5 {
		t.Fatalf("CaptchaAfter (CAPTCHA_AFTER) = %d, want 5", CaptchaAfter)
	}
	if LockAfter != 10 {
		t.Fatalf("LockAfter (LOCK_AFTER) = %d, want 10", LockAfter)
	}
	if BaseLockMinutes != 30 {
		t.Fatalf("BaseLockMinutes = %d, want 30", BaseLockMinutes)
	}
	if LockStepMinutes != 15 {
		t.Fatalf("LockStepMinutes = %d, want 15", LockStepMinutes)
	}
}

func TestLockDurationMinutes(t *testing.T) {
	s := NewLoginProtectionService(nil)
	cases := []struct {
		lockouts int
		want     int
	}{
		{0, 30},
		{1, 45},
		{2, 60},
		{-1, 30},
	}
	for _, tc := range cases {
		if got := s.LockDurationMinutes(tc.lockouts); got != tc.want {
			t.Fatalf("LockDurationMinutes(%d) = %d, want %d", tc.lockouts, got, tc.want)
		}
	}
}

func TestStatusUnlockedWithoutAttempts(t *testing.T) {
	s := NewLoginProtectionService(nil)
	st, err := s.Status(context.Background(), "127.0.0.1")
	if err != nil {
		t.Fatal(err)
	}
	if st.CaptchaRequired || st.Locked || st.Attempts != 0 {
		t.Fatalf("fresh IP should be unlocked without captcha: %+v", st)
	}
	if st.LockDurationMinutes != BaseLockMinutes {
		t.Fatalf("LockDurationMinutes = %d, want %d", st.LockDurationMinutes, BaseLockMinutes)
	}
}

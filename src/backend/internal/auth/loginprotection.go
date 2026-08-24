package auth

import (
	"context"
	"database/sql"
	"math"
	"time"
)

const (
	CaptchaAfter     = 5
	LockAfter        = 10
	BaseLockMinutes  = 30
	LockStepMinutes  = 15
)

type LoginProtectionService struct {
	db *sql.DB
}

func NewLoginProtectionService(db *sql.DB) *LoginProtectionService {
	return &LoginProtectionService{db: db}
}

type ProtectionStatus struct {
	Attempts             int     `json:"attempts"`
	CaptchaRequired      bool    `json:"captcha_required"`
	Locked               bool    `json:"locked"`
	LockedUntil          *string `json:"locked_until"`
	RemainingSeconds     int     `json:"remaining_seconds"`
	LockDurationMinutes  int     `json:"lock_duration_minutes"`
	LockoutCount         int     `json:"lockout_count"`
}

type protectionRow struct {
	ID           int64
	IP           string
	Attempts     int
	LockoutCount int
	LockedUntil  sql.NullTime
}

func (s *LoginProtectionService) forIP(ctx context.Context, ip string) (*protectionRow, error) {
	if s.db == nil {
		return &protectionRow{IP: ip}, nil
	}
	row := &protectionRow{IP: ip}
	err := s.db.QueryRowContext(ctx, `
SELECT id, ip, attempts, lockout_count, locked_until
FROM login_protections WHERE ip = ?`, ip).Scan(
		&row.ID, &row.IP, &row.Attempts, &row.LockoutCount, &row.LockedUntil,
	)
	if err == sql.ErrNoRows {
		res, err := s.db.ExecContext(ctx, `
INSERT INTO login_protections (ip, attempts, lockout_count, locked_until, created_at, updated_at)
VALUES (?, 0, 0, NULL, NOW(), NOW())`, ip)
		if err != nil {
			// race: fetch again
			err2 := s.db.QueryRowContext(ctx, `
SELECT id, ip, attempts, lockout_count, locked_until
FROM login_protections WHERE ip = ?`, ip).Scan(
				&row.ID, &row.IP, &row.Attempts, &row.LockoutCount, &row.LockedUntil,
			)
			return row, err2
		}
		id, _ := res.LastInsertId()
		row.ID = id
		return row, nil
	}
	return row, err
}

func (s *LoginProtectionService) Status(ctx context.Context, ip string) (ProtectionStatus, error) {
	row, err := s.forIP(ctx, ip)
	if err != nil {
		return ProtectionStatus{}, err
	}
	s.expireLockIfNeeded(ctx, row)

	remaining := remainingSeconds(row.LockedUntil)
	locked := remaining > 0
	lockout := row.LockoutCount
	durCount := lockout
	if locked {
		durCount = max(0, lockout-1)
	}

	var lockedUntil *string
	if row.LockedUntil.Valid && remaining > 0 {
		v := row.LockedUntil.Time.UTC().Format(time.RFC3339)
		lockedUntil = &v
	}

	return ProtectionStatus{
		Attempts:            row.Attempts,
		CaptchaRequired:     !locked && row.Attempts >= CaptchaAfter,
		Locked:              locked,
		LockedUntil:         lockedUntil,
		RemainingSeconds:    remaining,
		LockDurationMinutes: s.LockDurationMinutes(durCount),
		LockoutCount:        lockout,
	}, nil
}

func (s *LoginProtectionService) IsLocked(ctx context.Context, ip string) (bool, error) {
	st, err := s.Status(ctx, ip)
	return st.Locked, err
}

func (s *LoginProtectionService) RequiresCaptcha(ctx context.Context, ip string) (bool, error) {
	st, err := s.Status(ctx, ip)
	return st.CaptchaRequired, err
}

func (s *LoginProtectionService) LockDurationMinutes(lockoutCount int) int {
	if lockoutCount < 0 {
		lockoutCount = 0
	}
	return BaseLockMinutes + lockoutCount*LockStepMinutes
}

func (s *LoginProtectionService) RecordFailedPassword(ctx context.Context, ip string) (ProtectionStatus, error) {
	row, err := s.forIP(ctx, ip)
	if err != nil {
		return ProtectionStatus{}, err
	}
	s.expireLockIfNeeded(ctx, row)
	if remainingSeconds(row.LockedUntil) > 0 {
		return s.Status(ctx, ip)
	}

	row.Attempts++
	if row.Attempts >= LockAfter {
		minutes := s.LockDurationMinutes(row.LockoutCount)
		until := time.Now().Add(time.Duration(minutes) * time.Minute)
		row.LockedUntil = sql.NullTime{Time: until, Valid: true}
		row.LockoutCount++
		row.Attempts = 0
	}

	if s.db != nil {
		var locked any
		if row.LockedUntil.Valid {
			locked = row.LockedUntil.Time
		}
		_, err = s.db.ExecContext(ctx, `
UPDATE login_protections
SET attempts = ?, lockout_count = ?, locked_until = ?, updated_at = NOW()
WHERE id = ?`, row.Attempts, row.LockoutCount, locked, row.ID)
		if err != nil {
			return ProtectionStatus{}, err
		}
	}
	return s.Status(ctx, ip)
}

func (s *LoginProtectionService) Clear(ctx context.Context, ip string) error {
	if s.db == nil {
		return nil
	}
	_, err := s.db.ExecContext(ctx, `DELETE FROM login_protections WHERE ip = ?`, ip)
	return err
}

func (s *LoginProtectionService) ClearAll(ctx context.Context) error {
	if s.db == nil {
		return nil
	}
	_, err := s.db.ExecContext(ctx, `DELETE FROM login_protections`)
	return err
}

func (s *LoginProtectionService) expireLockIfNeeded(ctx context.Context, row *protectionRow) {
	if row.LockedUntil.Valid && row.LockedUntil.Time.Before(time.Now()) {
		row.LockedUntil = sql.NullTime{}
		if s.db != nil && row.ID > 0 {
			_, _ = s.db.ExecContext(ctx, `
UPDATE login_protections SET locked_until = NULL, updated_at = NOW() WHERE id = ?`, row.ID)
		}
	}
}

func remainingSeconds(lockedUntil sql.NullTime) int {
	if !lockedUntil.Valid || lockedUntil.Time.Before(time.Now()) {
		return 0
	}
	sec := time.Until(lockedUntil.Time).Seconds()
	return int(math.Max(0, math.Ceil(sec)))
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}

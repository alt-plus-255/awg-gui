package settings

import (
	"context"
	"database/sql"
	"encoding/json"
	"sync"
	"time"
)

const cacheTTL = 30 * time.Second

type Store struct {
	db *sql.DB

	mu    sync.Mutex
	all   map[string]string
	until time.Time
}

func New(db *sql.DB) *Store {
	return &Store{db: db}
}

func (s *Store) Get(ctx context.Context, key string) (string, bool) {
	all, err := s.AllKeyed(ctx)
	if err != nil {
		return "", false
	}
	v, ok := all[key]
	return v, ok
}

func (s *Store) GetValue(ctx context.Context, key, fallback string) string {
	if v, ok := s.Get(ctx, key); ok {
		return v
	}
	return fallback
}

func (s *Store) Set(ctx context.Context, key string, value any) error {
	str := stringify(value)
	_, err := s.db.ExecContext(ctx, `
INSERT INTO settings (`+"`key`"+`, value, created_at, updated_at)
VALUES (?, ?, NOW(), NOW())
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()`, key, str)
	s.Invalidate()
	return err
}

func (s *Store) AllKeyed(ctx context.Context) (map[string]string, error) {
	s.mu.Lock()
	if s.all != nil && time.Now().Before(s.until) {
		cp := copyMap(s.all)
		s.mu.Unlock()
		return cp, nil
	}
	s.mu.Unlock()

	rows, err := s.db.QueryContext(ctx, `SELECT `+"`key`"+`, value FROM settings`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := map[string]string{}
	for rows.Next() {
		var k string
		var v sql.NullString
		if err := rows.Scan(&k, &v); err != nil {
			return nil, err
		}
		if v.Valid {
			out[k] = v.String
		} else {
			out[k] = ""
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	s.mu.Lock()
	s.all = out
	s.until = time.Now().Add(cacheTTL)
	s.mu.Unlock()
	return copyMap(out), nil
}

func (s *Store) Invalidate() {
	s.mu.Lock()
	s.all = nil
	s.until = time.Time{}
	s.mu.Unlock()
}

func stringify(value any) string {
	switch v := value.(type) {
	case string:
		return v
	case []byte:
		return string(v)
	case nil:
		return ""
	default:
		if b, err := json.Marshal(v); err == nil {
			return string(b)
		}
		return ""
	}
}

func copyMap(in map[string]string) map[string]string {
	out := make(map[string]string, len(in))
	for k, v := range in {
		out[k] = v
	}
	return out
}

func AsBool(v string) bool {
	switch v {
	case "1", "true", "TRUE", "yes", "on":
		return true
	default:
		return false
	}
}

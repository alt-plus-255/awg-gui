package resolver

import (
	"context"
	"database/sql"
	"encoding/json"
	"sync"
	"time"
)

type KV struct {
	DB *sql.DB
	mu sync.Mutex
}

func (k *KV) Get(ctx context.Context, key, fallback string) string {
	if k == nil || k.DB == nil {
		return fallback
	}
	var v sql.NullString
	err := k.DB.QueryRowContext(ctx, `SELECT value FROM settings WHERE `+"`key`"+` = ? LIMIT 1`, key).Scan(&v)
	if err != nil || !v.Valid {
		return fallback
	}
	return v.String
}

func (k *KV) Set(ctx context.Context, key, value string) error {
	if k == nil || k.DB == nil {
		return nil
	}
	_, err := k.DB.ExecContext(ctx, `
INSERT INTO settings (`+"`key`"+`, value, created_at, updated_at)
VALUES (?, ?, NOW(), NOW())
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()`, key, value)
	return err
}

func (k *KV) GetJSON(ctx context.Context, key string, dest any) {
	raw := k.Get(ctx, key, "")
	if raw == "" {
		return
	}
	_ = json.Unmarshal([]byte(raw), dest)
}

func (k *KV) SetJSON(ctx context.Context, key string, v any) error {
	b, err := json.Marshal(v)
	if err != nil {
		return err
	}
	return k.Set(ctx, key, string(b))
}

// Memory cache for ping sessions / speed-test jobs (same process as HTTP + cron).
type MemCache struct {
	mu   sync.Mutex
	data map[string]cacheEntry
}

type cacheEntry struct {
	v   any
	exp time.Time
}

func NewMemCache() *MemCache {
	return &MemCache{data: map[string]cacheEntry{}}
}

func (c *MemCache) Put(key string, v any, ttl time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.data[key] = cacheEntry{v: v, exp: time.Now().Add(ttl)}
}

func (c *MemCache) Get(key string) (any, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	e, ok := c.data[key]
	if !ok {
		return nil, false
	}
	if time.Now().After(e.exp) {
		delete(c.data, key)
		return nil, false
	}
	return e.v, true
}

func (c *MemCache) Forget(key string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	delete(c.data, key)
}

func (c *MemCache) Has(key string) bool {
	_, ok := c.Get(key)
	return ok
}

func (c *MemCache) Pull(key string) (any, bool) {
	c.mu.Lock()
	defer c.mu.Unlock()
	e, ok := c.data[key]
	if !ok {
		return nil, false
	}
	delete(c.data, key)
	if time.Now().After(e.exp) {
		return nil, false
	}
	return e.v, true
}

func (c *MemCache) TryPut(key string, v any, ttl time.Duration) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if e, ok := c.data[key]; ok && time.Now().Before(e.exp) {
		return false
	}
	c.data[key] = cacheEntry{v: v, exp: time.Now().Add(ttl)}
	return true
}

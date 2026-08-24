package store

import (
	"context"
	"database/sql"
	"strconv"
	"sync"
	"time"
)

// Cache is a Laravel-compatible row in the `cache` table, with an in-memory fallback.
type Cache struct {
	DB *sql.DB

	mu   sync.Mutex
	mem  map[string]memEntry
}

type memEntry struct {
	value string
	exp   int64
}

func NewCache(db *sql.DB) *Cache {
	return &Cache{DB: db, mem: map[string]memEntry{}}
}

func (c *Cache) Add(ctx context.Context, key, value string, ttl time.Duration) bool {
	exp := time.Now().Add(ttl).Unix()
	if c.DB != nil {
		_, _ = c.DB.ExecContext(ctx, `DELETE FROM `+"`cache`"+` WHERE `+"`key`"+` = ? AND expiration < ?`, key, time.Now().Unix())
		_, err := c.DB.ExecContext(ctx, `INSERT INTO `+"`cache`"+` (`+"`key`"+`, value, expiration) VALUES (?, ?, ?)`,
			key, value, exp)
		if err == nil {
			return true
		}
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	if e, ok := c.mem[key]; ok && e.exp >= time.Now().Unix() {
		return false
	}
	c.mem[key] = memEntry{value: value, exp: exp}
	return true
}

func (c *Cache) Forget(ctx context.Context, key string) {
	if c.DB != nil {
		_, _ = c.DB.ExecContext(ctx, `DELETE FROM `+"`cache`"+` WHERE `+"`key`"+` = ?`, key)
	}
	c.mu.Lock()
	delete(c.mem, key)
	c.mu.Unlock()
}

func (c *Cache) Has(ctx context.Context, key string) bool {
	if c.DB != nil {
		var exp int64
		err := c.DB.QueryRowContext(ctx, `SELECT expiration FROM `+"`cache`"+` WHERE `+"`key`"+` = ?`, key).Scan(&exp)
		if err == nil && exp >= time.Now().Unix() {
			return true
		}
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	e, ok := c.mem[key]
	return ok && e.exp >= time.Now().Unix()
}

func (c *Cache) Get(ctx context.Context, key string) (string, bool) {
	now := time.Now().Unix()
	if c.DB != nil {
		var val string
		var exp int64
		err := c.DB.QueryRowContext(ctx, `SELECT value, expiration FROM `+"`cache`"+` WHERE `+"`key`"+` = ?`, key).Scan(&val, &exp)
		if err == nil && exp >= now {
			return val, true
		}
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	e, ok := c.mem[key]
	if !ok || e.exp < now {
		return "", false
	}
	return e.value, true
}

func (c *Cache) PutForever(ctx context.Context, key, value string) {
	c.Put(ctx, key, value, 10*365*24*time.Hour)
}

func (c *Cache) Put(ctx context.Context, key, value string, ttl time.Duration) {
	exp := time.Now().Add(ttl).Unix()
	if c.DB != nil {
		_, err := c.DB.ExecContext(ctx, `
INSERT INTO `+"`cache`"+` (`+"`key`"+`, value, expiration) VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE value = VALUES(value), expiration = VALUES(expiration)`,
			key, value, exp)
		if err == nil {
			return
		}
	}
	c.mu.Lock()
	c.mem[key] = memEntry{value: value, exp: exp}
	c.mu.Unlock()
}

func UnixString(t time.Time) string {
	return strconv.FormatInt(t.Unix(), 10)
}

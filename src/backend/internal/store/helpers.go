package store

import (
	"database/sql"
	"encoding/json"
	"time"
)

func scanJSONSlice[T any](raw []byte) []T {
	if len(raw) == 0 || string(raw) == "null" {
		return []T{}
	}
	var v []T
	if err := json.Unmarshal(raw, &v); err != nil || v == nil {
		return []T{}
	}
	return v
}

func marshalJSON(v any) []byte {
	if v == nil {
		return []byte("[]")
	}
	b, err := json.Marshal(v)
	if err != nil {
		return []byte("[]")
	}
	return b
}

func nullString(s *string) sql.NullString {
	if s == nil || *s == "" {
		return sql.NullString{}
	}
	return sql.NullString{String: *s, Valid: true}
}

func nullInt64(v *int64) sql.NullInt64 {
	if v == nil {
		return sql.NullInt64{}
	}
	return sql.NullInt64{Int64: *v, Valid: true}
}

func nullInt(v *int) sql.NullInt64 {
	if v == nil {
		return sql.NullInt64{}
	}
	return sql.NullInt64{Int64: int64(*v), Valid: true}
}

func nullTime(t *time.Time) sql.NullTime {
	if t == nil {
		return sql.NullTime{}
	}
	return sql.NullTime{Time: *t, Valid: true}
}

func ptrString(ns sql.NullString) *string {
	if !ns.Valid {
		return nil
	}
	s := ns.String
	return &s
}

func ptrInt64(n sql.NullInt64) *int64 {
	if !n.Valid {
		return nil
	}
	v := n.Int64
	return &v
}

func ptrInt(n sql.NullInt64) *int {
	if !n.Valid {
		return nil
	}
	v := int(n.Int64)
	return &v
}

func ptrTime(n sql.NullTime) *time.Time {
	if !n.Valid {
		return nil
	}
	t := n.Time
	return &t
}

func ptrBool(n sql.NullBool) *bool {
	if !n.Valid {
		return nil
	}
	v := n.Bool
	return &v
}

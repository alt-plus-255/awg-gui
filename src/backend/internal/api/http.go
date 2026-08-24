package api

import (
	"encoding/json"
	"net"
	"net/http"
	"strconv"
	"strings"

	"github.com/awggui/backend/internal/auth"
	"github.com/awggui/backend/internal/i18n"
	"github.com/awggui/backend/internal/resolver"
	"github.com/go-chi/chi/v5"
)

func writeJSON(w http.ResponseWriter, status int, v any) {
	auth.WriteJSON(w, status, v)
}

func writeValidation(w http.ResponseWriter, r *http.Request, field, key string, vars map[string]string) {
	locale := auth.LocaleFromContext(r.Context())
	msg := i18n.Tf(locale, key, vars)
	writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
		"message": msg,
		"errors":  map[string]any{field: []string{msg}},
	})
}

func writeMessage(w http.ResponseWriter, r *http.Request, status int, key string, vars map[string]string) {
	locale := auth.LocaleFromContext(r.Context())
	writeJSON(w, status, map[string]any{"message": i18n.Tf(locale, key, vars)})
}

func writeNotFound(w http.ResponseWriter, r *http.Request) {
	writeMessage(w, r, http.StatusNotFound, "api.http_404", nil)
}

func write422(w http.ResponseWriter, r *http.Request) {
	writeMessage(w, r, http.StatusUnprocessableEntity, "api.http_422", nil)
}

func writeText(w http.ResponseWriter, body, contentType, disposition string) {
	w.Header().Set("Content-Type", contentType)
	if disposition != "" {
		w.Header().Set("Content-Disposition", disposition)
	}
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte(body))
}

func writeBytes(w http.ResponseWriter, body []byte, contentType string) {
	w.Header().Set("Content-Type", contentType)
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(body)
}

func pathID(r *http.Request, name string) (int64, bool) {
	n, err := strconv.ParseInt(chi.URLParam(r, name), 10, 64)
	return n, err == nil && n > 0
}

func requestHost(r *http.Request) string {
	host := r.Host
	if h, _, err := net.SplitHostPort(host); err == nil {
		return h
	}
	return host
}

func decodeJSON(r *http.Request, dst any) error {
	dec := json.NewDecoder(r.Body)
	return dec.Decode(dst)
}

func asString(v any) string {
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return strconv.FormatInt(int64(t), 10)
	case json.Number:
		return t.String()
	case bool:
		if t {
			return "1"
		}
		return "0"
	default:
		return ""
	}
}

func asBool(v any) (bool, bool) {
	switch t := v.(type) {
	case bool:
		return t, true
	case string:
		s := strings.ToLower(strings.TrimSpace(t))
		if s == "1" || s == "true" || s == "yes" || s == "on" {
			return true, true
		}
		if s == "0" || s == "false" || s == "no" || s == "off" {
			return false, true
		}
	case float64:
		return t != 0, true
	}
	return false, false
}

func asInt(v any) (int, bool) {
	switch t := v.(type) {
	case float64:
		return int(t), true
	case json.Number:
		n, err := t.Int64()
		return int(n), err == nil
	case string:
		n, err := strconv.Atoi(strings.TrimSpace(t))
		return n, err == nil
	}
	return 0, false
}

func asInt64(v any) (int64, bool) {
	switch t := v.(type) {
	case float64:
		return int64(t), true
	case json.Number:
		n, err := t.Int64()
		return n, err == nil
	case string:
		n, err := strconv.ParseInt(strings.TrimSpace(t), 10, 64)
		return n, err == nil
	}
	return 0, false
}

func asStringSlice(v any) []string {
	switch t := v.(type) {
	case []string:
		return t
	case []any:
		out := make([]string, 0, len(t))
		for _, x := range t {
			s := strings.TrimSpace(asString(x))
			if s != "" {
				out = append(out, s)
			}
		}
		return out
	case string:
		s := strings.TrimSpace(t)
		if s == "" {
			return nil
		}
		return []string{s}
	default:
		return nil
	}
}

func writeResolverErr(w http.ResponseWriter, r *http.Request, err error) {
	locale := auth.LocaleFromContext(r.Context())
	if ve, ok := err.(*resolver.ValidationError); ok {
		status := ve.Status
		if status == 0 {
			status = http.StatusUnprocessableEntity
		}
		msg := ve.Translate(locale)
		writeJSON(w, status, map[string]any{
			"message": msg,
			"errors":  map[string]any{ve.Field: []string{msg}},
		})
		return
	}
	if he, ok := err.(*resolver.HTTPError); ok {
		body := map[string]any{"error": he.Message}
		if he.Code != "" {
			body["code"] = he.Code
		}
		for k, v := range he.Extra {
			body[k] = v
		}
		writeJSON(w, he.Status, body)
		return
	}
	msg := resolver.TranslateErr(locale, err)
	writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
		"message": msg,
		"errors":  map[string]any{"resolver": []string{msg}},
	})
}

func NotImplemented(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusNotImplemented, map[string]any{
		"ok":      false,
		"message": "Not implemented in this backend phase",
		"error":   "not_implemented",
	})
}

package resolver

import (
	"context"
	"fmt"
	"net/http"

	"github.com/awggui/backend/internal/i18n"
)

type localeKey struct{}

func WithLocale(ctx context.Context, locale string) context.Context {
	return context.WithValue(ctx, localeKey{}, locale)
}

func Locale(ctx context.Context) string {
	if ctx == nil {
		return "en"
	}
	if v, ok := ctx.Value(localeKey{}).(string); ok && v != "" {
		return v
	}
	return "en"
}

type ValidationError struct {
	Field  string
	Key    string
	Params map[string]string
	Status int
}

func (e *ValidationError) Error() string {
	return i18n.Tf("en", e.Key, e.Params)
}

func (e *ValidationError) Translate(locale string) string {
	return i18n.Tf(locale, e.Key, e.Params)
}

func FieldErr(field, key string, params map[string]string) *ValidationError {
	return &ValidationError{Field: field, Key: key, Params: params, Status: http.StatusUnprocessableEntity}
}

type HTTPError struct {
	Status  int
	Message string
	Code    string
	Extra   map[string]any
}

func (e *HTTPError) Error() string {
	if e.Message != "" {
		return e.Message
	}
	return fmt.Sprintf("http %d", e.Status)
}

func BusyErr(locale string) *HTTPError {
	return &HTTPError{
		Status:  http.StatusConflict,
		Message: i18n.T(locale, "resolver.ping_already_running"),
		Code:    "ping_busy",
	}
}

package telegram

import (
	"crypto/subtle"
	"encoding/json"
	"io"
	"log"
	"net/http"
)

func (s *Service) HandleWebhook(w http.ResponseWriter, r *http.Request, secret string) {
	ctx := r.Context()
	expected := s.Settings.WebhookSecret(ctx)
	if expected == "" || subtle.ConstantTimeCompare([]byte(expected), []byte(secret)) != 1 {
		writeJSONStatus(w, http.StatusNotFound, map[string]any{"message": "Not found"})
		return
	}
	headerToken := r.Header.Get("X-Telegram-Bot-Api-Secret-Token")
	if headerToken == "" || subtle.ConstantTimeCompare([]byte(expected), []byte(headerToken)) != 1 {
		writeJSONStatus(w, http.StatusNotFound, map[string]any{"message": "Not found"})
		return
	}
	if s.Settings.Mode(ctx) != ModeWebhook {
		writeJSONStatus(w, http.StatusOK, map[string]any{"ok": true, "ignored": "mode"})
		return
	}
	if !s.Settings.IsConfigured(ctx) {
		writeJSONStatus(w, http.StatusOK, map[string]any{"ok": true, "ignored": "not_configured"})
		return
	}
	raw, err := io.ReadAll(r.Body)
	if err != nil {
		writeJSONStatus(w, http.StatusBadRequest, map[string]any{"ok": false})
		return
	}
	update, err := decodeUpdate(raw)
	if err != nil || len(update) == 0 {
		writeJSONStatus(w, http.StatusBadRequest, map[string]any{"ok": false})
		return
	}
	func() {
		defer func() {
			if rec := recover(); rec != nil {
				log.Printf("telegram.webhook_failed: %v", rec)
			}
		}()
		s.Router.Handle(ctx, update)
	}()
	writeJSONStatus(w, http.StatusOK, map[string]any{"ok": true})
}

func writeJSONStatus(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

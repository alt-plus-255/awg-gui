package httpapi

import (
	"crypto/hmac"
	"encoding/json"
	"io"
	"net/http"
	"strings"

	"github.com/awggui/panel-ops/internal/ops"
)

func NewHandler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", handleHealth)
	mux.HandleFunc("GET /ops/awg-kernel/status", requireAuth(handleKernelStatus))
	mux.HandleFunc("POST /ops/caddy/recreate", requireAuth(handleCaddyRecreate))
	mux.HandleFunc("POST /ops/update/start", requireAuth(handleUpdateStart))
	mux.HandleFunc("POST /ops/update/clear-log", requireAuth(handleClearUpdateLog))
	mux.HandleFunc("POST /ops/awg-kernel/install", requireAuth(handleKernelInstall))
	mux.HandleFunc("POST /ops/awg-kernel/uninstall", requireAuth(handleKernelUninstall))
	mux.HandleFunc("POST /ops/awg-kernel/recover", requireAuth(handleKernelRecover))
	mux.HandleFunc("GET /ops/host-debug", requireAuth(handleHostDebug))
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h, pattern := mux.Handler(r)
		if pattern == "" {
			handleNotFound(w, r)
			return
		}
		h.ServeHTTP(w, r)
	})
}

func requireAuth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		expected := strings.TrimSpace(ops.Env("PANEL_OPS_TOKEN", ""))
		if expected == "" {
			writeJSON(w, http.StatusServiceUnavailable, map[string]any{"ok": false, "error": "PANEL_OPS_TOKEN is not configured"})
			return
		}
		provided := bearerToken(r)
		if provided == "" || !hmac.Equal([]byte(provided), []byte(expected)) {
			writeJSON(w, http.StatusUnauthorized, map[string]any{"ok": false, "error": "Unauthorized"})
			return
		}
		next(w, r)
	}
}

func bearerToken(r *http.Request) string {
	h := r.Header.Get("Authorization")
	if h == "" || !strings.HasPrefix(h, "Bearer ") {
		return ""
	}
	return strings.TrimSpace(strings.TrimPrefix(h, "Bearer "))
}

func handleNotFound(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet && r.Method != http.MethodPost {
		writeJSON(w, http.StatusMethodNotAllowed, map[string]any{"ok": false, "error": "Method not allowed"})
		return
	}
	writeJSON(w, http.StatusNotFound, map[string]any{"ok": false, "error": "Not found"})
}

func handleHealth(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func handleKernelStatus(w http.ResponseWriter, _ *http.Request) {
	result := ops.AWGKernelStatus()
	status := http.StatusOK
	if s, ok := result["status"].(int); ok {
		status = s
	}
	writeJSON(w, status, result)
}

func handleCaddyRecreate(w http.ResponseWriter, _ *http.Request) {
	result := ops.RecreateCaddy()
	if ops.AsBool(result["ok"]) {
		writeJSON(w, http.StatusOK, result)
		return
	}
	writeJSON(w, http.StatusInternalServerError, result)
}

func handleUpdateStart(w http.ResponseWriter, r *http.Request) {
	var payload struct {
		Version string `json:"version"`
	}
	body, _ := io.ReadAll(io.LimitReader(r.Body, 1<<20))
	_ = json.Unmarshal(body, &payload)
	var version *string
	v := strings.TrimSpace(payload.Version)
	if v != "" {
		version = &v
	}
	result := ops.StartUpdate(version)
	status := http.StatusAccepted
	if s, ok := result["status"].(int); ok {
		status = s
	}
	writeJSON(w, status, result)
}

func handleClearUpdateLog(w http.ResponseWriter, _ *http.Request) {
	result := ops.ClearUpdateLog()
	status := http.StatusOK
	if s, ok := result["status"].(int); ok {
		status = s
	}
	writeJSON(w, status, result)
}

func handleKernelInstall(w http.ResponseWriter, _ *http.Request) {
	result := ops.StartAWGKernelOp("install")
	status := http.StatusAccepted
	if s, ok := result["status"].(int); ok {
		status = s
	}
	writeJSON(w, status, result)
}

func handleKernelUninstall(w http.ResponseWriter, _ *http.Request) {
	result := ops.StartAWGKernelOp("uninstall")
	status := http.StatusAccepted
	if s, ok := result["status"].(int); ok {
		status = s
	}
	writeJSON(w, status, result)
}

func handleKernelRecover(w http.ResponseWriter, _ *http.Request) {
	result := ops.StartAWGKernelOp("recover")
	status := http.StatusAccepted
	if s, ok := result["status"].(int); ok {
		status = s
	}
	writeJSON(w, status, result)
}

func handleHostDebug(w http.ResponseWriter, _ *http.Request) {
	result := ops.CollectHostDebug()
	writeJSON(w, http.StatusOK, result)
}

func writeJSON(w http.ResponseWriter, status int, body map[string]any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(body)
}

package ops

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

func Env(key, fallback string) string {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return fallback
	}
	return v
}

func IsoNow() string {
	return time.Now().UTC().Format("2006-01-02T15:04:05Z")
}

func ShellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'"'"'`) + "'"
}

func ReadJSONMap(path string) map[string]any {
	raw, err := os.ReadFile(path)
	if err != nil {
		return map[string]any{}
	}
	var out map[string]any
	if err := json.Unmarshal(raw, &out); err != nil || out == nil {
		return map[string]any{}
	}
	return out
}

func WriteJSONMap(path string, state map[string]any) {
	raw, err := json.MarshalIndent(state, "", "    ")
	if err != nil {
		return
	}
	_ = os.WriteFile(path, append(raw, '\n'), 0o644)
}

func AppendLog(path, line string) {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o666)
	if err != nil {
		return
	}
	defer f.Close()
	_, _ = f.WriteString(line)
	_ = os.Chmod(path, 0o666)
}

func TruncateLog(path string) error {
	dir := filepath.Dir(path)
	st, err := os.Stat(dir)
	if err != nil {
		return err
	}
	if !st.IsDir() {
		return os.ErrNotExist
	}
	if err := os.WriteFile(path, []byte{}, 0o666); err != nil {
		return err
	}
	_ = os.Chmod(path, 0o666)
	return nil
}

func RotateLogIfHuge(path string, maxBytes int64) {
	st, err := os.Stat(path)
	if err != nil || !st.Mode().IsRegular() || st.Size() <= maxBytes {
		return
	}
	_ = os.Remove(path + ".1")
	_ = os.Rename(path, path+".1")
	_ = os.WriteFile(path, []byte{}, 0o666)
}

func ProcAlive(pid int) bool {
	if pid < 1 {
		return false
	}
	st, err := os.Stat("/proc/" + strconv.Itoa(pid))
	return err == nil && st.IsDir()
}

func AsString(v any) string {
	if v == nil {
		return ""
	}
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

func AsInt(v any) int {
	switch n := v.(type) {
	case float64:
		return int(n)
	case int:
		return n
	case int64:
		return int(n)
	case json.Number:
		i, _ := n.Int64()
		return int(i)
	default:
		return 0
	}
}

func AsBool(v any) bool {
	b, _ := v.(bool)
	return b
}

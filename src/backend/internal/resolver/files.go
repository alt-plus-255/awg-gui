package resolver

import (
	"crypto/sha256"
	"os"
	"path/filepath"
	"strconv"
)

type FileHelper struct{}

func (FileHelper) WriteIfChanged(path, contents string) (bool, error) {
	if existing, err := os.ReadFile(path); err == nil {
		if sha256.Sum256(existing) == sha256.Sum256([]byte(contents)) {
			return false, nil
		}
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return false, err
	}
	if err := os.WriteFile(path, []byte(contents), 0o644); err != nil {
		return false, err
	}
	return true, nil
}

func (h FileHelper) AtomicWriteIfChanged(path, contents string) (bool, error) {
	if existing, err := os.ReadFile(path); err == nil {
		if sha256.Sum256(existing) == sha256.Sum256([]byte(contents)) {
			return false, nil
		}
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return false, err
	}
	tmp := path + ".tmp." + strconv.Itoa(os.Getpid())
	if err := os.WriteFile(tmp, []byte(contents), 0o644); err != nil {
		return false, err
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return false, err
	}
	return true, nil
}

func (h FileHelper) WriteExecutable(path, body string) (bool, error) {
	changed, err := h.WriteIfChanged(path, body)
	if err != nil {
		return false, err
	}
	if err := os.Chmod(path, 0o755); err != nil {
		return changed, err
	}
	return changed, nil
}

func fileSize(path string) int64 {
	st, err := os.Stat(path)
	if err != nil {
		return 0
	}
	return st.Size()
}

func fileExists(path string) bool {
	st, err := os.Stat(path)
	return err == nil && st.Mode().IsRegular()
}

func fileMTimeISO(path string) *string {
	st, err := os.Stat(path)
	if err != nil {
		return nil
	}
	s := st.ModTime().UTC().Format("2006-01-02T15:04:05Z07:00")
	return &s
}

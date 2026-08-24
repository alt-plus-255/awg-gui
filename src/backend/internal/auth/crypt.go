package auth

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"
)

// ParseAppKey accepts Laravel-style "base64:..." keys or raw key material.
func ParseAppKey(appKey string) ([]byte, error) {
	appKey = strings.TrimSpace(appKey)
	if appKey == "" {
		return nil, errors.New("empty APP_KEY")
	}
	if strings.HasPrefix(appKey, "base64:") {
		raw, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(appKey, "base64:"))
		if err != nil {
			return nil, fmt.Errorf("decode APP_KEY: %w", err)
		}
		if len(raw) == 0 {
			return nil, errors.New("empty APP_KEY material")
		}
		return raw, nil
	}
	return []byte(appKey), nil
}

type laravelPayload struct {
	IV    string `json:"iv"`
	Value string `json:"value"`
	MAC   string `json:"mac"`
	Tag   string `json:"tag"`
}

// EncryptString mirrors Laravel Crypt::encryptString (AES-256-CBC + HMAC).
func EncryptString(key []byte, plaintext string) (string, error) {
	key = normalizeKey(key)
	block, err := aes.NewCipher(key)
	if err != nil {
		return "", err
	}
	iv := make([]byte, aes.BlockSize)
	if _, err := io.ReadFull(rand.Reader, iv); err != nil {
		return "", err
	}
	padded := pkcs7Pad([]byte(plaintext), aes.BlockSize)
	ciphertext := make([]byte, len(padded))
	cipher.NewCBCEncrypter(block, iv).CryptBlocks(ciphertext, padded)

	value := base64.StdEncoding.EncodeToString(ciphertext)
	ivB64 := base64.StdEncoding.EncodeToString(iv)
	mac := laravelMAC(key, ivB64, value)

	raw, err := json.Marshal(laravelPayload{IV: ivB64, Value: value, MAC: mac, Tag: ""})
	if err != nil {
		return "", err
	}
	return base64.StdEncoding.EncodeToString(raw), nil
}

// DecryptString mirrors Laravel Crypt::decryptString.
func DecryptString(key []byte, payload string) (string, error) {
	key = normalizeKey(key)
	raw, err := base64.StdEncoding.DecodeString(payload)
	if err != nil {
		return "", err
	}
	var p laravelPayload
	if err := json.Unmarshal(raw, &p); err != nil {
		return "", err
	}
	if !hmac.Equal([]byte(laravelMAC(key, p.IV, p.Value)), []byte(p.MAC)) {
		return "", errors.New("invalid mac")
	}
	iv, err := base64.StdEncoding.DecodeString(p.IV)
	if err != nil {
		return "", err
	}
	ct, err := base64.StdEncoding.DecodeString(p.Value)
	if err != nil {
		return "", err
	}
	if len(iv) != aes.BlockSize || len(ct) == 0 || len(ct)%aes.BlockSize != 0 {
		return "", errors.New("invalid ciphertext")
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return "", err
	}
	plain := make([]byte, len(ct))
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(plain, ct)
	plain, err = pkcs7Unpad(plain, aes.BlockSize)
	if err != nil {
		return "", err
	}
	return string(plain), nil
}

func laravelMAC(key []byte, ivB64, value string) string {
	m := hmac.New(sha256.New, key)
	_, _ = m.Write([]byte(ivB64 + value))
	return fmt.Sprintf("%x", m.Sum(nil))
}

func normalizeKey(key []byte) []byte {
	if len(key) >= 32 {
		return key[:32]
	}
	out := make([]byte, 32)
	copy(out, key)
	return out
}

func pkcs7Pad(data []byte, blockSize int) []byte {
	pad := blockSize - len(data)%blockSize
	out := make([]byte, len(data)+pad)
	copy(out, data)
	for i := len(data); i < len(out); i++ {
		out[i] = byte(pad)
	}
	return out
}

func pkcs7Unpad(data []byte, blockSize int) ([]byte, error) {
	if len(data) == 0 || len(data)%blockSize != 0 {
		return nil, errors.New("invalid padding size")
	}
	pad := int(data[len(data)-1])
	if pad == 0 || pad > blockSize || pad > len(data) {
		return nil, errors.New("invalid padding")
	}
	for i := 0; i < pad; i++ {
		if data[len(data)-1-i] != byte(pad) {
			return nil, errors.New("invalid padding bytes")
		}
	}
	return data[:len(data)-pad], nil
}

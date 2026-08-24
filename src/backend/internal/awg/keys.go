package awg

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"strings"
	"time"

	"golang.org/x/crypto/curve25519"
)

type KeyPair struct {
	Private string
	Public  string
}

func (s *Service) GenerateKeyPair(ctx context.Context) (KeyPair, error) {
	if kp, ok := s.generateKeyPairViaAWG(ctx); ok {
		return kp, nil
	}
	return generateKeyPairViaCurve25519()
}

func (s *Service) generateKeyPairViaAWG(ctx context.Context) (KeyPair, bool) {
	if !s.IsContainerRunning(ctx) {
		return KeyPair{}, false
	}
	privRes := s.Docker.Exec(ctx, s.ContainerName(), []string{"awg", "genkey"}, 10*time.Second, "")
	if !privRes.Successful() {
		return KeyPair{}, false
	}
	priv := strings.TrimSpace(privRes.Stdout)
	if priv == "" {
		return KeyPair{}, false
	}
	pubRes := s.Docker.ExecInteractive(ctx, s.ContainerName(), []string{"awg", "pubkey"}, 10*time.Second, priv+"\n")
	if !pubRes.Successful() {
		return KeyPair{}, false
	}
	pub := strings.TrimSpace(pubRes.Stdout)
	if pub == "" {
		return KeyPair{}, false
	}
	return KeyPair{Private: priv, Public: pub}, true
}

func generateKeyPairViaCurve25519() (KeyPair, error) {
	var priv [32]byte
	if _, err := rand.Read(priv[:]); err != nil {
		return KeyPair{}, err
	}
	priv[0] &= 248
	priv[31] &= 127
	priv[31] |= 64
	pub, err := curve25519.X25519(priv[:], curve25519.Basepoint)
	if err != nil {
		return KeyPair{}, err
	}
	return KeyPair{
		Private: base64.StdEncoding.EncodeToString(priv[:]),
		Public:  base64.StdEncoding.EncodeToString(pub),
	}, nil
}

func GeneratePresharedKey() (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.StdEncoding.EncodeToString(b), nil
}

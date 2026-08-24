package ssl

import (
	"bytes"
	"crypto"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"math/big"
	"net/http"
	"strings"
	"time"
)

type AcmeHTTPClient struct {
	DirectoryURL  string
	directory     map[string]any
	nonce         string
	AccountKeyPEM []byte
	accountURL    string
	HTTP          *http.Client
}

func NewAcmeHTTPClient(accountKeyPEM []byte, directoryURL string) *AcmeHTTPClient {
	if strings.TrimSpace(directoryURL) == "" {
		directoryURL = "https://acme-v02.api.letsencrypt.org/directory"
	}
	return &AcmeHTTPClient{
		DirectoryURL:  directoryURL,
		AccountKeyPEM: accountKeyPEM,
		HTTP:          &http.Client{Timeout: 60 * time.Second},
	}
}

func (c *AcmeHTTPClient) SetAccountURL(url string) { c.accountURL = url }
func (c *AcmeHTTPClient) AccountURL() string       { return c.accountURL }

func (c *AcmeHTTPClient) Directory() (map[string]any, error) {
	if c.directory != nil {
		return c.directory, nil
	}
	resp, err := c.HTTP.Get(c.DirectoryURL)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("ACME directory request failed: HTTP %d", resp.StatusCode)
	}
	var dir map[string]any
	if err := json.NewDecoder(resp.Body).Decode(&dir); err != nil {
		return nil, fmt.Errorf("Invalid ACME directory response")
	}
	c.directory = dir
	return dir, nil
}

func (c *AcmeHTTPClient) ResourceURL(name string) (string, error) {
	dir, err := c.Directory()
	if err != nil {
		return "", err
	}
	v, _ := dir[name].(string)
	if v == "" {
		return "", fmt.Errorf("ACME directory missing resource: %s", name)
	}
	return v, nil
}

type AcmeResponse struct {
	Status   int
	Body     any
	Location string
	Raw      string
}

func (c *AcmeHTTPClient) SignedRequest(url string, payload any, useKid bool) (AcmeResponse, error) {
	if err := c.ensureNonce(); err != nil {
		return AcmeResponse{}, err
	}
	protected := map[string]any{
		"alg":   "RS256",
		"nonce": c.nonce,
		"url":   url,
	}
	if useKid && c.accountURL != "" {
		protected["kid"] = c.accountURL
	} else {
		jwk, err := c.JWK()
		if err != nil {
			return AcmeResponse{}, err
		}
		protected["jwk"] = jwk
	}
	protJSON, _ := json.Marshal(protected)
	protectedB64 := b64(protJSON)
	var payloadB64 string
	switch p := payload.(type) {
	case nil:
		payloadB64 = b64([]byte{})
	case map[string]any:
		if p == nil {
			payloadB64 = b64([]byte{})
		} else {
			b, _ := json.Marshal(p)
			payloadB64 = b64(b)
		}
	default:
		b, _ := json.Marshal(p)
		payloadB64 = b64(b)
	}
	sig, err := c.sign(protectedB64 + "." + payloadB64)
	if err != nil {
		return AcmeResponse{}, err
	}
	jose, _ := json.Marshal(map[string]string{
		"protected": protectedB64,
		"payload":   payloadB64,
		"signature": sig,
	})
	req, err := http.NewRequest(http.MethodPost, url, bytes.NewReader(jose))
	if err != nil {
		return AcmeResponse{}, err
	}
	req.Header.Set("Content-Type", "application/jose+json")
	req.Header.Set("Accept", "application/pem-certificate-chain, application/json")
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return AcmeResponse{}, err
	}
	defer resp.Body.Close()
	if n := resp.Header.Get("Replay-Nonce"); n != "" {
		c.nonce = n
	}
	location := resp.Header.Get("Location")
	raw, _ := io.ReadAll(resp.Body)
	var body any
	var parsed map[string]any
	if json.Unmarshal(raw, &parsed) == nil {
		body = parsed
	} else {
		body = string(raw)
	}
	if resp.StatusCode >= 400 {
		detail := string(raw)
		if parsed != nil {
			if d, ok := parsed["detail"].(string); ok && d != "" {
				detail = d
			} else if t, ok := parsed["type"].(string); ok && t != "" {
				detail = t
			}
		}
		return AcmeResponse{}, fmt.Errorf("ACME request failed (%d): %s", resp.StatusCode, detail)
	}
	return AcmeResponse{Status: resp.StatusCode, Body: body, Location: location, Raw: string(raw)}, nil
}

func (c *AcmeHTTPClient) JWK() (map[string]string, error) {
	key, err := parseRSAPrivate(c.AccountKeyPEM)
	if err != nil {
		return nil, fmt.Errorf("Invalid ACME account private key")
	}
	pub := key.Public().(*rsa.PublicKey)
	return map[string]string{
		"e":   b64(big.NewInt(int64(pub.E)).Bytes()),
		"kty": "RSA",
		"n":   b64(pub.N.Bytes()),
	}, nil
}

func (c *AcmeHTTPClient) Thumbprint() (string, error) {
	jwk, err := c.JWK()
	if err != nil {
		return "", err
	}
	jsonStr := `{"e":"` + jwk["e"] + `","kty":"RSA","n":"` + jwk["n"] + `"}`
	sum := sha256.Sum256([]byte(jsonStr))
	return b64(sum[:]), nil
}

func (c *AcmeHTTPClient) KeyAuthorization(token string) (string, error) {
	tp, err := c.Thumbprint()
	if err != nil {
		return "", err
	}
	return token + "." + tp, nil
}

func (c *AcmeHTTPClient) DNSTXTValue(token string) (string, error) {
	ka, err := c.KeyAuthorization(token)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256([]byte(ka))
	return b64(sum[:]), nil
}

func (c *AcmeHTTPClient) ensureNonce() error {
	if c.nonce != "" {
		return nil
	}
	newNonce, err := c.ResourceURL("newNonce")
	if err != nil {
		return err
	}
	req, _ := http.NewRequest(http.MethodHead, newNonce, nil)
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return err
	}
	nonce := resp.Header.Get("Replay-Nonce")
	resp.Body.Close()
	if nonce == "" {
		resp, err = c.HTTP.Get(newNonce)
		if err != nil {
			return err
		}
		nonce = resp.Header.Get("Replay-Nonce")
		resp.Body.Close()
	}
	if nonce == "" {
		return fmt.Errorf("Failed to obtain ACME nonce")
	}
	c.nonce = nonce
	return nil
}

func (c *AcmeHTTPClient) sign(input string) (string, error) {
	key, err := parseRSAPrivate(c.AccountKeyPEM)
	if err != nil {
		return "", fmt.Errorf("Cannot load ACME account key")
	}
	sum := sha256.Sum256([]byte(input))
	sig, err := rsa.SignPKCS1v15(rand.Reader, key, crypto.SHA256, sum[:])
	if err != nil {
		return "", fmt.Errorf("ACME JWS signature failed")
	}
	return b64(sig), nil
}

func b64(data []byte) string {
	return strings.TrimRight(base64.RawURLEncoding.EncodeToString(data), "=")
}

func parseRSAPrivate(pemBytes []byte) (*rsa.PrivateKey, error) {
	block, _ := pem.Decode(pemBytes)
	if block == nil {
		return nil, fmt.Errorf("invalid pem")
	}
	if key, err := x509.ParsePKCS1PrivateKey(block.Bytes); err == nil {
		return key, nil
	}
	k, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return nil, err
	}
	rsaKey, ok := k.(*rsa.PrivateKey)
	if !ok {
		return nil, fmt.Errorf("not rsa")
	}
	return rsaKey, nil
}

func generateRSAPrivateKeyPEM() ([]byte, error) {
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		return nil, fmt.Errorf("Failed to generate RSA key")
	}
	b := pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(key)})
	if len(b) == 0 {
		return nil, fmt.Errorf("Failed to export RSA key")
	}
	return b, nil
}

func createCSRDer(domain string, privateKeyPEM []byte) ([]byte, error) {
	key, err := parseRSAPrivate(privateKeyPEM)
	if err != nil {
		return nil, fmt.Errorf("Invalid domain private key")
	}
	csrDER, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{
		Subject:            pkix.Name{CommonName: domain},
		DNSNames:           []string{domain},
		SignatureAlgorithm: x509.SHA256WithRSA,
	}, key)
	if err != nil {
		return nil, fmt.Errorf("Failed to create CSR")
	}
	return csrDER, nil
}

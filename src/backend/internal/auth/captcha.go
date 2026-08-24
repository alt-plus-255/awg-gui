package auth

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"image"
	"image/color"
	"image/png"
	"math/big"
	"regexp"
	"time"

	"golang.org/x/image/font"
	"golang.org/x/image/font/basicfont"
	"golang.org/x/image/math/fixed"
)

const (
	CaptchaTTLSeconds = 300
	CaptchaLength     = 5
)

var nonDigits = regexp.MustCompile(`\D+`)

type CaptchaService struct {
	db *sql.DB
}

func NewCaptchaService(db *sql.DB) *CaptchaService {
	return &CaptchaService{db: db}
}

type CaptchaPayload struct {
	Token string `json:"token"`
	Image string `json:"image"`
}

func (s *CaptchaService) Generate(ctx context.Context) (CaptchaPayload, error) {
	answer := make([]byte, CaptchaLength)
	for i := 0; i < CaptchaLength; i++ {
		n, _ := rand.Int(rand.Reader, big.NewInt(10))
		answer[i] = byte('0' + n.Int64())
	}
	token := randomHex(20)
	hash := sha256Hex(string(answer))
	if err := s.put(ctx, token, hash); err != nil {
		return CaptchaPayload{}, err
	}
	pngBytes, err := renderCaptchaPNG(string(answer))
	if err != nil {
		return CaptchaPayload{}, err
	}
	return CaptchaPayload{
		Token: token,
		Image: "data:image/png;base64," + base64.StdEncoding.EncodeToString(pngBytes),
	}, nil
}

func (s *CaptchaService) Verify(ctx context.Context, token, answer string) bool {
	if token == "" || answer == "" {
		return false
	}
	expected, ok := s.pull(ctx, token)
	if !ok || expected == "" {
		return false
	}
	normalized := nonDigits.ReplaceAllString(answer, "")
	return hmacEqual(expected, sha256Hex(normalized))
}

func (s *CaptchaService) put(ctx context.Context, token, hash string) error {
	if s.db == nil {
		return fmt.Errorf("database unavailable")
	}
	key := "captcha:" + token
	exp := time.Now().Unix() + CaptchaTTLSeconds
	_, err := s.db.ExecContext(ctx, `
INSERT INTO cache (`+"`key`"+`, value, expiration) VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE value = VALUES(value), expiration = VALUES(expiration)`,
		key, hash, exp)
	return err
}

func (s *CaptchaService) pull(ctx context.Context, token string) (string, bool) {
	if s.db == nil {
		return "", false
	}
	key := "captcha:" + token
	var value string
	var exp int64
	err := s.db.QueryRowContext(ctx, `SELECT value, expiration FROM cache WHERE `+"`key`"+` = ?`, key).Scan(&value, &exp)
	_, _ = s.db.ExecContext(ctx, `DELETE FROM cache WHERE `+"`key`"+` = ?`, key)
	if err != nil || time.Now().Unix() > exp {
		return "", false
	}
	return value, true
}

func sha256Hex(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}

func hmacEqual(a, b string) bool {
	if len(a) != len(b) {
		return false
	}
	var v byte
	for i := 0; i < len(a); i++ {
		v |= a[i] ^ b[i]
	}
	return v == 0
}

func renderCaptchaPNG(digits string) ([]byte, error) {
	const width, height = 180, 56
	img := image.NewRGBA(image.Rect(0, 0, width, height))
	bg := color.RGBA{28, 32, 40, 255}
	for y := 0; y < height; y++ {
		for x := 0; x < width; x++ {
			img.Set(x, y, bg)
		}
	}

	for i := 0; i < 60; i++ {
		x, _ := rand.Int(rand.Reader, big.NewInt(width))
		y, _ := rand.Int(rand.Reader, big.NewInt(height))
		r, _ := rand.Int(rand.Reader, big.NewInt(81))
		g, _ := rand.Int(rand.Reader, big.NewInt(81))
		b, _ := rand.Int(rand.Reader, big.NewInt(101))
		img.Set(int(x.Int64()), int(y.Int64()), color.RGBA{uint8(40 + r.Int64()), uint8(40 + g.Int64()), uint8(40 + b.Int64()), 255})
	}
	for i := 0; i < 5; i++ {
		x0, _ := rand.Int(rand.Reader, big.NewInt(width))
		y0, _ := rand.Int(rand.Reader, big.NewInt(height))
		x1, _ := rand.Int(rand.Reader, big.NewInt(width))
		y1, _ := rand.Int(rand.Reader, big.NewInt(height))
		r, _ := rand.Int(rand.Reader, big.NewInt(81))
		g, _ := rand.Int(rand.Reader, big.NewInt(81))
		b, _ := rand.Int(rand.Reader, big.NewInt(81))
		drawLine(img, int(x0.Int64()), int(y0.Int64()), int(x1.Int64()), int(y1.Int64()), color.RGBA{uint8(60 + r.Int64()), uint8(60 + g.Int64()), uint8(80 + b.Int64()), 255})
	}

	face := basicfont.Face7x13
	slot := (width - 16) / len(digits)
	for i, ch := range digits {
		jx, _ := rand.Int(rand.Reader, big.NewInt(5))
		jy, _ := rand.Int(rand.Reader, big.NewInt(17))
		x := 10 + i*slot + int(jx.Int64()) - 2
		y := 12 + int(jy.Int64())
		r, _ := rand.Int(rand.Reader, big.NewInt(76))
		g, _ := rand.Int(rand.Reader, big.NewInt(76))
		b, _ := rand.Int(rand.Reader, big.NewInt(76))
		col := color.RGBA{uint8(180 + r.Int64()), uint8(180 + g.Int64()), uint8(180 + b.Int64()), 255}
		d := &font.Drawer{
			Dst:  img,
			Src:  image.NewUniform(col),
			Face: face,
			Dot:  fixed.P(x, y+13),
		}
		d.DrawString(string(ch))
	}

	var buf bytes.Buffer
	if err := png.Encode(&buf, img); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func drawLine(img *image.RGBA, x0, y0, x1, y1 int, c color.RGBA) {
	dx := abs(x1 - x0)
	dy := abs(y1 - y0)
	sx, sy := 1, 1
	if x0 > x1 {
		sx = -1
	}
	if y0 > y1 {
		sy = -1
	}
	err := dx - dy
	for {
		img.Set(x0, y0, c)
		if x0 == x1 && y0 == y1 {
			break
		}
		e2 := 2 * err
		if e2 > -dy {
			err -= dy
			x0 += sx
		}
		if e2 < dx {
			err += dx
			y0 += sy
		}
	}
}

func abs(n int) int {
	if n < 0 {
		return -n
	}
	return n
}

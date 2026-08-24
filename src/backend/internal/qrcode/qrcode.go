package qrcode

import (
	"bytes"
	"errors"
	"os"
	"os/exec"
	"regexp"
	"strings"

	goqr "github.com/skip2/go-qrcode"
)

var emptyIParam = regexp.MustCompile(`(?i)^I[1-5]\s*=\s*$`)

var ErrTooLarge = errors.New("qr_too_large")
var ErrSVGRequiresQrencode = errors.New("svg_requires_qrencode")

type Service struct{}

func New() *Service { return &Service{} }

func (s *Service) NormalizeConfigText(conf string) string {
	conf = strings.ReplaceAll(conf, "\r\n", "\n")
	conf = strings.ReplaceAll(conf, "\r", "\n")
	var lines []string
	for _, line := range strings.Split(conf, "\n") {
		if emptyIParam.MatchString(line) {
			continue
		}
		lines = append(lines, strings.TrimRight(line, "\r"))
	}
	for len(lines) > 0 && lines[len(lines)-1] == "" {
		lines = lines[:len(lines)-1]
	}
	return strings.Join(lines, "\n") + "\n"
}

func (s *Service) BuildPNG(data string) ([]byte, error) {
	if png := s.buildWithQrencode(data, "PNG"); png != nil {
		return png, nil
	}
	return s.buildWithGoQR(data)
}

func (s *Service) BuildSVG(data string) ([]byte, error) {
	if svg := s.buildWithQrencode(data, "SVG"); svg != nil {
		return svg, nil
	}
	return nil, ErrSVGRequiresQrencode
}

func (s *Service) buildWithQrencode(data, typ string) []byte {
	if _, err := exec.LookPath("qrencode"); err != nil {
		return nil
	}
	tmp, err := os.CreateTemp("", "awg-qr-")
	if err != nil {
		return nil
	}
	path := tmp.Name()
	_, _ = tmp.WriteString(data)
	tmp.Close()
	defer os.Remove(path)

	module := qrencodeModuleSize(len(data))
	for _, level := range []string{"L", "M", "Q", "H"} {
		cmd := exec.Command("qrencode", "-t", typ, "-o", "-", "-m", "4", "-l", level, "-s", itoa(module), "-r", path)
		out, err := cmd.Output()
		if err != nil || len(out) == 0 {
			continue
		}
		if typ == "PNG" && !bytes.HasPrefix(out, []byte("\x89PNG\r\n\x1a\n")) {
			continue
		}
		if typ == "SVG" && !bytes.Contains(out, []byte("<svg")) {
			continue
		}
		return out
	}
	return nil
}

func (s *Service) buildWithGoQR(data string) ([]byte, error) {
	size := imageSizeForLength(len(data))
	var last error
	for _, level := range []goqr.RecoveryLevel{goqr.Low, goqr.Medium, goqr.High, goqr.Highest} {
		png, err := goqr.New(data, level)
		if err != nil {
			last = err
			continue
		}
		b, err := png.PNG(size)
		if err != nil {
			last = err
			continue
		}
		return b, nil
	}
	if last == nil {
		last = ErrTooLarge
	}
	return nil, last
}

func qrencodeModuleSize(bytes int) int {
	if bytes < 1200 {
		return 6
	}
	if bytes <= 2200 {
		return 5
	}
	return 4
}

func imageSizeForLength(n int) int {
	if n < 1200 {
		return 400
	}
	if n <= 2200 {
		return 512
	}
	return 640
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var buf [8]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	return string(buf[i:])
}

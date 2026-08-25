package api

import (
	"net/http"
	"strings"

	"github.com/awggui/backend/internal/cps"
)

type CPSController struct{}

func (c *CPSController) Templates(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"templates": cps.TemplatesCatalog(),
		"default":   cps.DefaultProtocol(),
	})
}

func (c *CPSController) Generate(w http.ResponseWriter, r *http.Request) {
	var req struct {
		Protocol string `json:"protocol"`
		S1       string `json:"s1"`
		S2       string `json:"s2"`
		S3       string `json:"s3"`
		S4       string `json:"s4"`
		MTU      int    `json:"mtu"`
		AllowD   bool   `json:"allow_d"`
	}
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	proto := strings.TrimSpace(req.Protocol)
	if proto != "" && !cps.HasProtocol(proto) {
		writeValidation(w, r, "protocol", "api.http_422", nil)
		return
	}
	constraints := cps.ConstraintsFromStrings(req.S1, req.S2, req.S3, req.S4, req.MTU, req.AllowD)
	gen := cps.Generate(cps.GenerateOpts{Protocol: proto, Constraints: constraints})
	writeJSON(w, http.StatusOK, map[string]any{
		"protocol": gen.Protocol,
		"i1":       gen.I1,
		"i2":       gen.I2,
		"i3":       gen.I3,
		"i4":       gen.I4,
		"i5":       gen.I5,
		"lengths":  gen.Lengths,
		"warnings": gen.Warnings,
	})
}

func (c *CPSController) Validate(w http.ResponseWriter, r *http.Request) {
	var req struct {
		I1     string `json:"i1"`
		I2     string `json:"i2"`
		I3     string `json:"i3"`
		I4     string `json:"i4"`
		I5     string `json:"i5"`
		S1     string `json:"s1"`
		S2     string `json:"s2"`
		S3     string `json:"s3"`
		S4     string `json:"s4"`
		MTU    int    `json:"mtu"`
		AllowD bool   `json:"allow_d"`
	}
	if err := decodeJSON(r, &req); err != nil {
		write422(w, r)
		return
	}
	constraints := cps.ConstraintsFromStrings(req.S1, req.S2, req.S3, req.S4, req.MTU, req.AllowD)
	result := cps.ValidateAll(map[string]string{
		"i1": req.I1, "i2": req.I2, "i3": req.I3, "i4": req.I4, "i5": req.I5,
	}, constraints)
	writeJSON(w, http.StatusOK, result)
}

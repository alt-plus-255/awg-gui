package ssl

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

type Challenge struct {
	Domain   string `json:"domain"`
	TXTName  string `json:"txt_name"`
	TXTValue string `json:"txt_value"`
}

type AcmeDNSIssuer struct {
	Base          string
	DirectoryURL  string
}

func NewAcmeDNSIssuer(baseDir, directoryURL string) *AcmeDNSIssuer {
	return &AcmeDNSIssuer{Base: strings.TrimRight(baseDir, "/"), DirectoryURL: directoryURL}
}

func (i *AcmeDNSIssuer) BaseDir() string      { return i.Base }
func (i *AcmeDNSIssuer) AcmeDir() string      { return i.Base + "/acme" }
func (i *AcmeDNSIssuer) ChallengeDir() string { return i.AcmeDir() + "/challenge" }

func (i *AcmeDNSIssuer) EnsureLayout() {
	for _, dir := range []string{
		i.AcmeDir(),
		i.AcmeDir() + "/account",
		i.AcmeDir() + "/pending",
		i.ChallengeDir(),
		i.Base + "/certs",
		i.Base + "/certs/panel",
		i.Base + "/certs/live/panel",
	} {
		_ = os.MkdirAll(dir, 0755)
	}
}

func (i *AcmeDNSIssuer) ReadPendingChallenge() *Challenge {
	if ch := i.readChallengeFiles(); ch != nil {
		i.backfillTXTValueInOrder(ch.Domain, ch.TXTValue)
		return ch
	}
	if _, err := os.Stat(i.AcmeDir() + "/pending/order.json"); err != nil {
		return nil
	}
	pending, err := i.loadPending()
	if err != nil {
		return nil
	}
	domain := strings.ToLower(strings.TrimSpace(strMap(pending, "domain")))
	value := strings.TrimSpace(strMap(pending, "txt_value"))
	if domain == "" || value == "" {
		return nil
	}
	i.writeChallengeFiles(domain, value)
	return &Challenge{Domain: domain, TXTName: "_acme-challenge." + domain, TXTValue: value}
}

func (i *AcmeDNSIssuer) HasPendingOrder() bool {
	if _, err := os.Stat(i.AcmeDir() + "/pending/order.json"); err != nil {
		return false
	}
	if _, err := os.Stat(i.AcmeDir() + "/pending/domain.pem"); err != nil {
		return false
	}
	return i.ReadPendingChallenge() != nil
}

func (i *AcmeDNSIssuer) ReusableChallengeFor(domain string) *Challenge {
	domain = strings.ToLower(strings.TrimSpace(domain))
	if domain == "" || !i.HasPendingOrder() {
		return nil
	}
	existing := i.ReadPendingChallenge()
	if existing == nil || existing.Domain != domain {
		return nil
	}
	return existing
}

func (i *AcmeDNSIssuer) Start(locale, domain, email string, forceNew bool) (Challenge, error) {
	domain = strings.ToLower(strings.TrimSpace(domain))
	email = strings.TrimSpace(email)
	if domain == "" || email == "" {
		return Challenge{}, fmt.Errorf("Domain and email are required")
	}
	i.EnsureLayout()
	if !forceNew {
		if existing := i.ReusableChallengeFor(domain); existing != nil {
			return *existing, nil
		}
	}
	i.clearPendingFiles()
	client, err := i.client()
	if err != nil {
		return Challenge{}, err
	}
	if err := i.ensureAccount(client, email, locale); err != nil {
		return Challenge{}, err
	}
	orderURL, err := client.ResourceURL("newOrder")
	if err != nil {
		return Challenge{}, err
	}
	orderResp, err := client.SignedRequest(orderURL, map[string]any{
		"identifiers": []map[string]string{{"type": "dns", "value": domain}},
	}, true)
	if err != nil {
		return Challenge{}, err
	}
	orderBody, _ := orderResp.Body.(map[string]any)
	authzList, _ := orderBody["authorizations"].([]any)
	if orderBody == nil || len(authzList) == 0 {
		return Challenge{}, fmt.Errorf("%s", i18n.T(locale, "settings.acme_order_failed"))
	}
	if orderResp.Location == "" {
		return Challenge{}, fmt.Errorf("%s", i18n.T(locale, "settings.acme_order_failed"))
	}
	authzURL, _ := authzList[0].(string)
	authzResp, err := client.SignedRequest(authzURL, nil, true)
	if err != nil {
		return Challenge{}, err
	}
	authz, _ := authzResp.Body.(map[string]any)
	challenges, _ := authz["challenges"].([]any)
	if authz == nil || len(challenges) == 0 {
		return Challenge{}, fmt.Errorf("%s", i18n.T(locale, "settings.acme_order_failed"))
	}
	var dnsChallenge map[string]any
	for _, raw := range challenges {
		ch, _ := raw.(map[string]any)
		if strMap(ch, "type") == "dns-01" {
			dnsChallenge = ch
			break
		}
	}
	token := strMap(dnsChallenge, "token")
	chalURL := strMap(dnsChallenge, "url")
	if token == "" || chalURL == "" {
		return Challenge{}, fmt.Errorf("%s", i18n.T(locale, "settings.acme_dns_challenge_missing"))
	}
	txtValue, err := client.DNSTXTValue(token)
	if err != nil {
		return Challenge{}, err
	}
	txtName := "_acme-challenge." + domain
	domainKeyPEM, err := generateRSAPrivateKeyPEM()
	if err != nil {
		return Challenge{}, err
	}
	pemPath := i.AcmeDir() + "/pending/domain.pem"
	if err := os.WriteFile(pemPath, domainKeyPEM, 0600); err != nil {
		return Challenge{}, err
	}
	pending := map[string]any{
		"domain":          domain,
		"email":           email,
		"order_url":       orderResp.Location,
		"finalize_url":    strMap(orderBody, "finalize"),
		"authz_url":       authzURL,
		"challenge_url":   chalURL,
		"challenge_token": token,
		"txt_name":        txtName,
		"txt_value":       txtValue,
		"account_url":     client.AccountURL(),
		"created_at":      time.Now().UTC().Format(time.RFC3339),
	}
	b, _ := json.MarshalIndent(pending, "", "    ")
	if err := os.WriteFile(i.AcmeDir()+"/pending/order.json", b, 0644); err != nil {
		return Challenge{}, err
	}
	i.writeChallengeFiles(domain, txtValue)
	return Challenge{Domain: domain, TXTName: txtName, TXTValue: txtValue}, nil
}

func (i *AcmeDNSIssuer) Complete(locale string, timeoutSeconds int) (map[string]string, error) {
	challenge := i.ReadPendingChallenge()
	if challenge == nil {
		return nil, fmt.Errorf("%s", i18n.T(locale, "settings.no_active_dns_challenge"))
	}
	if _, err := os.Stat(i.AcmeDir() + "/pending/order.json"); err != nil {
		return nil, fmt.Errorf("%s", i18n.T(locale, "settings.no_active_dns_challenge"))
	}
	pending, err := i.loadPending()
	if err != nil {
		return nil, err
	}
	domainKeyPEM, err := os.ReadFile(i.AcmeDir() + "/pending/domain.pem")
	if err != nil || len(domainKeyPEM) == 0 {
		return nil, fmt.Errorf("%s", i18n.T(locale, "settings.acme_domain_key_missing"))
	}
	client, err := i.client()
	if err != nil {
		return nil, err
	}
	if u := strMap(pending, "account_url"); u != "" {
		client.SetAccountURL(u)
	}
	if err := i.ensureAccount(client, strMap(pending, "email"), locale); err != nil {
		return nil, err
	}
	if _, err := client.SignedRequest(strMap(pending, "challenge_url"), map[string]any{}, true); err != nil {
		return nil, err
	}
	deadline := time.Now().Add(time.Duration(timeoutSeconds) * time.Second)
	status := "pending"
	for time.Now().Before(deadline) || time.Now().Equal(deadline) {
		authz, err := client.SignedRequest(strMap(pending, "authz_url"), nil, true)
		if err != nil {
			return nil, err
		}
		body, _ := authz.Body.(map[string]any)
		status = strMap(body, "status")
		if status == "valid" {
			break
		}
		if status == "invalid" {
			detail, _ := json.Marshal(body["challenges"])
			if len(detail) == 0 {
				detail, _ = json.Marshal(body)
			}
			msg := i18n.T(locale, "settings.acme_challenge_invalid")
			if len(detail) > 0 {
				msg += ": " + string(detail)
			}
			return nil, fmt.Errorf("%s", msg)
		}
		time.Sleep(time.Second)
	}
	if status != "valid" {
		return nil, fmt.Errorf("%s", i18n.T(locale, "settings.acme_challenge_timeout"))
	}
	csrDER, err := createCSRDer(strMap(pending, "domain"), domainKeyPEM)
	if err != nil {
		return nil, err
	}
	finalizeURL := strMap(pending, "finalize_url")
	if finalizeURL == "" {
		return nil, fmt.Errorf("%s", i18n.T(locale, "settings.acme_order_failed"))
	}
	if _, err := client.SignedRequest(finalizeURL, map[string]any{"csr": b64(csrDER)}, true); err != nil {
		return nil, err
	}
	orderURL := strMap(pending, "order_url")
	var certURL string
	orderStatus := "processing"
	for time.Now().Before(deadline) || time.Now().Equal(deadline) {
		order, err := client.SignedRequest(orderURL, nil, true)
		if err != nil {
			return nil, err
		}
		body, _ := order.Body.(map[string]any)
		if body == nil {
			return nil, fmt.Errorf("%s", i18n.T(locale, "settings.acme_order_failed"))
		}
		orderStatus = strMap(body, "status")
		if orderStatus == "valid" && strMap(body, "certificate") != "" {
			certURL = strMap(body, "certificate")
			break
		}
		switch orderStatus {
		case "invalid", "revoked", "expired":
			return nil, fmt.Errorf("%s: %s", i18n.T(locale, "settings.acme_order_failed"), orderStatus)
		}
		time.Sleep(time.Second)
	}
	if certURL == "" {
		return nil, fmt.Errorf("%s", i18n.T(locale, "settings.acme_challenge_timeout"))
	}
	certResp, err := client.SignedRequest(certURL, nil, true)
	if err != nil {
		return nil, err
	}
	pemChain := ""
	switch t := certResp.Body.(type) {
	case string:
		pemChain = t
	default:
		pemChain = certResp.Raw
	}
	if pemChain == "" || !strings.Contains(pemChain, "BEGIN CERTIFICATE") {
		return nil, fmt.Errorf("%s", i18n.T(locale, "settings.cert_files_not_found_after_issue"))
	}
	liveDir := i.Base + "/certs/live/panel"
	_ = os.MkdirAll(liveDir, 0755)
	_ = os.WriteFile(liveDir+"/fullchain.pem", []byte(pemChain), 0644)
	_ = os.WriteFile(liveDir+"/privkey.pem", domainKeyPEM, 0640)
	panelDir := i.Base + "/certs/panel"
	_ = os.MkdirAll(panelDir, 0755)
	_ = os.WriteFile(panelDir+"/fullchain.pem", []byte(pemChain), 0644)
	_ = os.WriteFile(panelDir+"/privkey.pem", domainKeyPEM, 0640)
	i.clearPendingFiles()
	return map[string]string{"fullchain": panelDir + "/fullchain.pem", "privkey": panelDir + "/privkey.pem"}, nil
}

func (i *AcmeDNSIssuer) Abort() { i.clearPendingFiles() }

func (i *AcmeDNSIssuer) readChallengeFiles() *Challenge {
	dir := i.ChallengeDir()
	if _, err := os.Stat(dir + "/ready"); err != nil {
		return nil
	}
	domainB, err1 := os.ReadFile(dir + "/domain")
	valB, err2 := os.ReadFile(dir + "/validation")
	if err1 != nil || err2 != nil {
		return nil
	}
	domain := strings.ToLower(strings.TrimSpace(string(domainB)))
	value := strings.TrimSpace(string(valB))
	if domain == "" || value == "" {
		return nil
	}
	return &Challenge{Domain: domain, TXTName: "_acme-challenge." + domain, TXTValue: value}
}

func (i *AcmeDNSIssuer) writeChallengeFiles(domain, txtValue string) {
	dir := i.ChallengeDir()
	_ = os.MkdirAll(dir, 0755)
	_ = os.WriteFile(dir+"/domain", []byte(domain), 0644)
	_ = os.WriteFile(dir+"/validation", []byte(txtValue), 0644)
	_ = os.Remove(dir + "/done")
	_ = os.Remove(dir + "/abort")
	f, err := os.Create(dir + "/ready")
	if err == nil {
		f.Close()
	}
}

func (i *AcmeDNSIssuer) backfillTXTValueInOrder(domain, txtValue string) {
	path := i.AcmeDir() + "/pending/order.json"
	pending, err := i.loadPending()
	if err != nil {
		return
	}
	if strMap(pending, "domain") != domain {
		return
	}
	if strings.TrimSpace(strMap(pending, "txt_value")) == txtValue && strings.TrimSpace(strMap(pending, "txt_name")) != "" {
		return
	}
	pending["txt_value"] = txtValue
	pending["txt_name"] = "_acme-challenge." + domain
	b, _ := json.MarshalIndent(pending, "", "    ")
	_ = os.WriteFile(path, b, 0644)
}

func (i *AcmeDNSIssuer) clearPendingFiles() {
	for _, p := range []string{i.AcmeDir() + "/pending/order.json", i.AcmeDir() + "/pending/domain.pem"} {
		_ = os.Remove(p)
	}
	for _, name := range []string{"ready", "done", "abort", "domain", "validation", "failed"} {
		_ = os.Remove(i.ChallengeDir() + "/" + name)
	}
}

func (i *AcmeDNSIssuer) loadPending() (map[string]any, error) {
	raw, err := os.ReadFile(i.AcmeDir() + "/pending/order.json")
	if err != nil {
		return nil, fmt.Errorf("%s", i18n.T("en", "settings.no_active_dns_challenge"))
	}
	var data map[string]any
	if json.Unmarshal(raw, &data) != nil {
		return nil, fmt.Errorf("%s", i18n.T("en", "settings.no_active_dns_challenge"))
	}
	return data, nil
}

func (i *AcmeDNSIssuer) client() (*AcmeHTTPClient, error) {
	i.EnsureLayout()
	keyPath := i.AcmeDir() + "/account/account.pem"
	if _, err := os.Stat(keyPath); err != nil {
		pem, err := generateRSAPrivateKeyPEM()
		if err != nil {
			return nil, err
		}
		if err := os.WriteFile(keyPath, pem, 0600); err != nil {
			return nil, err
		}
	}
	pem, err := os.ReadFile(keyPath)
	if err != nil {
		return nil, err
	}
	return NewAcmeHTTPClient(pem, i.DirectoryURL), nil
}

func (i *AcmeDNSIssuer) ensureAccount(client *AcmeHTTPClient, email, locale string) error {
	if client.AccountURL() != "" {
		return nil
	}
	accountFile := i.AcmeDir() + "/account/account_url"
	if raw, err := os.ReadFile(accountFile); err == nil {
		url := strings.TrimSpace(string(raw))
		if url != "" {
			client.SetAccountURL(url)
			return nil
		}
	}
	newAccount, err := client.ResourceURL("newAccount")
	if err != nil {
		return err
	}
	payload := map[string]any{"termsOfServiceAgreed": true, "contact": []string{}}
	if email != "" {
		payload["contact"] = []string{"mailto:" + email}
	}
	resp, err := client.SignedRequest(newAccount, payload, false)
	if err != nil {
		resp, err = client.SignedRequest(newAccount, map[string]any{"onlyReturnExisting": true}, false)
		if err != nil {
			return err
		}
	}
	if resp.Location == "" {
		return fmt.Errorf("%s", i18n.T(locale, "settings.acme_account_failed"))
	}
	client.SetAccountURL(resp.Location)
	_ = os.WriteFile(accountFile, []byte(resp.Location), 0644)
	return nil
}

func strMap(m map[string]any, key string) string {
	if m == nil {
		return ""
	}
	v, _ := m[key].(string)
	return v
}

package resolver

import (
	"context"
	"net"
	"strings"
)

func ValidDNSServer(addr string) bool {
	addr = strings.TrimSpace(addr)
	if addr == "" {
		return false
	}
	if net.ParseIP(addr) != nil {
		return true
	}
	parts := strings.Split(strings.ToLower(addr), ".")
	if len(parts) < 2 {
		return false
	}
	for _, p := range parts {
		if p == "" {
			return false
		}
	}
	return true
}

func (s *Service) BootstrapDNS(ctx context.Context) string {
	if s == nil || s.KV == nil {
		return DefaultBootstrapDNS
	}
	v := strings.TrimSpace(s.KV.Get(ctx, SettingBootstrapDNS, DefaultBootstrapDNS))
	if v == "" || !ValidDNSServer(v) {
		return DefaultBootstrapDNS
	}
	return v
}

func (s *Service) SetBootstrapDNS(ctx context.Context, dns string) error {
	dns = strings.TrimSpace(dns)
	if dns == "" {
		dns = DefaultBootstrapDNS
	}
	if !ValidDNSServer(dns) {
		return FieldErr("bootstrap_dns", "resolver.dns_required", nil)
	}
	if s.KV == nil {
		return nil
	}
	return s.KV.Set(ctx, SettingBootstrapDNS, dns)
}

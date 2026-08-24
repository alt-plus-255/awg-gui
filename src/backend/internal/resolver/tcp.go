package resolver

import (
	"net"
	"strconv"
	"sync"
	"time"
)

type TCPProbe struct{}

func (TCPProbe) IsReachable(outbound map[string]any, timeout time.Duration) bool {
	server := strVal(outbound["server"])
	port := atoiDef(strVal(outbound["server_port"]), 0)
	if server == "" || port < 1 {
		return true
	}
	ip := resolveIPv4(server)
	if ip == "" {
		return false
	}
	return tcpConnect(ip, port, timeout)
}

func (TCPProbe) CheckManyStreaming(keyToOutbound map[string]map[string]any, timeout time.Duration, onResult func(key string, reachable bool), shouldCancel func() bool) {
	sem := make(chan struct{}, 20)
	var wg sync.WaitGroup
	for key, ob := range keyToOutbound {
		if shouldCancel != nil && shouldCancel() {
			break
		}
		key, ob := key, ob
		wg.Add(1)
		sem <- struct{}{}
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			if shouldCancel != nil && shouldCancel() {
				return
			}
			ok := TCPProbe{}.IsReachable(ob, timeout)
			if onResult != nil {
				onResult(key, ok)
			}
		}()
	}
	wg.Wait()
}

func resolveIPv4(host string) string {
	if isIPv4(host) {
		return host
	}
	ips, err := net.LookupIP(host)
	if err != nil {
		return ""
	}
	for _, ip := range ips {
		if v4 := ip.To4(); v4 != nil {
			return v4.String()
		}
	}
	return ""
}

func tcpConnect(ip string, port int, timeout time.Duration) bool {
	addr := net.JoinHostPort(ip, strconv.Itoa(port))
	c, err := net.DialTimeout("tcp", addr, timeout)
	if err != nil {
		return false
	}
	_ = c.Close()
	return true
}

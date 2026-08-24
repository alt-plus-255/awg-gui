package telegram

import (
	"encoding/binary"
	"fmt"
	"io"
	"net"
	"strings"
	"time"
)

func dialSOCKS5(proxyAddr, user, pass, destHost string, destPort int, timeout time.Duration) (net.Conn, error) {
	conn, err := net.DialTimeout("tcp", proxyAddr, timeout)
	if err != nil {
		return nil, err
	}
	_ = conn.SetDeadline(time.Now().Add(timeout))
	if user != "" || pass != "" {
		if _, err := conn.Write([]byte{0x05, 0x01, 0x02}); err != nil {
			conn.Close()
			return nil, err
		}
	} else {
		if _, err := conn.Write([]byte{0x05, 0x01, 0x00}); err != nil {
			conn.Close()
			return nil, err
		}
	}
	auth := make([]byte, 2)
	if _, err := io.ReadFull(conn, auth); err != nil {
		conn.Close()
		return nil, err
	}
	if auth[0] != 0x05 {
		conn.Close()
		return nil, fmt.Errorf("socks5: bad version")
	}
	if auth[1] == 0x02 {
		u, p := []byte(user), []byte(pass)
		buf := []byte{0x01, byte(len(u))}
		buf = append(buf, u...)
		buf = append(buf, byte(len(p)))
		buf = append(buf, p...)
		if _, err := conn.Write(buf); err != nil {
			conn.Close()
			return nil, err
		}
		rep := make([]byte, 2)
		if _, err := io.ReadFull(conn, rep); err != nil {
			conn.Close()
			return nil, err
		}
		if rep[1] != 0 {
			conn.Close()
			return nil, fmt.Errorf("socks5: auth failed")
		}
	} else if auth[1] != 0x00 {
		conn.Close()
		return nil, fmt.Errorf("socks5: no acceptable auth")
	}
	host := []byte(destHost)
	req := []byte{0x05, 0x01, 0x00, 0x03, byte(len(host))}
	req = append(req, host...)
	portBuf := make([]byte, 2)
	binary.BigEndian.PutUint16(portBuf, uint16(destPort))
	req = append(req, portBuf...)
	if _, err := conn.Write(req); err != nil {
		conn.Close()
		return nil, err
	}
	hdr := make([]byte, 4)
	if _, err := io.ReadFull(conn, hdr); err != nil {
		conn.Close()
		return nil, err
	}
	if hdr[1] != 0 {
		conn.Close()
		return nil, fmt.Errorf("socks5: connect failed (%d)", hdr[1])
	}
	switch hdr[3] {
	case 0x01:
		_, _ = io.ReadFull(conn, make([]byte, 4+2))
	case 0x03:
		l := make([]byte, 1)
		if _, err := io.ReadFull(conn, l); err != nil {
			conn.Close()
			return nil, err
		}
		_, _ = io.ReadFull(conn, make([]byte, int(l[0])+2))
	case 0x04:
		_, _ = io.ReadFull(conn, make([]byte, 16+2))
	}
	_ = conn.SetDeadline(time.Time{})
	return conn, nil
}

func splitHostPortDefault(hostport string, defPort string) (string, string) {
	if !strings.Contains(hostport, ":") {
		return hostport, defPort
	}
	h, p, err := net.SplitHostPort(hostport)
	if err != nil {
		return hostport, defPort
	}
	return h, p
}

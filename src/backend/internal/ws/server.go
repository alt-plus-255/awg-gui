package ws

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"log"
	"net/http"
	"sync"
	"time"

	"github.com/awggui/backend/internal/stats"
	"github.com/awggui/backend/internal/store"
	"github.com/gorilla/websocket"
)

type TokenStore struct {
	Cache *store.Cache
}

func (t *TokenStore) Issue(userID int64) (string, error) {
	b := make([]byte, 24)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	token := hex.EncodeToString(b)
	t.Cache.Put(context.Background(), "ws_token:"+token, itoa64(userID), 30*time.Minute)
	return token, nil
}

func (t *TokenStore) Lookup(token string) (int64, bool) {
	val, ok := t.Cache.Get(context.Background(), "ws_token:"+token)
	if !ok || val == "" {
		return 0, false
	}
	n := int64(0)
	for _, c := range val {
		if c < '0' || c > '9' {
			return 0, false
		}
		n = n*10 + int64(c-'0')
	}
	return n, true
}

type Conn struct {
	ws *websocket.Conn
	id string
	mu sync.Mutex
}

func (c *Conn) Send(payload []byte) {
	c.mu.Lock()
	defer c.mu.Unlock()
	_ = c.ws.SetWriteDeadline(time.Now().Add(10 * time.Second))
	_ = c.ws.WriteMessage(websocket.TextMessage, payload)
}

func (c *Conn) ID() string { return c.id }

type Server struct {
	Broadcaster *stats.Broadcaster
	Tokens      *TokenStore
	upgrader    websocket.Upgrader
}

func NewServer(b *stats.Broadcaster, tokens *TokenStore) *Server {
	return &Server{
		Broadcaster: b,
		Tokens:      tokens,
		upgrader: websocket.Upgrader{
			CheckOrigin: func(r *http.Request) bool { return true },
		},
	}
}

func (s *Server) ListenAndServe(addr string) error {
	mux := http.NewServeMux()
	mux.HandleFunc("/ws", s.Handle)
	mux.HandleFunc("/ws/", s.Handle)
	go s.tickLoop()
	log.Printf("awggui websocket listening on %s", addr)
	return http.ListenAndServe(addr, mux)
}

func (s *Server) tickLoop() {
	t := time.NewTicker(s.Broadcaster.Interval())
	defer t.Stop()
	for range t.C {
		s.Broadcaster.Tick()
	}
}

func (s *Server) Handle(w http.ResponseWriter, r *http.Request) {
	token := r.URL.Query().Get("token")
	if token == "" {
		http.Error(w, "", http.StatusUnauthorized)
		return
	}
	if _, ok := s.Tokens.Lookup(token); !ok {
		http.Error(w, "", http.StatusUnauthorized)
		return
	}
	wsConn, err := s.upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	conn := &Conn{ws: wsConn, id: hexID(wsConn)}
	s.Broadcaster.Authenticate(conn)
	defer func() {
		s.Broadcaster.Detach(conn)
		_ = wsConn.Close()
	}()

	for {
		_, msg, err := wsConn.ReadMessage()
		if err != nil {
			return
		}
		s.onMessage(conn, msg)
	}
}

func (s *Server) onMessage(conn *Conn, msg []byte) {
	var data map[string]any
	if json.Unmarshal(msg, &data) != nil {
		return
	}
	action, _ := data["action"].(string)
	switch action {
	case "subscribe":
		s.Broadcaster.Subscribe(conn, intIDs(data["config_ids"]))
	case "unsubscribe":
		s.Broadcaster.Unsubscribe(conn, intIDs(data["config_ids"]))
	case "ping":
		conn.Send([]byte(`{"type":"pong"}`))
	}
}

func intIDs(v any) []int64 {
	arr, ok := v.([]any)
	if !ok {
		return nil
	}
	out := make([]int64, 0, len(arr))
	for _, x := range arr {
		switch t := x.(type) {
		case float64:
			if int64(t) > 0 {
				out = append(out, int64(t))
			}
		case json.Number:
			n, _ := t.Int64()
			if n > 0 {
				out = append(out, n)
			}
		}
	}
	return out
}

func hexID(c *websocket.Conn) string {
	b := make([]byte, 8)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func itoa64(n int64) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var buf [20]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		i--
		buf[i] = '-'
	}
	return string(buf[i:])
}

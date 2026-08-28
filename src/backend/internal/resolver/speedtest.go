package resolver

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"math"
	"net/url"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

const (
	speedJobKey       = "resolver:speed_test:job"
	speedResultsKey   = "resolver:speed_test:results"
	speedLockKey      = "resolver:speed_test_lock"
	speedJobTTL       = 6 * time.Hour
	speedResultsTTL   = 30 * 24 * time.Hour
	speedLockTTL      = 180 * time.Second
	speedPingTimeout  = 3000
)

type SpeedTest struct {
	Svc *Service
	mu  sync.Mutex
}

func (s *SpeedTest) cache() *MemCache { return s.Svc.Cache }

func (s *SpeedTest) Status() map[string]any {
	job := s.GetJob()
	running := false
	if job != nil {
		st := strVal(job["status"])
		running = st == "queued" || st == "running"
	}
	return map[string]any{"running": running, "job": job, "results": s.storedResults()}
}

func (s *SpeedTest) GetJob() map[string]any {
	v, ok := s.cache().Get(speedJobKey)
	if !ok {
		return nil
	}
	m, _ := v.(map[string]any)
	return m
}

func (s *SpeedTest) putJob(job map[string]any) {
	s.cache().Put(speedJobKey, job, speedJobTTL)
}

func (s *SpeedTest) storedResults() map[string]any {
	v, ok := s.cache().Get(speedResultsKey)
	if !ok {
		return map[string]any{"updated_at": nil, "by_key": map[string]any{}}
	}
	raw, _ := v.(map[string]any)
	if raw == nil {
		return map[string]any{"updated_at": nil, "by_key": map[string]any{}}
	}
	byKey, _ := raw["by_key"].(map[string]any)
	if byKey == nil {
		byKey = map[string]any{}
	}
	return map[string]any{"updated_at": raw["updated_at"], "by_key": byKey}
}

func (s *SpeedTest) EnqueueConnection(ctx context.Context, conn *Connection, nodeKey *string) (map[string]any, error) {
	if !conn.Enabled {
		return nil, runtimeKey("resolver.speed_test_connection_disabled")
	}
	return s.enqueue(ctx, func() map[string]any {
		ids := []int64{conn.ID}
		var nk any
		if nodeKey != nil && *nodeKey != "" {
			nk = *nodeKey
		}
		return s.newJob(map[string]any{
			"kind": "connection", "connection_id": conn.ID, "node_key": nk, "connection_ids": ids,
		})
	})
}

func (s *SpeedTest) EnqueueBatch(ctx context.Context, conns []*Connection) (map[string]any, error) {
	var ids []int64
	for _, c := range conns {
		if c != nil && c.Enabled {
			ids = append(ids, c.ID)
		}
	}
	if len(ids) == 0 {
		return nil, runtimeKey("resolver.speed_test_no_enabled")
	}
	return s.enqueue(ctx, func() map[string]any {
		return s.newJob(map[string]any{
			"kind": "batch", "connection_id": nil, "node_key": nil, "connection_ids": ids,
		})
	})
}

func (s *SpeedTest) enqueue(ctx context.Context, factory func() map[string]any) (map[string]any, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if err := s.assertNoActive(); err != nil {
		return nil, err
	}
	job := factory()
	s.putJob(job)
	id := strVal(job["id"])
	go s.processQueuedJob(context.Background(), id)
	return map[string]any{"ok": true, "async": true, "job": job}, nil
}

func (s *SpeedTest) assertNoActive() error {
	job := s.GetJob()
	if job != nil {
		st := strVal(job["status"])
		if st == "queued" || st == "running" {
			return runtimeKey("resolver.speed_test_busy")
		}
	}
	if s.cache().Has(speedLockKey) {
		return runtimeKey("resolver.speed_test_busy")
	}
	return nil
}

func (s *SpeedTest) newJob(extra map[string]any) map[string]any {
	job := map[string]any{
		"id": newJobID(), "status": "queued", "kind": "connection",
		"connection_id": nil, "node_key": nil, "connection_ids": []int64{},
		"current_connection_id": nil, "queued_at": isoNow(),
		"started_at": nil, "finished_at": nil, "error": nil,
	}
	for k, v := range extra {
		job[k] = v
	}
	return job
}

func newJobID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func (s *SpeedTest) processQueuedJob(ctx context.Context, jobID string) {
	job := s.GetJob()
	if job == nil || strVal(job["id"]) != jobID || strVal(job["status"]) != "queued" {
		return
	}
	job["status"] = "running"
	job["started_at"] = isoNow()
	s.putJob(job)
	locale := Locale(ctx)
	var runErr error
	defer func() {
		if rec := recover(); rec != nil {
			job = s.GetJob()
			if job == nil {
				return
			}
			job["status"] = "failed"
			job["finished_at"] = isoNow()
			job["current_connection_id"] = nil
			job["error"] = fmt.Sprint(rec)
			s.putJob(job)
		}
	}()
	if strVal(job["kind"]) == "batch" {
		ids := int64List(job["connection_ids"])
		for _, id := range ids {
			conn, err := s.Svc.Store.GetConnection(ctx, id)
			if err != nil || conn == nil || !conn.Enabled {
				continue
			}
			job["current_connection_id"] = conn.ID
			s.putJob(job)
			res, err := s.run(ctx, conn, nil)
			if err != nil {
				res = s.failedResult(conn, nil, err, locale)
			}
			s.storeResult(res)
		}
	} else {
		id := int64(atoiDef(strVal(job["connection_id"]), 0))
		conn, err := s.Svc.Store.GetConnection(ctx, id)
		if err != nil || conn == nil {
			runErr = runtimeKey("resolver.speed_test_connection_disabled")
		} else {
			var nk *string
			if v := strVal(job["node_key"]); v != "" {
				nk = &v
			}
			job["current_connection_id"] = conn.ID
			s.putJob(job)
			res, err := s.run(ctx, conn, nk)
			if err != nil {
				runErr = err
			} else {
				s.storeResult(res)
			}
		}
	}
	job = s.GetJob()
	if job == nil {
		return
	}
	if runErr != nil {
		job["status"] = "failed"
		job["error"] = TranslateErr(locale, runErr)
	} else {
		job["status"] = "done"
		job["error"] = nil
	}
	job["finished_at"] = isoNow()
	job["current_connection_id"] = nil
	s.putJob(job)
}

func int64List(v any) []int64 {
	switch t := v.(type) {
	case []int64:
		return t
	case []any:
		var out []int64
		for _, x := range t {
			n := int64(atoiDef(strVal(x), 0))
			if n > 0 {
				out = append(out, n)
			}
		}
		return out
	}
	return nil
}

func (s *SpeedTest) run(ctx context.Context, conn *Connection, nodeKey *string) (map[string]any, error) {
	locale := Locale(ctx)
	if !conn.Enabled {
		return nil, runtimeKey("resolver.speed_test_connection_disabled")
	}
	if !s.cache().TryPut(speedLockKey, true, speedLockTTL) {
		return nil, runtimeKey("resolver.speed_test_busy")
	}
	defer s.cache().Forget(speedLockKey)
	defer func() { _ = s.stopProbe(ctx) }()

	targetTag, outbounds, err := s.buildOutbounds(ctx, conn, nodeKey)
	if err != nil {
		return nil, err
	}
	cfg := s.buildConfig(ctx, outbounds, targetTag)
	if err := s.writeConfig(cfg); err != nil {
		return nil, err
	}
	if err := s.startProbe(ctx); err != nil {
		return nil, err
	}
	if err := s.waitForSpeedAPI(ctx); err != nil {
		return nil, err
	}

	ping := s.measurePing(ctx, targetTag)
	reachable := ping.ms != nil && *ping.ms > 0
	if !reachable {
		errMsg := i18n.T(locale, "resolver.speed_test_unreachable")
		if ping.err != nil && *ping.err != "" {
			errMsg = *ping.err
		}
		return s.resultMap(conn, nodeKey, targetTag, ping.ms, nil, nil, nil, nil, nil, nil, false, &errMsg), nil
	}

	down := s.measureDownload(ctx)
	up := s.measureUpload(ctx)
	ok := (down.mbps != nil && *down.mbps > 0) || (up.mbps != nil && *up.mbps > 0)
	var errMsg *string
	var errs []string
	if down.err != nil && *down.err != "" {
		errs = append(errs, *down.err)
	}
	if up.err != nil && *up.err != "" {
		errs = append(errs, *up.err)
	}
	if len(errs) > 0 {
		msg := strings.Join(errs, "; ")
		errMsg = &msg
	}
	return s.resultMap(conn, nodeKey, targetTag, ping.ms, down.mbps, up.mbps, down.bytes, up.bytes, down.ms, up.ms, ok, errMsg), nil
}

type speedSample struct {
	mbps  *float64
	bytes *int
	ms    *int
	err   *string
}

type pingSample struct {
	ms  *int
	err *string
}

func (s *SpeedTest) resultMap(conn *Connection, nodeKey *string, tag string, pingMS *int, downMbps, upMbps *float64, downBytes, upBytes, downMS, upMS *int, ok bool, errMsg *string) map[string]any {
	var nk any
	if nodeKey != nil && *nodeKey != "" {
		nk = *nodeKey
	}
	var ping any
	if pingMS != nil {
		ping = *pingMS
	}
	return map[string]any{
		"ok": ok, "outbound_tag": tag, "connection_id": conn.ID, "node_key": nk,
		"ping_ms": ping, "download_mbps": downMbps, "upload_mbps": upMbps,
		"download_bytes": downBytes, "upload_bytes": upBytes, "download_ms": downMS, "upload_ms": upMS,
		"error": errMsg,
	}
}

func (s *SpeedTest) failedResult(conn *Connection, nodeKey *string, err error, locale string) map[string]any {
	msg := TranslateErr(locale, err)
	tag := conn.OutboundTag()
	return s.resultMap(conn, nodeKey, tag, nil, nil, nil, nil, nil, nil, nil, false, &msg)
}

func (s *SpeedTest) buildOutbounds(ctx context.Context, conn *Connection, nodeKey *string) (string, []map[string]any, error) {
	if nodeKey != nil && *nodeKey != "" {
		tag := s.Svc.Builder.ResolveNodeTag(conn, *nodeKey)
		if tag == nil {
			return "", nil, runtimeKey("resolver.speed_test_node_not_found")
		}
		ob, err := s.outboundForNodeKey(ctx, conn, *nodeKey, *tag)
		if err != nil {
			return "", nil, err
		}
		return *tag, []map[string]any{
			{"type": "direct", "tag": "direct"},
			ob,
		}, nil
	}
	built := s.Svc.Builder.BuildForConnections([]*Connection{conn})
	tag := conn.OutboundTag()
	if !built.TagsAdded[tag] {
		return "", nil, runtimeKey("resolver.speed_test_no_outbound")
	}
	return tag, built.Outbounds, nil
}

func (s *SpeedTest) outboundForNodeKey(ctx context.Context, conn *Connection, nodeKey, tag string) (map[string]any, error) {
	if conn.IsURLTestMode() {
		for _, n := range conn.SubscriptionNodes {
			if n == nil || strVal(n["key"]) != nodeKey {
				continue
			}
			ob, _ := n["outbound"].(map[string]any)
			if ob == nil || strVal(ob["type"]) == "" {
				break
			}
			norm, err := s.Svc.Parser.Normalize(cloneMap(ob))
			if err != nil {
				break
			}
			delete(norm, "tag")
			norm["tag"] = tag
			return norm, nil
		}
		return nil, runtimeKey("resolver.speed_test_node_not_found")
	}
	ob := conn.Outbound
	if ob == nil || strVal(ob["type"]) == "" {
		return nil, runtimeKey("resolver.speed_test_no_outbound")
	}
	norm, err := s.Svc.Parser.Normalize(cloneMap(ob))
	if err != nil {
		return nil, err
	}
	norm["tag"] = tag
	return norm, nil
}

func (s *SpeedTest) buildConfig(ctx context.Context, outbounds []map[string]any, finalTag string) map[string]any {
	return map[string]any{
		"log": map[string]any{"level": "warn", "timestamp": true},
		"dns": map[string]any{
			"servers": []map[string]any{{"type": "udp", "tag": "bootstrap", "server": "8.8.8.8", "server_port": 53}},
			"final":   "bootstrap", "strategy": "ipv4_only",
		},
		"inbounds": []map[string]any{{
			"type": "mixed", "tag": SpeedMixedTag,
			"listen": SpeedMixedListen, "listen_port": SpeedMixedPort,
		}},
		"outbounds": outbounds,
		"route": map[string]any{
			"rules": []map[string]any{{
				"inbound": []string{SpeedMixedTag}, "action": "route", "outbound": finalTag,
			}},
			"final": finalTag, "auto_detect_interface": false,
			"default_interface": s.Svc.Egress.Resolve(ctx), "default_domain_resolver": "bootstrap",
		},
		"experimental": map[string]any{
			"clash_api": map[string]any{"external_controller": ClashSpeedAPIAddr, "default_mode": "rule"},
		},
	}
}

func (s *SpeedTest) writeConfig(cfg map[string]any) error {
	js, err := json.Marshal(cfg)
	if err != nil {
		return runtimeKey("resolver.speed_test_serialize_failed")
	}
	path := s.Svc.Paths.SingBoxSpeedConfigPath()
	if err := os.WriteFile(path, append(js, '\n'), 0o644); err != nil {
		return runtimeKey("resolver.speed_test_write_failed")
	}
	return nil
}

func (s *SpeedTest) startProbe(ctx context.Context) error {
	_ = s.stopProbe(ctx)
	script := `set -e
CONFIG=/config/sing-box-speed.json
PIDFILE=/run/sing-box-speed.pid
BIN=/usr/local/bin/sing-box
LOG=/config/sing-box-speed.log
LOG_MAX_BYTES=$((10 * 1024 * 1024))
"$BIN" check -c "$CONFIG"
if [ -f "$LOG" ]; then
  size=$(wc -c < "$LOG" | tr -d '[:space:]')
  if [ -n "$size" ] && [ "$size" -gt "$LOG_MAX_BYTES" ] 2>/dev/null; then
    rm -f "$LOG.1"
    mv -f "$LOG" "$LOG.1"
  fi
fi
: >>"$LOG"
setsid "$BIN" run -c "$CONFIG" >>"$LOG" 2>&1 </dev/null &
echo $! > "$PIDFILE"
pid=$(cat "$PIDFILE")
for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  if kill -0 "$pid" 2>/dev/null; then
    exit 0
  fi
  sleep 0.2
done
echo "speed probe failed to stay up" >&2
tail -n 40 "$LOG" >&2 || true
exit 1`
	r, err := s.Svc.Docker.Exec(ctx, s.Svc.Cfg.AWGContainer, []string{"bash", "-lc", script}, 30*time.Second)
	if err != nil {
		return err
	}
	if !r.Successful() {
		msg := strings.TrimSpace(r.Stderr + "\n" + r.Stdout)
		if msg == "" {
			return runtimeKey("resolver.speed_test_start_failed")
		}
		return fmt.Errorf("%s", msg)
	}
	return nil
}

func (s *SpeedTest) stopProbe(ctx context.Context) error {
	script := `PIDFILE=/run/sing-box-speed.pid
if [ -f "$PIDFILE" ]; then
  pid=$(cat "$PIDFILE" 2>/dev/null || true)
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    kill "$pid" 2>/dev/null || true
    sleep 0.5
    kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$PIDFILE"
fi
pkill -f '/usr/local/bin/sing-box run -c /config/sing-box-speed.json' 2>/dev/null || true`
	r, err := s.Svc.Docker.Exec(ctx, s.Svc.Cfg.AWGContainer, []string{"bash", "-lc", script}, 15*time.Second)
	if err != nil {
		return err
	}
	if !r.Successful() {
		return fmt.Errorf("%s", strings.TrimSpace(r.Stderr+r.Stdout))
	}
	return nil
}

func (s *SpeedTest) waitForSpeedAPI(ctx context.Context) error {
	addr := ClashSpeedAPIAddr
	for i := 0; i < 40; i++ {
		r, err := s.Svc.Docker.Exec(ctx, s.Svc.Cfg.AWGContainer,
			[]string{"curl", "-sS", "-m", "2", "http://" + addr + "/version"}, 5*time.Second)
		if err == nil && r.Successful() && strings.Contains(r.Stdout, "version") {
			return nil
		}
		time.Sleep(200 * time.Millisecond)
	}
	return runtimeKey("resolver.speed_test_api_not_ready")
}

func (s *SpeedTest) measurePing(ctx context.Context, tag string) pingSample {
	locale := Locale(ctx)
	q := url.Values{}
	q.Set("url", DelayTestURL)
	q.Set("timeout", strconv.Itoa(speedPingTimeout))
	path := "/proxies/" + url.PathEscape(tag) + "/delay?" + q.Encode()
	curlMax := max(5, int(math.Ceil(float64(speedPingTimeout)/1000))+2)
	r, err := s.Svc.Docker.Exec(ctx, s.Svc.Cfg.AWGContainer,
		[]string{"curl", "-sS", "-m", strconv.Itoa(curlMax), "http://" + ClashSpeedAPIAddr + path},
		time.Duration(curlMax+5)*time.Second)
	if err != nil {
		msg := err.Error()
		return pingSample{err: &msg}
	}
	var decoded map[string]any
	_ = json.Unmarshal([]byte(r.Stdout), &decoded)
	if delay := atoiDef(strVal(decoded["delay"]), 0); delay > 0 {
		return pingSample{ms: &delay}
	}
	msg := i18n.T(locale, "resolver.speed_test_unreachable")
	if decoded != nil {
		if m := strVal(decoded["message"]); m != "" {
			msg = m
		}
	}
	return pingSample{err: &msg}
}

func (s *SpeedTest) measureDownload(ctx context.Context) speedSample {
	proxy := fmt.Sprintf("socks5h://%s:%d", SpeedMixedListen, SpeedMixedPort)
	cmd := fmt.Sprintf("curl -sS -o /dev/null -m 40 -x %s -w '%%{speed_download} %%{time_total} %%{http_code} %%{size_download}' %s",
		shellQuote(proxy), shellQuote(SpeedDownURL))
	return s.parseCurlSpeed(ctx, cmd, "download")
}

func (s *SpeedTest) measureUpload(ctx context.Context) speedSample {
	proxy := fmt.Sprintf("socks5h://%s:%d", SpeedMixedListen, SpeedMixedPort)
	count := int(math.Ceil(float64(SpeedTestBytes) / 1_000_000))
	cmd := fmt.Sprintf("dd if=/dev/zero bs=1000000 count=%d 2>/dev/null | curl -sS -o /dev/null -m 45 -x %s -H %s --data-binary @- -w '%%{speed_upload} %%{time_total} %%{http_code} %%{size_upload}' %s",
		count, shellQuote(proxy), shellQuote("Content-Type: application/octet-stream"), shellQuote(SpeedUpURL))
	return s.parseCurlSpeed(ctx, cmd, "upload")
}

var curlSpeedRE = regexp.MustCompile(`([0-9.]+)\s+([0-9.]+)\s+(\d+)\s+(\d+)\s*$`)

func (s *SpeedTest) parseCurlSpeed(ctx context.Context, shellCmd, kind string) speedSample {
	locale := Locale(ctx)
	r, err := s.Svc.Docker.Exec(ctx, s.Svc.Cfg.AWGContainer, []string{"bash", "-lc", shellCmd}, 55*time.Second)
	if err != nil {
		msg := err.Error()
		return speedSample{err: &msg}
	}
	out := strings.TrimSpace(r.Stdout)
	errOut := strings.TrimSpace(r.Stderr)
	m := curlSpeedRE.FindStringSubmatch(out)
	if m == nil {
		msg := errOut
		if msg == "" {
			if out != "" {
				msg = out
			} else {
				msg = i18n.T(locale, "resolver.speed_test_"+kind+"_failed")
			}
		}
		return speedSample{err: &msg}
	}
	speedBps, _ := strconv.ParseFloat(m[1], 64)
	timeSec, _ := strconv.ParseFloat(m[2], 64)
	http := atoiDef(m[3], 0)
	size := atoiDef(m[4], 0)
	if http < 200 || http >= 300 || speedBps <= 0 {
		msg := i18n.Tf(locale, "resolver.speed_test_http_failed", map[string]string{"code": strconv.Itoa(http)})
		var ms *int
		if timeSec > 0 {
			n := int(math.Round(timeSec * 1000))
			ms = &n
		}
		var bytes *int
		if size > 0 {
			bytes = &size
		}
		return speedSample{bytes: bytes, ms: ms, err: &msg}
	}
	mbps := math.Round((speedBps*8)/1_000_000*100) / 100
	ms := int(math.Round(timeSec * 1000))
	return speedSample{mbps: &mbps, bytes: &size, ms: &ms}
}

func (s *SpeedTest) storeResult(result map[string]any) {
	id := atoiDef(strVal(result["connection_id"]), 0)
	nk := strVal(result["node_key"])
	key := itoa(id)
	if nk != "" {
		key = itoa(id) + "::" + nk
	}
	stored := s.storedResults()
	byKey, _ := stored["by_key"].(map[string]any)
	if byKey == nil {
		byKey = map[string]any{}
	}
	cloned := cloneMap(result)
	cloned["measured_at"] = isoNow()
	byKey[key] = cloned
	s.cache().Put(speedResultsKey, map[string]any{"updated_at": isoNow(), "by_key": byKey}, speedResultsTTL)
}

func (s *SpeedTest) ResultKey(connectionID int64, nodeKey *string) string {
	if nodeKey != nil && *nodeKey != "" {
		return itoa(int(connectionID)) + "::" + *nodeKey
	}
	return itoa(int(connectionID))
}

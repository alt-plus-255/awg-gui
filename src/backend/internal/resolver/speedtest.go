package resolver

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"sync"
	"time"

	"github.com/awggui/backend/internal/i18n"
)

const (
	speedJobKey     = "resolver:speed_test:job"
	speedResultsKey = "resolver:speed_test:results"
	speedJobTTL     = 6 * time.Hour
	speedResultsTTL = 30 * 24 * time.Hour
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
	defer func() {
		if rec := recover(); rec != nil {
			job = s.GetJob()
			if job == nil {
				return
			}
			job["status"] = "failed"
			job["finished_at"] = isoNow()
			job["current_connection_id"] = nil
			job["error"] = i18n.T(locale, "resolver.speed_test_stub")
			s.putJob(job)
		}
	}()
	var runErr error
	if strVal(job["kind"]) == "batch" {
		ids := int64List(job["connection_ids"])
		for _, id := range ids {
			conn, err := s.Svc.Store.GetConnection(ctx, id)
			if err != nil || conn == nil || !conn.Enabled {
				continue
			}
			job["current_connection_id"] = conn.ID
			s.putJob(job)
			res := s.runStub(ctx, conn, nil)
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
			s.storeResult(s.runStub(ctx, conn, nk))
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

func (s *SpeedTest) runStub(ctx context.Context, conn *Connection, nodeKey *string) map[string]any {
	locale := Locale(ctx)
	tag := conn.OutboundTag()
	if nodeKey != nil && *nodeKey != "" {
		if t := s.Svc.Builder.ResolveNodeTag(conn, *nodeKey); t != nil {
			tag = *t
		}
	}
	var pingMS any
	var pingErr *string
	ok := false
	if s.Svc.Clash.WaitForAPI(ctx, 3, 150*time.Millisecond) {
		d := s.Svc.Clash.TestOutboundDelay(ctx, tag, 3000, false)
		if d.OK && d.LatencyMS != nil {
			ok = true
			pingMS = *d.LatencyMS
		} else {
			pingErr = d.Error
		}
	}
	errMsg := i18n.T(locale, "resolver.speed_test_stub")
	if pingErr != nil {
		errMsg = *pingErr + " · " + errMsg
	}
	var nk any
	if nodeKey != nil && *nodeKey != "" {
		nk = *nodeKey
	}
	return map[string]any{
		"ok": ok, "outbound_tag": tag, "connection_id": conn.ID, "node_key": nk,
		"ping_ms": pingMS, "download_mbps": nil, "upload_mbps": nil,
		"download_bytes": nil, "upload_bytes": nil, "download_ms": nil, "upload_ms": nil,
		"error": errMsg,
	}
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

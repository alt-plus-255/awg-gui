package stats

import (
	"context"
	"encoding/json"
	"log"
	"sync"
	"time"
)

const BroadcastInterval = 8 * time.Second

type Sender interface {
	Send(payload []byte)
	ID() string
}

type HostCollector interface {
	Collect() map[string]any
}

type Broadcaster struct {
	Stats *Service
	Host  HostCollector

	mu         sync.Mutex
	conns      map[string]*connMeta
	configRefs map[int64]int
}

type connMeta struct {
	sender    Sender
	configIDs map[int64]bool
}

func NewBroadcaster(st *Service, host HostCollector) *Broadcaster {
	return &Broadcaster{
		Stats:      st,
		Host:       host,
		conns:      map[string]*connMeta{},
		configRefs: map[int64]int{},
	}
}

func (b *Broadcaster) Authenticate(sender Sender) {
	b.mu.Lock()
	defer b.mu.Unlock()
	b.conns[sender.ID()] = &connMeta{sender: sender, configIDs: map[int64]bool{}}
}

func (b *Broadcaster) Detach(sender Sender) {
	b.mu.Lock()
	defer b.mu.Unlock()
	meta, ok := b.conns[sender.ID()]
	if !ok {
		return
	}
	ids := make([]int64, 0, len(meta.configIDs))
	for id := range meta.configIDs {
		ids = append(ids, id)
	}
	b.unsubscribeLocked(meta, ids)
	delete(b.conns, sender.ID())
}

func (b *Broadcaster) Subscribe(sender Sender, configIDs []int64) {
	b.mu.Lock()
	meta, ok := b.conns[sender.ID()]
	if !ok {
		b.mu.Unlock()
		return
	}
	newIDs := []int64{}
	for _, id := range configIDs {
		if id <= 0 || meta.configIDs[id] {
			continue
		}
		meta.configIDs[id] = true
		newIDs = append(newIDs, id)
		b.configRefs[id]++
	}
	b.mu.Unlock()

	ctx := context.Background()
	for _, id := range newIDs {
		b.pushConfigStats(ctx, sender, id)
	}
	if len(newIDs) > 0 {
		b.pushHost(sender)
	}
}

func (b *Broadcaster) Unsubscribe(sender Sender, configIDs []int64) {
	b.mu.Lock()
	defer b.mu.Unlock()
	meta, ok := b.conns[sender.ID()]
	if !ok {
		return
	}
	b.unsubscribeLocked(meta, configIDs)
}

func (b *Broadcaster) unsubscribeLocked(meta *connMeta, configIDs []int64) {
	for _, id := range configIDs {
		if id <= 0 || !meta.configIDs[id] {
			continue
		}
		delete(meta.configIDs, id)
		b.configRefs[id]--
		if b.configRefs[id] <= 0 {
			delete(b.configRefs, id)
		}
	}
}

func (b *Broadcaster) Tick() {
	b.mu.Lock()
	n := len(b.conns)
	ids := make([]int64, 0, len(b.configRefs))
	for id, c := range b.configRefs {
		if c > 0 {
			ids = append(ids, id)
		}
	}
	b.mu.Unlock()
	if n == 0 {
		return
	}
	b.broadcastHost()
	ctx := context.Background()
	for _, id := range ids {
		b.broadcastConfigStats(ctx, id)
	}
}

func (b *Broadcaster) Interval() time.Duration { return BroadcastInterval }

func (b *Broadcaster) pushConfigStats(ctx context.Context, sender Sender, configID int64) {
	payload, err := json.Marshal(b.buildStatsPayload(ctx, configID))
	if err != nil {
		log.Printf("ws stats push failed config_id=%d err=%v", configID, err)
		return
	}
	sender.Send(payload)
}

func (b *Broadcaster) pushHost(sender Sender) {
	payload, err := json.Marshal(b.buildHostPayload())
	if err != nil {
		log.Printf("ws host push failed err=%v", err)
		return
	}
	sender.Send(payload)
}

func (b *Broadcaster) broadcastHost() {
	payload, err := json.Marshal(b.buildHostPayload())
	if err != nil {
		log.Printf("ws host broadcast failed err=%v", err)
		return
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	for _, meta := range b.conns {
		meta.sender.Send(payload)
	}
}

func (b *Broadcaster) broadcastConfigStats(ctx context.Context, configID int64) {
	payload, err := json.Marshal(b.buildStatsPayload(ctx, configID))
	if err != nil {
		log.Printf("ws stats broadcast failed config_id=%d err=%v", configID, err)
		return
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	for _, meta := range b.conns {
		if !meta.configIDs[configID] {
			continue
		}
		meta.sender.Send(payload)
	}
}

func (b *Broadcaster) buildStatsPayload(ctx context.Context, configID int64) map[string]any {
	live := b.Stats.EnrichLiveWithTotals(ctx, b.Stats.LivePeerStats(ctx, []int64{configID}), []int64{configID})
	return map[string]any{
		"type":            "stats",
		"config_id":       configID,
		"stats_available": live.StatsAvailable,
		"by_public_key":   live.ByPublicKey,
		"synced_at":       time.Now().Format(time.RFC3339),
	}
}

func (b *Broadcaster) buildHostPayload() map[string]any {
	host := map[string]any{}
	if b.Host != nil {
		host = b.Host.Collect()
	}
	return map[string]any{
		"type":      "host",
		"host":      host,
		"synced_at": time.Now().Format(time.RFC3339),
	}
}

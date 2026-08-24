package resolver

import (
	"context"
	"log"
	"time"

	"github.com/robfig/cron/v3"
)

func (s *Service) StartScheduler() *cron.Cron {
	c := cron.New()
	_, _ = c.AddFunc("@hourly", func() {
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
		defer cancel()
		if err := s.Apply(ctx, ApplyOpts{RefreshSubscriptions: true, URLTestRoutingRetry: true}); err != nil {
			log.Printf("resolver:refresh: %v", err)
		}
	})
	_, _ = c.AddFunc("* * * * *", func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		if _, err := s.Lists.SyncIfDue(ctx); err != nil {
			log.Printf("resolver:sync-lists: %v", err)
		}
		s.Probe.StopIfIdle(ctx)
		s.autoPingDue(ctx)
	})
	c.Start()
	return c
}

func (s *Service) autoPingDue(ctx context.Context) {
	conns, err := s.Store.ListConnections(ctx)
	if err != nil {
		return
	}
	now := time.Now()
	for _, conn := range conns {
		if !conn.IsPingCheckDue(now) {
			continue
		}
		func(conn *Connection) {
			defer func() {
				if rec := recover(); rec != nil {
					log.Printf("resolver:auto-ping panic conn %d: %v", conn.ID, rec)
				}
			}()
			if conn.IsSubscription() {
				if len(conn.SubscriptionNodes) == 0 {
					return
				}
				if _, err := s.Ping.PingNodes(ctx, conn, nil, false); err != nil {
					log.Printf("resolver:auto-ping conn %d: %v", conn.ID, err)
					return
				}
				fresh, _ := s.Store.GetConnection(ctx, conn.ID)
				if fresh == nil {
					return
				}
				sw := s.Ping.ApplyBestPickIfChanged(ctx, fresh)
				s.Ping.SyncActivePickAfterPing(ctx, fresh)
				if b, _ := sw["switched"].(bool); b {
					_ = s.Apply(ctx, ApplyOpts{})
					s.Probe.RebuildAndMaybeReload(ctx)
					log.Printf("resolver:auto-ping switched single node connection_id=%d pick=%v", conn.ID, sw["pick"])
				}
				return
			}
			if _, err := s.Ping.PingConnection(ctx, conn, false); err != nil {
				log.Printf("resolver:auto-ping conn %d: %v", conn.ID, err)
			}
		}(conn)
	}
}

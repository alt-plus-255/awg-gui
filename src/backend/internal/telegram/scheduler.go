package telegram

import (
	"context"
	"log"
	"time"

	"github.com/robfig/cron/v3"
)

func (s *Service) StartScheduler() *cron.Cron {
	loc := time.Local
	if s.AWG != nil {
		tz := s.AWG.ResolveTimezone(context.Background())
		if l, err := time.LoadLocation(tz); err == nil {
			loc = l
		}
	}
	c := cron.New(cron.WithLocation(loc))
	_, _ = c.AddFunc("* * * * *", func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		defer func() {
			if rec := recover(); rec != nil {
				log.Printf("telegram.notify-peers panic: %v", rec)
			}
		}()
		s.Notifier.CheckAndNotify(ctx)
	})
	_, _ = c.AddFunc("0 9 * * *", func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
		defer cancel()
		defer func() {
			if rec := recover(); rec != nil {
				log.Printf("telegram.daily-report panic: %v", rec)
			}
		}()
		if s.Report.Send(ctx) {
			log.Printf("telegram daily report sent")
		}
	})
	c.Start()
	return c
}

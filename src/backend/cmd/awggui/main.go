package main

import (
	"context"
	"log"
	"net/http"
	"os"

	"github.com/awggui/backend/internal/config"
	"github.com/awggui/backend/internal/db"
	"github.com/awggui/backend/internal/server"
)

func main() {
	cfg := config.Load()
	sqlDB, err := db.Open(cfg)
	if err != nil {
		log.Printf("database open: %v (continuing; /up still serves)", err)
	} else {
		defer sqlDB.Close()
	}

	app := server.New(cfg, sqlDB)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	if app.Stats != nil {
		go app.Stats.RunEveryMinute(ctx)
	}
	if app.WS != nil {
		go func() {
			if err := app.WS.ListenAndServe(cfg.WSAddr); err != nil {
				log.Printf("websocket: %v", err)
			}
		}()
	}
	if app.Resolver != nil {
		cron := app.Resolver.StartScheduler()
		defer cron.Stop()
	}
	if app.Telegram != nil {
		go app.Telegram.RunPoller(ctx)
		tgCron := app.Telegram.StartScheduler()
		defer tgCron.Stop()
	}

	addr := cfg.HTTPAddr
	log.Printf("awggui listening on %s", addr)
	if err := http.ListenAndServe(addr, app.Handler); err != nil {
		log.Printf("server: %v", err)
		os.Exit(1)
	}
}

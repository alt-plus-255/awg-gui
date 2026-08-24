package telegram

import (
	"context"
	"encoding/json"
	"log"
	"strconv"
	"time"
)

func (s *Service) RunPoller(ctx context.Context) {
	log.Printf("telegram poller started")
	webhookCleared := false
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}
		if !s.Settings.IsConfigured(ctx) || s.Settings.Mode(ctx) != ModePolling {
			webhookCleared = false
			sleepCtx(ctx, 15*time.Second)
			continue
		}
		if !s.Bot.IsReady(ctx) {
			sleepCtx(ctx, 15*time.Second)
			continue
		}
		if !webhookCleared {
			deleted := s.Bot.DeleteWebhook(ctx, false)
			if !resultOK(deleted) {
				log.Printf("telegram.deleteWebhook_failed: %s", strResult(deleted, "error"))
			}
			webhookCleared = true
		}
		offset := 0
		if raw, ok := s.Cache.Get(ctx, "telegram.updates.offset"); ok {
			offset, _ = strconv.Atoi(raw)
		}
		pollStarted := time.Now()
		response := s.Bot.GetUpdates(ctx, offset, 25)
		if !resultOK(response) {
			log.Printf("telegram.getUpdates_failed: %s", strResult(response, "error"))
			sleepCtx(ctx, 2*time.Second)
			continue
		}
		updates, _ := response["result"].([]any)
		for _, raw := range updates {
			update, ok := raw.(map[string]any)
			if !ok {
				continue
			}
			updateID := int(asInt64(update["update_id"]))
			if updateID >= offset {
				offset = updateID + 1
				s.Cache.PutForever(ctx, "telegram.updates.offset", strconv.Itoa(offset))
			}
			func() {
				defer func() {
					if rec := recover(); rec != nil {
						log.Printf("telegram.update_failed update_id=%d: %v", updateID, rec)
					}
				}()
				s.Router.Handle(ctx, update)
			}()
		}
		if len(updates) == 0 && time.Since(pollStarted) < 3*time.Second {
			sleepCtx(ctx, 2*time.Second)
		}
	}
}

func sleepCtx(ctx context.Context, d time.Duration) {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	}
}

func decodeUpdate(raw []byte) (map[string]any, error) {
	var m map[string]any
	if err := json.Unmarshal(raw, &m); err != nil {
		return nil, err
	}
	return m, nil
}

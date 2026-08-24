package telegram

import (
	"context"
	"encoding/json"
	"time"

	"github.com/awggui/backend/internal/store"
)

const convTTL = 900 * time.Second

type Conversation struct {
	Step string         `json:"step"`
	Data map[string]any `json:"data"`
}

type ConversationStore struct {
	Cache *store.Cache
}

func (s *ConversationStore) key(chatID string) string {
	return "telegram.conv." + chatID
}

func (s *ConversationStore) Get(ctx context.Context, chatID string) *Conversation {
	raw, ok := s.Cache.Get(ctx, s.key(chatID))
	if !ok {
		return nil
	}
	var v Conversation
	if json.Unmarshal([]byte(raw), &v) != nil || v.Step == "" {
		return nil
	}
	if v.Data == nil {
		v.Data = map[string]any{}
	}
	return &v
}

func (s *ConversationStore) Put(ctx context.Context, chatID, step string, data map[string]any) {
	if data == nil {
		data = map[string]any{}
	}
	b, _ := json.Marshal(Conversation{Step: step, Data: data})
	s.Cache.Put(ctx, s.key(chatID), string(b), convTTL)
}

func (s *ConversationStore) Clear(ctx context.Context, chatID string) {
	s.Cache.Forget(ctx, s.key(chatID))
}

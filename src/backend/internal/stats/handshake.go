package stats

import (
	"context"

	"github.com/awggui/backend/internal/models"
)

const (
	handshakeByteLimit      = 10 * 1024 * 1024
	handshakeByteTrimTarget = 9 * 1024 * 1024
	handshakeRowOverhead    = 96
)

func estimateByteSize(publicKey string, endpoint *string) int {
	n := handshakeRowOverhead + len(publicKey)
	if endpoint != nil {
		n += len(*endpoint)
	}
	return n
}

func (s *Service) recordHandshake(ctx context.Context, cfg *models.AwgConfig, m *models.AwgConfigPeer, handshakeAt int64, endpoint *string) {
	if s.Handshakes == nil || cfg == nil || !cfg.HandshakeLoggingEnabled || handshakeAt <= 0 {
		return
	}
	byteSize := estimateByteSize(m.PublicKey, endpoint)
	log := &models.AwgHandshakeLog{
		AwgConfigID:     cfg.ID,
		AwgConfigPeerID: &m.ID,
		VpnClientID:     &m.VpnClientID,
		PublicKey:       m.PublicKey,
		Endpoint:        endpoint,
		HandshakeAt:     handshakeAt,
		ByteSize:        byteSize,
	}
	if err := s.Handshakes.Create(ctx, log); err != nil {
		return
	}
	cfg.HandshakeLogBytes += int64(byteSize)
	_ = s.Configs.Update(ctx, cfg)
	s.trimHandshakeLogs(ctx, cfg)
}

func (s *Service) trimHandshakeLogs(ctx context.Context, cfg *models.AwgConfig) {
	fresh, err := s.Configs.Find(ctx, cfg.ID)
	if err != nil || fresh == nil {
		return
	}
	*cfg = *fresh
	bytes := cfg.HandshakeLogBytes
	if bytes <= handshakeByteLimit {
		return
	}
	target := int64(handshakeByteTrimTarget)
	freed := int64(0)
	for bytes-freed > target {
		batch, err := s.Handshakes.OldestBatch(ctx, cfg.ID, 100)
		if err != nil || len(batch) == 0 {
			break
		}
		ids := make([]int64, 0, len(batch))
		for _, row := range batch {
			ids = append(ids, row.ID)
			freed += int64(row.ByteSize)
			if bytes-freed <= target {
				break
			}
		}
		_ = s.Handshakes.DeleteIDs(ctx, ids)
	}
	remaining, err := s.Handshakes.SumBytes(ctx, cfg.ID)
	if err != nil {
		return
	}
	cfg.HandshakeLogBytes = remaining
	_ = s.Configs.Update(ctx, cfg)
}

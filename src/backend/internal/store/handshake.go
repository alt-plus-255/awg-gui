package store

import (
	"context"
	"database/sql"

	"github.com/awggui/backend/internal/models"
)

type Handshakes struct {
	DB *sql.DB
}

func NewHandshakes(db *sql.DB) *Handshakes {
	return &Handshakes{DB: db}
}

func (s *Handshakes) Create(ctx context.Context, log *models.AwgHandshakeLog) error {
	res, err := s.DB.ExecContext(ctx, `
INSERT INTO awg_handshake_logs (
  awg_config_id, awg_config_peer_id, vpn_client_id, public_key, endpoint,
  handshake_at, byte_size, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())`,
		log.AwgConfigID, nullInt64(log.AwgConfigPeerID), nullInt64(log.VpnClientID),
		log.PublicKey, nullString(log.Endpoint), log.HandshakeAt, log.ByteSize,
	)
	if err != nil {
		return err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return err
	}
	log.ID = id
	return nil
}

func (s *Handshakes) List(ctx context.Context, configID int64, vpnClientID, beforeID *int64, limit int) ([]models.AwgHandshakeLog, error) {
	q := `
SELECT l.id, l.awg_config_id, l.awg_config_peer_id, l.vpn_client_id, l.public_key,
       l.endpoint, l.handshake_at, l.byte_size, l.created_at, c.name
FROM awg_handshake_logs l
LEFT JOIN vpn_clients c ON c.id = l.vpn_client_id
WHERE l.awg_config_id = ?`
	args := []any{configID}
	if vpnClientID != nil {
		q += ` AND l.vpn_client_id = ?`
		args = append(args, *vpnClientID)
	}
	if beforeID != nil {
		q += ` AND l.id < ?`
		args = append(args, *beforeID)
	}
	q += ` ORDER BY l.id DESC LIMIT ?`
	args = append(args, limit)

	rows, err := s.DB.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []models.AwgHandshakeLog
	for rows.Next() {
		var l models.AwgHandshakeLog
		var peerID, clientID sql.NullInt64
		var endpoint, name sql.NullString
		if err := rows.Scan(
			&l.ID, &l.AwgConfigID, &peerID, &clientID, &l.PublicKey,
			&endpoint, &l.HandshakeAt, &l.ByteSize, &l.CreatedAt, &name,
		); err != nil {
			return nil, err
		}
		l.AwgConfigPeerID = ptrInt64(peerID)
		l.VpnClientID = ptrInt64(clientID)
		l.Endpoint = ptrString(endpoint)
		l.PeerName = ptrString(name)
		out = append(out, l)
	}
	return out, rows.Err()
}

func (s *Handshakes) OldestBatch(ctx context.Context, configID int64, limit int) ([]models.AwgHandshakeLog, error) {
	rows, err := s.DB.QueryContext(ctx, `
SELECT id, byte_size FROM awg_handshake_logs
WHERE awg_config_id = ? ORDER BY id LIMIT ?`, configID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []models.AwgHandshakeLog
	for rows.Next() {
		var l models.AwgHandshakeLog
		if err := rows.Scan(&l.ID, &l.ByteSize); err != nil {
			return nil, err
		}
		out = append(out, l)
	}
	return out, rows.Err()
}

func (s *Handshakes) DeleteIDs(ctx context.Context, ids []int64) error {
	if len(ids) == 0 {
		return nil
	}
	q := `DELETE FROM awg_handshake_logs WHERE id IN (`
	args := make([]any, len(ids))
	for i, id := range ids {
		if i > 0 {
			q += `,`
		}
		q += `?`
		args[i] = id
	}
	q += `)`
	_, err := s.DB.ExecContext(ctx, q, args...)
	return err
}

func (s *Handshakes) DeleteByConfig(ctx context.Context, configID int64) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM awg_handshake_logs WHERE awg_config_id = ?`, configID)
	return err
}

func (s *Handshakes) SumBytes(ctx context.Context, configID int64) (int64, error) {
	var n sql.NullInt64
	err := s.DB.QueryRowContext(ctx, `
SELECT SUM(byte_size) FROM awg_handshake_logs WHERE awg_config_id = ?`, configID).Scan(&n)
	if err != nil {
		return 0, err
	}
	if !n.Valid {
		return 0, nil
	}
	return n.Int64, nil
}

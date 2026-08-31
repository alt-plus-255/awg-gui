package store

import (
	"context"
	"database/sql"
	"errors"

	"github.com/awggui/backend/internal/models"
)

const peerCols = `id, awg_config_id, vpn_client_id, enabled, private_key, public_key, preshared_key,
address, extra_allowed_ips, excluded_client_ids, exclusions_mutual, keepalive,
forward_policy, forward_allowed_cidrs,
runtime_endpoint, latest_handshake, transfer_rx, transfer_tx,
traffic_rx_total, traffic_tx_total, traffic_rx_baseline, traffic_tx_baseline,
traffic_reset_at, online, stats_synced_at, created_at, updated_at`

type Peers struct {
	DB *sql.DB
}

func NewPeers(db *sql.DB) *Peers {
	return &Peers{DB: db}
}

func (s *Peers) ListAll(ctx context.Context) ([]models.AwgConfigPeer, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+peerCols+` FROM awg_config_peers ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanPeers(rows)
}

func (s *Peers) ListByConfigIDs(ctx context.Context, ids []int64) ([]models.AwgConfigPeer, error) {
	if len(ids) == 0 {
		return []models.AwgConfigPeer{}, nil
	}
	q := `SELECT ` + peerCols + ` FROM awg_config_peers WHERE awg_config_id IN (`
	args := make([]any, len(ids))
	for i, id := range ids {
		if i > 0 {
			q += `,`
		}
		q += `?`
		args[i] = id
	}
	q += `) ORDER BY id`
	rows, err := s.DB.QueryContext(ctx, q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanPeers(rows)
}

func (s *Peers) Count(ctx context.Context, configIDs []int64) (int, error) {
	return s.count(ctx, configIDs, false)
}

func (s *Peers) CountEnabled(ctx context.Context, configIDs []int64) (int, error) {
	return s.count(ctx, configIDs, true)
}

func (s *Peers) count(ctx context.Context, configIDs []int64, enabledOnly bool) (int, error) {
	q := `SELECT COUNT(*) FROM awg_config_peers`
	args := []any{}
	where := []string{}
	if configIDs != nil {
		if len(configIDs) == 0 {
			return 0, nil
		}
		in := `awg_config_id IN (`
		for i, id := range configIDs {
			if i > 0 {
				in += `,`
			}
			in += `?`
			args = append(args, id)
		}
		in += `)`
		where = append(where, in)
	}
	if enabledOnly {
		where = append(where, `enabled = 1`)
	}
	if len(where) > 0 {
		q += ` WHERE ` + where[0]
		for i := 1; i < len(where); i++ {
			q += ` AND ` + where[i]
		}
	}
	var n int
	err := s.DB.QueryRowContext(ctx, q, args...).Scan(&n)
	return n, err
}

func (s *Peers) ListByConfig(ctx context.Context, configID int64) ([]models.AwgConfigPeer, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+peerCols+` FROM awg_config_peers WHERE awg_config_id = ? ORDER BY id`, configID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanPeers(rows)
}

func (s *Peers) ListEnabledByConfig(ctx context.Context, configID int64) ([]models.AwgConfigPeer, error) {
	rows, err := s.DB.QueryContext(ctx, `
SELECT `+peerCols+` FROM awg_config_peers WHERE awg_config_id = ? AND enabled = 1 ORDER BY id`, configID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanPeers(rows)
}

func (s *Peers) ListByClient(ctx context.Context, clientID int64) ([]models.AwgConfigPeer, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+peerCols+` FROM awg_config_peers WHERE vpn_client_id = ? ORDER BY id`, clientID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanPeers(rows)
}

func (s *Peers) ConfigIDsForClient(ctx context.Context, clientID int64) ([]int64, error) {
	rows, err := s.DB.QueryContext(ctx, `
SELECT DISTINCT awg_config_id FROM awg_config_peers WHERE vpn_client_id = ?`, clientID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		out = append(out, id)
	}
	return out, rows.Err()
}

func (s *Peers) Find(ctx context.Context, id int64) (*models.AwgConfigPeer, error) {
	row := s.DB.QueryRowContext(ctx, `SELECT `+peerCols+` FROM awg_config_peers WHERE id = ?`, id)
	p, err := scanPeer(row)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

func (s *Peers) FindMembership(ctx context.Context, configID, clientID int64) (*models.AwgConfigPeer, error) {
	row := s.DB.QueryRowContext(ctx, `
SELECT `+peerCols+` FROM awg_config_peers WHERE awg_config_id = ? AND vpn_client_id = ?`, configID, clientID)
	p, err := scanPeer(row)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

func (s *Peers) ExistsMembership(ctx context.Context, configID, clientID int64) (bool, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `
SELECT COUNT(*) FROM awg_config_peers WHERE awg_config_id = ? AND vpn_client_id = ?`, configID, clientID).Scan(&n)
	return n > 0, err
}

func (s *Peers) Addresses(ctx context.Context, configID int64) ([]string, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT address FROM awg_config_peers WHERE awg_config_id = ?`, configID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var a string
		if err := rows.Scan(&a); err != nil {
			return nil, err
		}
		out = append(out, a)
	}
	return out, rows.Err()
}

func (s *Peers) AttachedClientIDs(ctx context.Context, configID int64) ([]int64, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT vpn_client_id FROM awg_config_peers WHERE awg_config_id = ?`, configID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		out = append(out, id)
	}
	return out, rows.Err()
}

func (s *Peers) Create(ctx context.Context, p *models.AwgConfigPeer) error {
	res, err := s.DB.ExecContext(ctx, `
INSERT INTO awg_config_peers (
  awg_config_id, vpn_client_id, enabled, private_key, public_key, preshared_key,
  address, extra_allowed_ips, excluded_client_ids, exclusions_mutual, keepalive,
  forward_policy, forward_allowed_cidrs,
  created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		p.AwgConfigID, p.VpnClientID, boolToInt(p.Enabled), p.PrivateKey, p.PublicKey, nullString(p.PresharedKey),
		p.Address, marshalJSON(p.ExtraAllowedIPs), marshalJSON(p.ExcludedClientIDs), boolToInt(p.ExclusionsMutual), nullInt(p.Keepalive),
		stringOrDefault(p.ForwardPolicy, "allow_all"), marshalJSON(p.ForwardAllowedCIDRs),
	)
	if err != nil {
		return err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return err
	}
	p.ID = id
	return nil
}

func (s *Peers) Update(ctx context.Context, p *models.AwgConfigPeer) error {
	_, err := s.DB.ExecContext(ctx, `
UPDATE awg_config_peers SET
  enabled=?, private_key=?, public_key=?, preshared_key=?, address=?,
  extra_allowed_ips=?, excluded_client_ids=?, exclusions_mutual=?, keepalive=?,
  forward_policy=?, forward_allowed_cidrs=?,
  runtime_endpoint=?, latest_handshake=?, transfer_rx=?, transfer_tx=?,
  traffic_rx_total=?, traffic_tx_total=?, traffic_rx_baseline=?, traffic_tx_baseline=?,
  traffic_reset_at=?, online=?, stats_synced_at=?, updated_at=NOW()
WHERE id=?`,
		boolToInt(p.Enabled), p.PrivateKey, p.PublicKey, nullString(p.PresharedKey), p.Address,
		marshalJSON(p.ExtraAllowedIPs), marshalJSON(p.ExcludedClientIDs), boolToInt(p.ExclusionsMutual), nullInt(p.Keepalive),
		stringOrDefault(p.ForwardPolicy, "allow_all"), marshalJSON(p.ForwardAllowedCIDRs),
		nullString(p.RuntimeEndpoint), nullInt64(p.LatestHandshake), p.TransferRx, p.TransferTx,
		p.TrafficRxTotal, p.TrafficTxTotal, p.TrafficRxBaseline, p.TrafficTxBaseline,
		nullTime(p.TrafficResetAt), nullOnline(p.Online), nullTime(p.StatsSyncedAt),
		p.ID,
	)
	return err
}

func (s *Peers) DeleteMembership(ctx context.Context, configID, clientID int64) error {
	_, err := s.DB.ExecContext(ctx, `
DELETE FROM awg_config_peers WHERE awg_config_id = ? AND vpn_client_id = ?`, configID, clientID)
	return err
}

func scanPeers(rows *sql.Rows) ([]models.AwgConfigPeer, error) {
	var out []models.AwgConfigPeer
	for rows.Next() {
		p, err := scanPeer(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

func scanPeer(row rowScanner) (models.AwgConfigPeer, error) {
	var p models.AwgConfigPeer
	var psk, extra, excluded, endpoint sql.NullString
	var extraRaw, excludedRaw, forwardCIDRsRaw []byte
	var keepalive, handshake sql.NullInt64
	var resetAt, synced sql.NullTime
	var online sql.NullBool
	err := row.Scan(
		&p.ID, &p.AwgConfigID, &p.VpnClientID, &p.Enabled, &p.PrivateKey, &p.PublicKey, &psk,
		&p.Address, &extraRaw, &excludedRaw, &p.ExclusionsMutual, &keepalive,
		&p.ForwardPolicy, &forwardCIDRsRaw,
		&endpoint, &handshake, &p.TransferRx, &p.TransferTx,
		&p.TrafficRxTotal, &p.TrafficTxTotal, &p.TrafficRxBaseline, &p.TrafficTxBaseline,
		&resetAt, &online, &synced, &p.CreatedAt, &p.UpdatedAt,
	)
	if err != nil {
		return p, err
	}
	_ = extra
	_ = excluded
	p.PresharedKey = ptrString(psk)
	p.ExtraAllowedIPs = scanJSONSlice[string](extraRaw)
	p.ExcludedClientIDs = scanJSONSlice[int64](excludedRaw)
	p.ForwardAllowedCIDRs = scanJSONSlice[string](forwardCIDRsRaw)
	if p.ForwardPolicy == "" {
		p.ForwardPolicy = "allow_all"
	}
	p.Keepalive = ptrInt(keepalive)
	p.RuntimeEndpoint = ptrString(endpoint)
	p.LatestHandshake = ptrInt64(handshake)
	p.TrafficResetAt = ptrTime(resetAt)
	p.Online = ptrBool(online)
	p.StatsSyncedAt = ptrTime(synced)
	return p, nil
}

func nullOnline(v *bool) any {
	if v == nil {
		return nil
	}
	return boolToInt(*v)
}

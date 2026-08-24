package store

import (
	"context"
	"database/sql"
	"errors"

	"github.com/awggui/backend/internal/models"
)

const configCols = `id, name, type, protocol_version, client_import_name_style, vn_policy, vn_zones,
iface, listen_port, internal_subnet, server_address, server_private_key, server_public_key,
peer_dns, resolver_dns, client_allowed_ips, persistent_keepalive, enabled,
handshake_logging_enabled, handshake_log_bytes, resolver_enabled, resolver_reject_quic,
community_lists, user_domains, user_subnets, resolver_updated_at, resolver_last_error,
connection_id, jc, jmin, jmax, s1, s2, s3, s4, h1, h2, h3, h4, i1, i2, i3, i4, i5,
created_at, updated_at`

type Configs struct {
	DB *sql.DB
}

func NewConfigs(db *sql.DB) *Configs {
	return &Configs{DB: db}
}

func (s *Configs) List(ctx context.Context) ([]models.AwgConfig, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+configCols+`,
(SELECT COUNT(*) FROM awg_config_peers p WHERE p.awg_config_id = awg_configs.id) AS peers_count
FROM awg_configs ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []models.AwgConfig
	for rows.Next() {
		c, err := scanConfig(rows, true)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

func (s *Configs) ListEnabled(ctx context.Context) ([]models.AwgConfig, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+configCols+` FROM awg_configs WHERE enabled = 1 ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []models.AwgConfig
	for rows.Next() {
		c, err := scanConfig(rows, false)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

func (s *Configs) All(ctx context.Context) ([]models.AwgConfig, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+configCols+` FROM awg_configs ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []models.AwgConfig
	for rows.Next() {
		c, err := scanConfig(rows, false)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

func (s *Configs) Find(ctx context.Context, id int64) (*models.AwgConfig, error) {
	row := s.DB.QueryRowContext(ctx, `SELECT `+configCols+`,
(SELECT COUNT(*) FROM awg_config_peers p WHERE p.awg_config_id = awg_configs.id) AS peers_count
FROM awg_configs WHERE id = ?`, id)
	c, err := scanConfig(row, true)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &c, nil
}

func (s *Configs) FirstEnabled(ctx context.Context) (*models.AwgConfig, error) {
	row := s.DB.QueryRowContext(ctx, `SELECT `+configCols+` FROM awg_configs WHERE enabled = 1 ORDER BY id LIMIT 1`)
	c, err := scanConfig(row, false)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &c, nil
}

func (s *Configs) First(ctx context.Context) (*models.AwgConfig, error) {
	row := s.DB.QueryRowContext(ctx, `SELECT `+configCols+` FROM awg_configs ORDER BY id LIMIT 1`)
	c, err := scanConfig(row, false)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &c, nil
}

func (s *Configs) Exists(ctx context.Context) (bool, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM awg_configs`).Scan(&n)
	return n > 0, err
}

func (s *Configs) Count(ctx context.Context) (int, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM awg_configs`).Scan(&n)
	return n, err
}

func (s *Configs) Ifaces(ctx context.Context) ([]string, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT iface FROM awg_configs`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var v string
		if err := rows.Scan(&v); err != nil {
			return nil, err
		}
		out = append(out, v)
	}
	return out, rows.Err()
}

func (s *Configs) ListenPorts(ctx context.Context) ([]int, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT listen_port FROM awg_configs`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []int
	for rows.Next() {
		var v int
		if err := rows.Scan(&v); err != nil {
			return nil, err
		}
		out = append(out, v)
	}
	return out, rows.Err()
}

func (s *Configs) PortTaken(ctx context.Context, port int, ignoreID int64) (bool, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `
SELECT COUNT(*) FROM awg_configs WHERE listen_port = ? AND id != ?`, port, ignoreID).Scan(&n)
	return n > 0, err
}

func (s *Configs) Subnets(ctx context.Context, ignoreID int64) ([]string, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT internal_subnet FROM awg_configs WHERE id != ? ORDER BY id`, ignoreID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var v string
		if err := rows.Scan(&v); err != nil {
			return nil, err
		}
		out = append(out, v)
	}
	return out, rows.Err()
}

func (s *Configs) Create(ctx context.Context, c *models.AwgConfig) error {
	res, err := s.DB.ExecContext(ctx, `
INSERT INTO awg_configs (
  name, type, protocol_version, client_import_name_style, vn_policy, vn_zones,
  iface, listen_port, internal_subnet, server_address, server_private_key, server_public_key,
  peer_dns, client_allowed_ips, persistent_keepalive, enabled,
  handshake_logging_enabled, handshake_log_bytes, resolver_enabled, resolver_reject_quic,
  community_lists, user_domains, user_subnets, connection_id,
  jc, jmin, jmax, s1, s2, s3, s4, h1, h2, h3, h4, i1, i2, i3, i4, i5,
  created_at, updated_at
) VALUES (
  ?, ?, ?, ?, ?, ?,
  ?, ?, ?, ?, ?, ?,
  ?, ?, ?, ?,
  ?, ?, ?, ?,
  ?, ?, ?, ?,
  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
  NOW(), NOW()
)`,
		c.Name, c.Type, c.ProtocolVersion, c.ClientImportNameStyle, c.VnPolicy, nullableJSON(c.VnZonesRaw),
		c.Iface, c.ListenPort, c.InternalSubnet, c.ServerAddress, c.ServerPrivateKey, c.ServerPublicKey,
		c.PeerDNS, c.ClientAllowedIPs, c.PersistentKeepalive, boolToInt(c.Enabled),
		boolToInt(c.HandshakeLoggingEnabled), c.HandshakeLogBytes, boolToInt(c.ResolverEnabled), boolToInt(c.ResolverRejectQuic),
		marshalJSON(c.CommunityLists), marshalJSON(c.UserDomains), marshalJSON(c.UserSubnets), nullInt64(c.ConnectionID),
		c.Jc, c.Jmin, c.Jmax, c.S1, c.S2, c.S3, c.S4, c.H1, c.H2, c.H3, c.H4,
		nullString(c.I1), nullString(c.I2), nullString(c.I3), nullString(c.I4), nullString(c.I5),
	)
	if err != nil {
		return err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return err
	}
	c.ID = id
	return nil
}

func (s *Configs) Update(ctx context.Context, c *models.AwgConfig) error {
	_, err := s.DB.ExecContext(ctx, `
UPDATE awg_configs SET
  name=?, type=?, protocol_version=?, client_import_name_style=?, vn_policy=?, vn_zones=?,
  iface=?, listen_port=?, internal_subnet=?, server_address=?, server_private_key=?, server_public_key=?,
  peer_dns=?, client_allowed_ips=?, persistent_keepalive=?, enabled=?,
  handshake_logging_enabled=?, handshake_log_bytes=?, resolver_enabled=?, resolver_reject_quic=?,
  community_lists=?, user_domains=?, user_subnets=?, resolver_updated_at=?, resolver_last_error=?,
  connection_id=?,
  jc=?, jmin=?, jmax=?, s1=?, s2=?, s3=?, s4=?, h1=?, h2=?, h3=?, h4=?,
  i1=?, i2=?, i3=?, i4=?, i5=?,
  updated_at=NOW()
WHERE id=?`,
		c.Name, c.Type, c.ProtocolVersion, c.ClientImportNameStyle, c.VnPolicy, nullableJSON(c.VnZonesRaw),
		c.Iface, c.ListenPort, c.InternalSubnet, c.ServerAddress, c.ServerPrivateKey, c.ServerPublicKey,
		c.PeerDNS, c.ClientAllowedIPs, c.PersistentKeepalive, boolToInt(c.Enabled),
		boolToInt(c.HandshakeLoggingEnabled), c.HandshakeLogBytes, boolToInt(c.ResolverEnabled), boolToInt(c.ResolverRejectQuic),
		marshalJSON(c.CommunityLists), marshalJSON(c.UserDomains), marshalJSON(c.UserSubnets),
		nullTime(c.ResolverUpdatedAt), nullString(c.ResolverLastError), nullInt64(c.ConnectionID),
		c.Jc, c.Jmin, c.Jmax, c.S1, c.S2, c.S3, c.S4, c.H1, c.H2, c.H3, c.H4,
		nullString(c.I1), nullString(c.I2), nullString(c.I3), nullString(c.I4), nullString(c.I5),
		c.ID,
	)
	return err
}

func (s *Configs) Delete(ctx context.Context, id int64) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM awg_configs WHERE id = ?`, id)
	return err
}

func scanConfig(row rowScanner, withCount bool) (models.AwgConfig, error) {
	var c models.AwgConfig
	var vnZones, community, domains, subnets []byte
	var resolverDNS, lastErr sql.NullString
	var resolverUpdated sql.NullTime
	var connID sql.NullInt64
	var i1, i2, i3, i4, i5 sql.NullString
	dest := []any{
		&c.ID, &c.Name, &c.Type, &c.ProtocolVersion, &c.ClientImportNameStyle, &c.VnPolicy, &vnZones,
		&c.Iface, &c.ListenPort, &c.InternalSubnet, &c.ServerAddress, &c.ServerPrivateKey, &c.ServerPublicKey,
		&c.PeerDNS, &resolverDNS, &c.ClientAllowedIPs, &c.PersistentKeepalive, &c.Enabled,
		&c.HandshakeLoggingEnabled, &c.HandshakeLogBytes, &c.ResolverEnabled, &c.ResolverRejectQuic,
		&community, &domains, &subnets, &resolverUpdated, &lastErr,
		&connID, &c.Jc, &c.Jmin, &c.Jmax, &c.S1, &c.S2, &c.S3, &c.S4, &c.H1, &c.H2, &c.H3, &c.H4,
		&i1, &i2, &i3, &i4, &i5, &c.CreatedAt, &c.UpdatedAt,
	}
	if withCount {
		dest = append(dest, &c.PeersCount)
	}
	if err := row.Scan(dest...); err != nil {
		return c, err
	}
	c.VnZonesRaw = vnZones
	c.ResolverDNS = ptrString(resolverDNS)
	c.CommunityLists = scanJSONSlice[string](community)
	c.UserDomains = scanJSONSlice[string](domains)
	c.UserSubnets = scanJSONSlice[string](subnets)
	c.ResolverUpdatedAt = ptrTime(resolverUpdated)
	c.ResolverLastError = ptrString(lastErr)
	c.ConnectionID = ptrInt64(connID)
	c.I1, c.I2, c.I3, c.I4, c.I5 = ptrString(i1), ptrString(i2), ptrString(i3), ptrString(i4), ptrString(i5)
	return c, nil
}

func boolToInt(v bool) int {
	if v {
		return 1
	}
	return 0
}

func nullableJSON(raw []byte) any {
	if len(raw) == 0 {
		return nil
	}
	return raw
}

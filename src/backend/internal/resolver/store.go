package resolver

import (
	"context"
	"database/sql"
	"encoding/json"
	"strings"
	"time"
)

type Store struct {
	DB *sql.DB
}

const connCols = `id, name, comment, kind, config_type, share_url, subscription_url, subscription_body,
subscription_mode, subscription_selected, subscription_nodes, subscription_fetched_at, latency_cache,
subscription_active, ping_check_interval_min, ping_last_checked_at, outbound, awg_conf, protocol_version,
enabled, last_latency_ms, last_tested_at, last_test_ok, last_test_error, last_tspu_status, last_tspu_likely,
last_tspu_detail, last_tspu_meta, created_at, updated_at`

func (s *Store) ListConnections(ctx context.Context) ([]*Connection, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+connCols+`,
(SELECT COUNT(*) FROM awg_configs c WHERE c.connection_id = resolver_connections.id) AS configs_count
FROM resolver_connections ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Connection
	for rows.Next() {
		c, err := scanConnection(rows, true)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

func (s *Store) EnabledConnections(ctx context.Context) ([]*Connection, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT `+connCols+`, 0 AS configs_count
FROM resolver_connections WHERE enabled = 1 ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*Connection
	for rows.Next() {
		c, err := scanConnection(rows, true)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

func (s *Store) GetConnection(ctx context.Context, id int64) (*Connection, error) {
	row := s.DB.QueryRowContext(ctx, `SELECT `+connCols+`,
(SELECT COUNT(*) FROM awg_configs c WHERE c.connection_id = resolver_connections.id) AS configs_count
FROM resolver_connections WHERE id = ?`, id)
	c, err := scanConnection(row, true)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return c, err
}

func (s *Store) InsertConnection(ctx context.Context, c *Connection) (int64, error) {
	res, err := s.DB.ExecContext(ctx, `
INSERT INTO resolver_connections
(name, comment, kind, config_type, share_url, subscription_url, subscription_body, subscription_mode,
 subscription_selected, subscription_nodes, subscription_fetched_at, outbound, awg_conf, protocol_version,
 enabled, ping_check_interval_min, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		c.Name, nullStr(c.Comment), c.Kind, c.ConfigType, nullStr(c.ShareURL), nullStr(c.SubscriptionURL),
		nullStr(c.SubscriptionBody), nullStr(c.SubscriptionMode), nullStr(c.SubscriptionSelected),
		marshalJSON(c.SubscriptionNodes), nullTime(c.SubscriptionFetchedAt), marshalJSON(c.Outbound),
		nullStr(c.AWGConf), nullStr(c.ProtocolVersion), boolToInt(c.Enabled), c.PingCheckIntervalMin,
	)
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

func (s *Store) UpdateConnection(ctx context.Context, c *Connection) error {
	_, err := s.DB.ExecContext(ctx, `
UPDATE resolver_connections SET
 name=?, comment=?, kind=?, config_type=?, share_url=?, subscription_url=?, subscription_body=?,
 subscription_mode=?, subscription_selected=?, subscription_nodes=?, subscription_fetched_at=?,
 latency_cache=?, subscription_active=?, ping_check_interval_min=?, ping_last_checked_at=?,
 outbound=?, awg_conf=?, protocol_version=?, enabled=?,
 last_latency_ms=?, last_tested_at=?, last_test_ok=?, last_test_error=?,
 last_tspu_status=?, last_tspu_likely=?, last_tspu_detail=?, last_tspu_meta=?,
 updated_at=NOW()
WHERE id=?`,
		c.Name, nullStr(c.Comment), c.Kind, c.ConfigType, nullStr(c.ShareURL), nullStr(c.SubscriptionURL),
		nullStr(c.SubscriptionBody), nullStr(c.SubscriptionMode), nullStr(c.SubscriptionSelected),
		marshalJSON(c.SubscriptionNodes), nullTime(c.SubscriptionFetchedAt),
		marshalJSON(c.LatencyCache), marshalJSON(c.SubscriptionActive), c.PingCheckIntervalMin,
		nullTime(c.PingLastCheckedAt), marshalJSON(c.Outbound), nullStr(c.AWGConf), nullStr(c.ProtocolVersion),
		boolToInt(c.Enabled), nullInt(c.LastLatencyMS), nullTime(c.LastTestedAt), nullBool(c.LastTestOK),
		nullStr(c.LastTestError), nullStr(c.LastTSPUStatus), nullBool(c.LastTSPULikely),
		nullStr(c.LastTSPUDetail), marshalJSON(c.LastTSPUMeta), c.ID,
	)
	return err
}

func (s *Store) DeleteConnection(ctx context.Context, id int64) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM resolver_connections WHERE id=?`, id)
	return err
}

func (s *Store) HasEnabledConnections(ctx context.Context) (bool, error) {
	var n int
	err := s.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM resolver_connections WHERE enabled=1`).Scan(&n)
	return n > 0, err
}

func (s *Store) ListServerConfigs(ctx context.Context) ([]*AWGConfig, error) {
	rows, err := s.DB.QueryContext(ctx, `
SELECT id, name, type, enabled, iface, internal_subnet, server_address, peer_dns, resolver_dns,
 client_allowed_ips, resolver_enabled, resolver_reject_quic, connection_id, community_lists,
 user_domains, user_subnets, resolver_updated_at, resolver_last_error
FROM awg_configs WHERE type='server' ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*AWGConfig
	for rows.Next() {
		c, err := scanConfig(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	for _, c := range out {
		c.HasPeerExtraAllowed, _ = s.hasPeerExtras(ctx, c.ID)
	}
	return out, nil
}

func (s *Store) EnabledServerConfigs(ctx context.Context) ([]*AWGConfig, error) {
	all, err := s.ListServerConfigs(ctx)
	if err != nil {
		return nil, err
	}
	var out []*AWGConfig
	for _, c := range all {
		if c.ResolverEnabled && c.Enabled {
			out = append(out, c)
		}
	}
	return out, nil
}

func (s *Store) GetConfig(ctx context.Context, id int64) (*AWGConfig, error) {
	row := s.DB.QueryRowContext(ctx, `
SELECT id, name, type, enabled, iface, internal_subnet, server_address, peer_dns, resolver_dns,
 client_allowed_ips, resolver_enabled, resolver_reject_quic, connection_id, community_lists,
 user_domains, user_subnets, resolver_updated_at, resolver_last_error
FROM awg_configs WHERE id=?`, id)
	c, err := scanConfig(row)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	c.HasPeerExtraAllowed, _ = s.hasPeerExtras(ctx, c.ID)
	return c, nil
}

func (s *Store) UpdateConfigResolver(ctx context.Context, c *AWGConfig) error {
	_, err := s.DB.ExecContext(ctx, `
UPDATE awg_configs SET
 resolver_enabled=?, resolver_reject_quic=?, connection_id=?, resolver_dns=?,
 community_lists=?, user_domains=?, user_subnets=?,
 resolver_updated_at=?, resolver_last_error=?, updated_at=NOW()
WHERE id=?`,
		boolToInt(c.ResolverEnabled), boolToInt(c.ResolverRejectQUIC), nullInt64(c.ConnectionID),
		nullStr(c.ResolverDNS), marshalJSON(c.CommunityLists), marshalJSON(c.UserDomains),
		marshalJSON(c.UserSubnets), nullTime(c.ResolverUpdatedAt), nullStr(c.ResolverLastError), c.ID,
	)
	return err
}

func (s *Store) DetachCommunityTag(ctx context.Context, slug string) error {
	cfgs, err := s.ListServerConfigs(ctx)
	if err != nil {
		return err
	}
	for _, c := range cfgs {
		found := false
		next := make([]string, 0, len(c.CommunityLists))
		for _, t := range c.CommunityLists {
			if t == slug {
				found = true
				continue
			}
			next = append(next, t)
		}
		if !found {
			continue
		}
		c.CommunityLists = next
		if err := s.UpdateConfigResolver(ctx, c); err != nil {
			return err
		}
	}
	return nil
}

func (s *Store) ListCustomLists(ctx context.Context) ([]*CustomList, error) {
	rows, err := s.DB.QueryContext(ctx, `
SELECT id, name, slug, domains, cidrs, source_url, created_at, updated_at
FROM resolver_custom_lists ORDER BY id`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []*CustomList
	for rows.Next() {
		l, err := scanCustomList(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, l)
	}
	return out, rows.Err()
}

func (s *Store) GetCustomList(ctx context.Context, id int64) (*CustomList, error) {
	row := s.DB.QueryRowContext(ctx, `
SELECT id, name, slug, domains, cidrs, source_url, created_at, updated_at
FROM resolver_custom_lists WHERE id=?`, id)
	l, err := scanCustomList(row)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return l, err
}

func (s *Store) GetCustomListBySlug(ctx context.Context, slug string) (*CustomList, error) {
	row := s.DB.QueryRowContext(ctx, `
SELECT id, name, slug, domains, cidrs, source_url, created_at, updated_at
FROM resolver_custom_lists WHERE slug=?`, slug)
	l, err := scanCustomList(row)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return l, err
}

func (s *Store) InsertCustomList(ctx context.Context, l *CustomList) (int64, error) {
	res, err := s.DB.ExecContext(ctx, `
INSERT INTO resolver_custom_lists (name, slug, domains, cidrs, source_url, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, NOW(), NOW())`,
		l.Name, l.Slug, marshalJSON(l.Domains), marshalJSON(l.CIDRs), nullStr(l.SourceURL))
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

func (s *Store) UpdateCustomList(ctx context.Context, l *CustomList) error {
	_, err := s.DB.ExecContext(ctx, `
UPDATE resolver_custom_lists SET name=?, slug=?, domains=?, cidrs=?, source_url=?, updated_at=NOW()
WHERE id=?`, l.Name, l.Slug, marshalJSON(l.Domains), marshalJSON(l.CIDRs), nullStr(l.SourceURL), l.ID)
	return err
}

func (s *Store) DeleteCustomList(ctx context.Context, id int64) error {
	_, err := s.DB.ExecContext(ctx, `DELETE FROM resolver_custom_lists WHERE id=?`, id)
	return err
}

func (s *Store) hasPeerExtras(ctx context.Context, configID int64) (bool, error) {
	rows, err := s.DB.QueryContext(ctx, `SELECT extra_allowed_ips FROM awg_config_peers WHERE awg_config_id=?`, configID)
	if err != nil {
		return false, err
	}
	defer rows.Close()
	for rows.Next() {
		var raw []byte
		if err := rows.Scan(&raw); err != nil {
			return false, err
		}
		var extras []any
		unmarshalJSON(raw, &extras)
		for _, x := range extras {
			if strings.TrimSpace(strVal(x)) != "" {
				return true, nil
			}
		}
	}
	return false, rows.Err()
}

type scanner interface {
	Scan(dest ...any) error
}

func scanConnection(row scanner, withCount bool) (*Connection, error) {
	c := &Connection{}
	var comment, share, subURL, subBody, subMode, subSel, awg, proto, lastErr, tspuSt, tspuDet sql.NullString
	var nodes, latCache, subAct, outbound, tspuMeta []byte
	var fetched, pingAt, lastTest, created, updated sql.NullTime
	var lastLat sql.NullInt64
	var lastOK, tspuLikely sql.NullBool
	var enabled int
	dest := []any{
		&c.ID, &c.Name, &comment, &c.Kind, &c.ConfigType, &share, &subURL, &subBody,
		&subMode, &subSel, &nodes, &fetched, &latCache, &subAct, &c.PingCheckIntervalMin, &pingAt,
		&outbound, &awg, &proto, &enabled, &lastLat, &lastTest, &lastOK, &lastErr,
		&tspuSt, &tspuLikely, &tspuDet, &tspuMeta, &created, &updated,
	}
	if withCount {
		dest = append(dest, &c.ConfigsCount)
	}
	if err := row.Scan(dest...); err != nil {
		return nil, err
	}
	c.Comment = ns(comment)
	c.ShareURL = ns(share)
	c.SubscriptionURL = ns(subURL)
	c.SubscriptionBody = ns(subBody)
	c.SubscriptionMode = ns(subMode)
	c.SubscriptionSelected = ns(subSel)
	c.AWGConf = ns(awg)
	c.ProtocolVersion = ns(proto)
	c.LastTestError = ns(lastErr)
	c.LastTSPUStatus = ns(tspuSt)
	c.LastTSPUDetail = ns(tspuDet)
	c.Enabled = enabled != 0
	if lastLat.Valid {
		v := int(lastLat.Int64)
		c.LastLatencyMS = &v
	}
	if lastOK.Valid {
		v := lastOK.Bool
		c.LastTestOK = &v
	}
	if tspuLikely.Valid {
		v := tspuLikely.Bool
		c.LastTSPULikely = &v
	}
	c.SubscriptionFetchedAt = nt(fetched)
	c.PingLastCheckedAt = nt(pingAt)
	c.LastTestedAt = nt(lastTest)
	c.CreatedAt = nt(created)
	c.UpdatedAt = nt(updated)
	unmarshalJSON(nodes, &c.SubscriptionNodes)
	unmarshalJSON(latCache, &c.LatencyCache)
	unmarshalJSON(subAct, &c.SubscriptionActive)
	unmarshalJSON(outbound, &c.Outbound)
	unmarshalJSON(tspuMeta, &c.LastTSPUMeta)
	if c.Kind == "" {
		c.Kind = KindProxy
	}
	if c.Outbound == nil {
		c.Outbound = map[string]any{}
	}
	return c, nil
}

func scanConfig(row scanner) (*AWGConfig, error) {
	c := &AWGConfig{}
	var dns sql.NullString
	var connID sql.NullInt64
	var lists, domains, subnets []byte
	var updated sql.NullTime
	var lastErr sql.NullString
	var enabled, resEn, reject int
	if err := row.Scan(&c.ID, &c.Name, &c.Type, &enabled, &c.Iface, &c.InternalSubnet, &c.ServerAddress,
		&c.PeerDNS, &dns, &c.ClientAllowedIPs, &resEn, &reject, &connID, &lists, &domains, &subnets,
		&updated, &lastErr); err != nil {
		return nil, err
	}
	c.Enabled = enabled != 0
	c.ResolverEnabled = resEn != 0
	c.ResolverRejectQUIC = reject != 0
	c.ResolverDNS = ns(dns)
	if connID.Valid {
		v := connID.Int64
		c.ConnectionID = &v
	}
	unmarshalJSON(lists, &c.CommunityLists)
	unmarshalJSON(domains, &c.UserDomains)
	unmarshalJSON(subnets, &c.UserSubnets)
	c.ResolverUpdatedAt = nt(updated)
	c.ResolverLastError = ns(lastErr)
	return c, nil
}

func scanCustomList(row scanner) (*CustomList, error) {
	l := &CustomList{}
	var domains, cidrs []byte
	var src sql.NullString
	var created, updated sql.NullTime
	if err := row.Scan(&l.ID, &l.Name, &l.Slug, &domains, &cidrs, &src, &created, &updated); err != nil {
		return nil, err
	}
	unmarshalJSON(domains, &l.Domains)
	unmarshalJSON(cidrs, &l.CIDRs)
	l.SourceURL = ns(src)
	l.CreatedAt = nt(created)
	l.UpdatedAt = nt(updated)
	return l, nil
}

func ns(v sql.NullString) *string {
	if !v.Valid {
		return nil
	}
	s := v.String
	return &s
}
func nt(v sql.NullTime) *time.Time {
	if !v.Valid {
		return nil
	}
	t := v.Time
	return &t
}
func nullStr(p *string) any {
	if p == nil {
		return nil
	}
	return *p
}
func nullTime(p *time.Time) any {
	if p == nil {
		return nil
	}
	return *p
}
func nullInt(p *int) any {
	if p == nil {
		return nil
	}
	return *p
}
func nullInt64(p *int64) any {
	if p == nil {
		return nil
	}
	return *p
}
func nullBool(p *bool) any {
	if p == nil {
		return nil
	}
	if *p {
		return 1
	}
	return 0
}
func boolToInt(v bool) int {
	if v {
		return 1
	}
	return 0
}

func (s *Store) TelegramConnectionIDs(ctx context.Context, kv *KV) []int64 {
	raw := kv.Get(ctx, "telegram_proxies", "[]")
	var decoded []map[string]any
	_ = json.Unmarshal([]byte(raw), &decoded)
	seen := map[int64]bool{}
	var ids []int64
	for _, row := range decoded {
		if strVal(row["type"]) != "connection" {
			continue
		}
		en := true
		switch v := row["enabled"].(type) {
		case bool:
			en = v
		case float64:
			en = v != 0
		case string:
			en = v == "1" || strings.EqualFold(v, "true")
		}
		if !en {
			continue
		}
		id := int64(atoiDef(strVal(row["connection_id"]), 0))
		if id < 1 || seen[id] {
			continue
		}
		seen[id] = true
		ids = append(ids, id)
	}
	return ids
}

func (s *Store) TelegramMixedAuth(ctx context.Context, kv *KV) (user, pass string) {
	user = strings.TrimSpace(kv.Get(ctx, "telegram_mixed_auth_user", ""))
	pass = strings.TrimSpace(kv.Get(ctx, "telegram_mixed_auth_pass", ""))
	return
}

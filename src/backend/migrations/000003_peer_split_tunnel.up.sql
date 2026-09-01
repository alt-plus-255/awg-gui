ALTER TABLE awg_config_peers
  ADD COLUMN split_tunnel TINYINT(1) NOT NULL DEFAULT 0 AFTER forward_allowed_cidrs;

UPDATE awg_config_peers
SET split_tunnel = 1
WHERE extra_allowed_ips IS NOT NULL
  AND JSON_LENGTH(extra_allowed_ips) > 0;

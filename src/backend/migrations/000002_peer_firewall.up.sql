ALTER TABLE awg_config_peers
  ADD COLUMN forward_policy VARCHAR(16) NOT NULL DEFAULT 'allow_all' AFTER keepalive,
  ADD COLUMN forward_allowed_cidrs JSON NULL AFTER forward_policy;

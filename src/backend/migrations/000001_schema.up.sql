-- Laravel-equivalent schema: 18 tables (17 from app migrations + Laravel migrations).

CREATE TABLE IF NOT EXISTS migrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(255) NOT NULL,
  batch INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(255) NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  email_verified_at TIMESTAMP NULL,
  password VARCHAR(255) NOT NULL,
  two_factor_secret TEXT NULL,
  two_factor_confirmed_at TIMESTAMP NULL,
  remember_token VARCHAR(100) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY users_email_unique (email),
  UNIQUE KEY users_username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  email VARCHAR(255) NOT NULL PRIMARY KEY,
  token VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(255) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  payload LONGTEXT NOT NULL,
  last_activity INT NOT NULL,
  KEY sessions_user_id_index (user_id),
  KEY sessions_last_activity_index (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cache (
  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
  value MEDIUMTEXT NOT NULL,
  expiration INT NOT NULL,
  KEY cache_expiration_index (expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cache_locks (
  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
  owner VARCHAR(255) NOT NULL,
  expiration INT NOT NULL,
  KEY cache_locks_expiration_index (expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  queue VARCHAR(255) NOT NULL,
  payload LONGTEXT NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL,
  reserved_at INT UNSIGNED NULL,
  available_at INT UNSIGNED NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  KEY jobs_queue_index (queue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_batches (
  id VARCHAR(255) NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  total_jobs INT NOT NULL,
  pending_jobs INT NOT NULL,
  failed_jobs INT NOT NULL,
  failed_job_ids LONGTEXT NOT NULL,
  options MEDIUMTEXT NULL,
  cancelled_at INT NULL,
  created_at INT NOT NULL,
  finished_at INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS failed_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(255) NOT NULL,
  connection TEXT NOT NULL,
  queue TEXT NOT NULL,
  payload LONGTEXT NOT NULL,
  exception LONGTEXT NOT NULL,
  failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY failed_jobs_uuid_unique (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_access_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tokenable_type VARCHAR(255) NOT NULL,
  tokenable_id BIGINT UNSIGNED NOT NULL,
  name TEXT NOT NULL,
  token VARCHAR(64) NOT NULL,
  abilities TEXT NULL,
  last_used_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY personal_access_tokens_token_unique (token),
  KEY personal_access_tokens_tokenable_type_tokenable_id_index (tokenable_type, tokenable_id),
  KEY personal_access_tokens_expires_at_index (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vpn_clients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  comment VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(255) NOT NULL,
  value TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY settings_key_unique (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resolver_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  comment TEXT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'proxy',
  config_type VARCHAR(16) NOT NULL DEFAULT 'url',
  share_url TEXT NULL,
  subscription_url TEXT NULL,
  subscription_body LONGTEXT NULL,
  subscription_mode VARCHAR(16) NULL,
  subscription_selected VARCHAR(64) NULL,
  subscription_nodes JSON NULL,
  subscription_fetched_at TIMESTAMP NULL,
  latency_cache JSON NULL,
  subscription_active JSON NULL,
  ping_check_interval_min SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  ping_last_checked_at TIMESTAMP NULL,
  outbound JSON NOT NULL,
  awg_conf TEXT NULL,
  protocol_version VARCHAR(16) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_latency_ms INT UNSIGNED NULL,
  last_tested_at TIMESTAMP NULL,
  last_test_ok TINYINT(1) NULL,
  last_test_error TEXT NULL,
  last_tspu_status VARCHAR(32) NULL,
  last_tspu_likely TINYINT(1) NULL,
  last_tspu_detail TEXT NULL,
  last_tspu_meta JSON NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS awg_configs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'server',
  protocol_version VARCHAR(8) NOT NULL DEFAULT '2.0',
  client_import_name_style VARCHAR(32) NOT NULL DEFAULT 'peer_name',
  vn_policy VARCHAR(32) NOT NULL DEFAULT 'allow_all',
  vn_zones JSON NULL,
  iface VARCHAR(16) NOT NULL,
  listen_port INT UNSIGNED NOT NULL,
  internal_subnet VARCHAR(255) NOT NULL,
  server_address VARCHAR(255) NOT NULL,
  server_private_key TEXT NOT NULL,
  server_public_key VARCHAR(255) NOT NULL,
  peer_dns VARCHAR(255) NOT NULL DEFAULT '1.1.1.1',
  resolver_dns VARCHAR(255) NULL DEFAULT '1.1.1.1',
  client_allowed_ips VARCHAR(255) NOT NULL DEFAULT '0.0.0.0/0, ::/0',
  persistent_keepalive INT UNSIGNED NOT NULL DEFAULT 25,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  handshake_logging_enabled TINYINT(1) NOT NULL DEFAULT 0,
  handshake_log_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  resolver_enabled TINYINT(1) NOT NULL DEFAULT 0,
  resolver_routing_mode VARCHAR(32) NOT NULL DEFAULT 'vds_split',
  resolver_reject_quic TINYINT(1) NOT NULL DEFAULT 0,
  community_lists JSON NULL,
  user_domains JSON NULL,
  user_subnets JSON NULL,
  resolver_updated_at TIMESTAMP NULL,
  resolver_last_error TEXT NULL,
  connection_id BIGINT UNSIGNED NULL,
  jc VARCHAR(10) NOT NULL DEFAULT '4',
  jmin VARCHAR(10) NOT NULL DEFAULT '64',
  jmax VARCHAR(10) NOT NULL DEFAULT '80',
  s1 VARCHAR(10) NOT NULL DEFAULT '0',
  s2 VARCHAR(10) NOT NULL DEFAULT '0',
  s3 VARCHAR(10) NOT NULL DEFAULT '0',
  s4 VARCHAR(10) NOT NULL DEFAULT '0',
  h1 VARCHAR(20) NOT NULL DEFAULT '1',
  h2 VARCHAR(20) NOT NULL DEFAULT '2',
  h3 VARCHAR(20) NOT NULL DEFAULT '3',
  h4 VARCHAR(20) NOT NULL DEFAULT '4',
  i1 TEXT NULL,
  i2 TEXT NULL,
  i3 TEXT NULL,
  i4 TEXT NULL,
  i5 TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY awg_configs_iface_unique (iface),
  UNIQUE KEY awg_configs_listen_port_unique (listen_port),
  CONSTRAINT awg_configs_connection_id_foreign
    FOREIGN KEY (connection_id) REFERENCES resolver_connections (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS awg_config_peers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  awg_config_id BIGINT UNSIGNED NOT NULL,
  vpn_client_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  private_key TEXT NOT NULL,
  public_key VARCHAR(255) NOT NULL,
  preshared_key VARCHAR(255) NULL,
  address VARCHAR(255) NOT NULL,
  extra_allowed_ips JSON NULL,
  excluded_client_ids JSON NULL,
  exclusions_mutual TINYINT(1) NOT NULL DEFAULT 0,
  keepalive INT UNSIGNED NULL,
  runtime_endpoint VARCHAR(64) NULL,
  latest_handshake BIGINT UNSIGNED NULL,
  transfer_rx BIGINT UNSIGNED NOT NULL DEFAULT 0,
  transfer_tx BIGINT UNSIGNED NOT NULL DEFAULT 0,
  traffic_rx_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  traffic_tx_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  traffic_rx_baseline BIGINT UNSIGNED NOT NULL DEFAULT 0,
  traffic_tx_baseline BIGINT UNSIGNED NOT NULL DEFAULT 0,
  traffic_reset_at TIMESTAMP NULL,
  online TINYINT(1) NULL,
  stats_synced_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY awg_config_peers_awg_config_id_vpn_client_id_unique (awg_config_id, vpn_client_id),
  UNIQUE KEY awg_config_peers_awg_config_id_public_key_unique (awg_config_id, public_key),
  CONSTRAINT awg_config_peers_awg_config_id_foreign
    FOREIGN KEY (awg_config_id) REFERENCES awg_configs (id) ON DELETE CASCADE,
  CONSTRAINT awg_config_peers_vpn_client_id_foreign
    FOREIGN KEY (vpn_client_id) REFERENCES vpn_clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_protections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  lockout_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY login_protections_ip_unique (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resolver_custom_lists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  domains JSON NULL,
  cidrs JSON NULL,
  source_url VARCHAR(1024) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY resolver_custom_lists_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS awg_handshake_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  awg_config_id BIGINT UNSIGNED NOT NULL,
  awg_config_peer_id BIGINT UNSIGNED NULL,
  vpn_client_id BIGINT UNSIGNED NULL,
  public_key VARCHAR(64) NOT NULL,
  endpoint VARCHAR(64) NULL,
  handshake_at BIGINT UNSIGNED NOT NULL,
  byte_size INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY awg_handshake_logs_awg_config_id_id_index (awg_config_id, id),
  KEY awg_handshake_logs_awg_config_id_vpn_client_id_id_index (awg_config_id, vpn_client_id, id),
  KEY awg_handshake_logs_awg_config_id_handshake_at_index (awg_config_id, handshake_at),
  CONSTRAINT awg_handshake_logs_awg_config_id_foreign
    FOREIGN KEY (awg_config_id) REFERENCES awg_configs (id) ON DELETE CASCADE,
  CONSTRAINT awg_handshake_logs_awg_config_peer_id_foreign
    FOREIGN KEY (awg_config_peer_id) REFERENCES awg_config_peers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE llx_knot_webhook (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  fk_workflow integer NOT NULL,
  webhook_token varchar(64) NOT NULL,
  method varchar(16) NOT NULL DEFAULT 'POST',
  secret_hmac text NULL,
  ip_allowlist text NULL,
  is_active tinyint NOT NULL DEFAULT 0,
  hit_count integer NOT NULL DEFAULT 0,
  last_hit_at datetime NULL,
  rate_limit_per_minute integer NOT NULL DEFAULT 60,
  entity integer NOT NULL DEFAULT 1,
  UNIQUE KEY uk_knot_webhook_token (webhook_token),
  KEY idx_knot_webhook_workflow_active (fk_workflow, is_active),
  KEY idx_knot_webhook_entity_active (entity, is_active)
) ENGINE=innodb;

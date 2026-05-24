CREATE TABLE llx_knot_idempotency (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  idempotency_key varchar(128) NOT NULL,
  fk_execution integer NOT NULL,
  ttl_seconds integer NOT NULL DEFAULT 86400,
  entity integer NOT NULL DEFAULT 1,
  date_creation datetime NOT NULL,
  UNIQUE KEY uk_knot_idempotency_key_entity (idempotency_key, entity),
  KEY idx_knot_idempotency_execution (fk_execution),
  KEY idx_knot_idempotency_created (date_creation)
) ENGINE=innodb;

CREATE TABLE llx_knot_audit_log (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  action_type varchar(128) NOT NULL,
  entity_type varchar(128) NOT NULL,
  entity_id integer NULL,
  fk_user integer NULL,
  ip_address varchar(64) NULL,
  user_agent varchar(255) NULL,
  payload longtext NULL,
  created_at datetime NOT NULL,
  entity integer NOT NULL DEFAULT 1,
  KEY idx_knot_audit_log_entity_created (entity, created_at),
  KEY idx_knot_audit_log_action_type (action_type),
  KEY idx_knot_audit_log_entity_ref (entity_type, entity_id)
) ENGINE=innodb;

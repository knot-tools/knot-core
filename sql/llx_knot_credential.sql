CREATE TABLE llx_knot_credential (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  ref varchar(64) NOT NULL,
  label varchar(255) NOT NULL,
  type varchar(64) NOT NULL,
  connector_type varchar(128) NOT NULL,
  encrypted_data text NOT NULL,
  encryption_version varchar(16) NOT NULL DEFAULT '1',
  expires_at datetime NULL,
  entity integer NOT NULL DEFAULT 1,
  fk_user_creat integer NULL,
  fk_user_modif integer NULL,
  date_creation datetime NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_knot_credential_ref_entity (ref, entity),
  KEY idx_knot_credential_entity_type (entity, type),
  KEY idx_knot_credential_connector_type (connector_type)
) ENGINE=innodb;

CREATE TABLE llx_knot_variable (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  ref varchar(128) NOT NULL,
  label varchar(255) NOT NULL,
  value text NULL,
  is_secret tinyint NOT NULL DEFAULT 0,
  scope varchar(32) NOT NULL DEFAULT 'global',
  fk_workflow integer NULL,
  entity integer NOT NULL DEFAULT 1,
  fk_user_creat integer NULL,
  KEY idx_knot_variable_entity_scope (entity, scope),
  KEY idx_knot_variable_workflow (fk_workflow),
  KEY idx_knot_variable_ref_entity (ref, entity)
) ENGINE=innodb;

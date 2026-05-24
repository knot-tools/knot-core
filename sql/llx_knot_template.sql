CREATE TABLE llx_knot_template (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  ref varchar(64) NOT NULL,
  label varchar(255) NOT NULL,
  description text NULL,
  category varchar(64) NOT NULL DEFAULT 'general',
  json_definition longtext NOT NULL,
  icon varchar(128) NULL,
  is_system tinyint NOT NULL DEFAULT 0,
  entity integer NOT NULL DEFAULT 1,
  KEY idx_knot_template_entity_category (entity, category),
  KEY idx_knot_template_ref_entity (ref, entity)
) ENGINE=innodb;

CREATE TABLE llx_knot_workflow_version (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  fk_workflow integer NOT NULL,
  version_label varchar(255) NULL,
  definition_json mediumtext NOT NULL,
  is_named tinyint NOT NULL DEFAULT 0,
  parent_version_id integer NULL,
  entity integer NOT NULL DEFAULT 1,
  fk_user_creat integer NULL,
  date_creation datetime NOT NULL,
  KEY idx_knot_workflow_version_workflow (fk_workflow, entity),
  KEY idx_knot_workflow_version_named (is_named),
  KEY idx_knot_workflow_version_created (date_creation)
) ENGINE=innodb;

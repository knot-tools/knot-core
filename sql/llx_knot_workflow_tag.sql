CREATE TABLE llx_knot_workflow_tag (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  fk_workflow integer NOT NULL,
  tag varchar(50) NOT NULL,
  entity integer NOT NULL DEFAULT 1,
  date_creation datetime NOT NULL,
  UNIQUE KEY uk_knot_workflow_tag (fk_workflow, tag, entity),
  KEY idx_knot_workflow_tag_tag (tag, entity)
) ENGINE=innodb;

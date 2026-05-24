CREATE TABLE llx_knot_workflow_folder (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  label varchar(255) NOT NULL,
  color varchar(16) NULL,
  parent_id integer NULL,
  entity integer NOT NULL DEFAULT 1,
  fk_user_creat integer NULL,
  date_creation datetime NOT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_knot_folder_entity (entity),
  KEY idx_knot_folder_parent (parent_id)
) ENGINE=innodb;

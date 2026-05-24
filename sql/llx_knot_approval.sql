CREATE TABLE llx_knot_approval (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  fk_execution integer NULL,
  node_id varchar(128) NOT NULL,
  message text NOT NULL,
  requester_id integer NULL,
  approver_role varchar(128) NULL,
  status varchar(32) NOT NULL DEFAULT 'pending',
  decided_at datetime NULL,
  decided_by integer NULL,
  comment text NULL,
  entity integer NOT NULL DEFAULT 1,
  date_creation datetime NOT NULL,
  KEY idx_knot_approval_status_entity (status, entity),
  KEY idx_knot_approval_execution (fk_execution)
) ENGINE=innodb;

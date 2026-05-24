CREATE TABLE llx_knot_execution_log (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  fk_execution integer NOT NULL,
  node_id varchar(128) NOT NULL,
  node_type varchar(128) NOT NULL,
  status varchar(32) NOT NULL,
  input_data longtext NULL,
  output_data longtext NULL,
  duration_ms integer NULL,
  error_message text NULL,
  executed_at datetime NOT NULL,
  sequence_order integer NOT NULL DEFAULT 0,
  entity integer NOT NULL DEFAULT 1,
  KEY idx_knot_execution_log_execution_sequence (fk_execution, sequence_order),
  KEY idx_knot_execution_log_entity_executed (entity, executed_at),
  KEY idx_knot_execution_log_node (node_id)
) ENGINE=innodb;

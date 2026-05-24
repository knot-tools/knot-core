CREATE TABLE llx_knot_schedule (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  fk_workflow integer NOT NULL,
  cron_expression varchar(128) NOT NULL,
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  is_active tinyint NOT NULL DEFAULT 0,
  last_run_at datetime NULL,
  next_run_at datetime NULL,
  entity integer NOT NULL DEFAULT 1,
  KEY idx_knot_schedule_next_active (next_run_at, is_active),
  KEY idx_knot_schedule_workflow_active (fk_workflow, is_active),
  KEY idx_knot_schedule_entity_active (entity, is_active)
) ENGINE=innodb;

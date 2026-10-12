-- Idempotent migration tracking (Migrator).
-- Folded from sql/migrations/v2.0.0/04_migration_history.sql.
-- Migrator::ensureHistoryTable remains as a runtime safety net.
CREATE TABLE llx_knot_migration_history (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  version varchar(32) NOT NULL,
  migration_file varchar(255) NOT NULL,
  applied_at datetime NOT NULL,
  duration_ms integer NULL,
  status varchar(16) NOT NULL DEFAULT 'applied',
  UNIQUE KEY uniq_migration (version, migration_file)
) ENGINE=innodb;

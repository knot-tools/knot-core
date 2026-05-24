-- Migration V2.0.0 — 04 : table de tracking idempotent
CREATE TABLE IF NOT EXISTS llx_knot_migration_history (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    migration_file VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL,
    duration_ms INT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'applied',
    UNIQUE KEY uniq_migration (version, migration_file)
) ENGINE=InnoDB;

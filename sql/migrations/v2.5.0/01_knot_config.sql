-- Migration V2.5.0a — 01 : key/value config table for Knot
-- Used by KnotConfigRepository for storage of:
--   * licensing.local_salt (32 random bytes hex, generated once on first
--     Bootstrap call, used by InstanceBinder)
--   * any future non-Dolibarr persistent state needed by Knot Core.
--
-- Multi-entity aware: the same key can hold different values per entity.
CREATE TABLE IF NOT EXISTS llx_knot_config (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER NOT NULL DEFAULT 1,
    k VARCHAR(128) NOT NULL,
    v LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_entity_key (entity, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Knot key/value config (licensing salt, update cache, marketplace TTL, …).
-- Folded from sql/migrations/v2.5.0/01_knot_config.sql so fresh installs
-- get the table via modKnot::_load_tables (Migrator still runs on setup/Apply).
CREATE TABLE llx_knot_config (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  k varchar(128) NOT NULL,
  v longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  UNIQUE KEY uniq_entity_key (entity, k)
) ENGINE=innodb;

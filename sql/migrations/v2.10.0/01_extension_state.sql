-- V2.10.0 — Per-(user, extension) key-value state store (ADR-20 slice 4).
-- Backs `window.KnotCore.persistedState(extensionId)` so first-visit gates,
-- onboarding progress, and other UI state survive browser changes and
-- instance moves.

CREATE TABLE IF NOT EXISTS llx_knot_extension_state (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  extension_id varchar(64) NOT NULL,
  fk_user integer NOT NULL,
  state_key varchar(128) NOT NULL,
  state_value LONGTEXT NULL,
  entity integer NOT NULL DEFAULT 1,
  date_creation datetime NOT NULL,
  tms timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_knot_extension_state (entity, extension_id, fk_user, state_key),
  KEY idx_knot_extension_state_user (fk_user, extension_id, entity)
) ENGINE=innodb;

-- Knot extension state store (ADR-20 slice 4).
-- Per-(user, extension) key-value persistence used by Knot Core's
-- `window.KnotCore.persistedState(extensionId)` runtime helper.
--
-- Designed to survive browser changes / instance migrations:
-- localStorage in the browser remains a synchronous cache, this
-- table is the source of truth.
--
-- Multi-tenant: `entity` mandatory. Quotas: a single (user, ext)
-- pair holds at most 200 keys (enforced at the API layer, not at
-- the schema layer).
CREATE TABLE llx_knot_extension_state (
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

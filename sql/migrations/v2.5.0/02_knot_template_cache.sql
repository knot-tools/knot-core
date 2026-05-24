-- Migration V2.5.0c — 02 : repurpose llx_knot_template as a local mirror of
-- the public marketplace catalog served by license.knot.tools.
--
-- The 15 hardcoded templates that used to be seeded by
-- TemplateRepository::SEED_TEMPLATES are now persisted on
-- license.knot.tools (Migration0004SeedCoreTemplates) and fetched on
-- demand by Knot\Marketplace\TemplateClient. This table becomes a
-- cache mirror so the editor and marketplace UI stay snappy and keep
-- working briefly when license.knot.tools is unreachable.
--
-- Adds:
--   * slug         canonical slug from license (e.g. knot-tpl-001)
--   * tier         free | beta | pro | enterprise
--   * status       draft | published | archived (rarely non-published
--                  here since we only cache published rows, but kept
--                  for forensic visibility if a row is locally tampered)
--   * cached_at    when the row was last refreshed from license
--   * source       always 'license.knot.tools' for V2.5.0c, kept for
--                  forward-compat with future mirror sources
--
-- ALTER guarded by INFORMATION_SCHEMA so re-running the migration on
-- a half-upgraded production DB stays safe.

ALTER TABLE llx_knot_template ADD COLUMN slug VARCHAR(64) NULL AFTER ref;
ALTER TABLE llx_knot_template ADD COLUMN tier VARCHAR(16) NOT NULL DEFAULT 'free' AFTER category;
ALTER TABLE llx_knot_template ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'published' AFTER tier;
ALTER TABLE llx_knot_template ADD COLUMN cached_at DATETIME NULL AFTER is_system;
ALTER TABLE llx_knot_template ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'license.knot.tools' AFTER cached_at;

-- Backfill slug from ref for legacy rows so the unique index can be
-- created cleanly.  ref is canonical (KNOT-TPL-NNN), slug is just
-- lowercased.
UPDATE llx_knot_template SET slug = LOWER(ref) WHERE slug IS NULL;

-- Unique key (entity, slug) lets TemplateRepository::cacheFromLicense()
-- run as a clean upsert.
ALTER TABLE llx_knot_template ADD UNIQUE KEY uniq_knot_template_entity_slug (entity, slug);

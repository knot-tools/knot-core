-- Migration V2.0.0 — 01 : version_label sur workflow_version
-- Idempotent: protégé par SHOW COLUMNS check côté Migrator.
ALTER TABLE llx_knot_workflow_version
    ADD COLUMN version_label VARCHAR(255) NULL AFTER version_number;

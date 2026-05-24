-- V2.12.0 — persist "do not show activation warning" per workflow (non-critical only).
ALTER TABLE llx_knot_workflow
  ADD COLUMN activation_warning_dismissed TINYINT(1) NOT NULL DEFAULT 0
  AFTER single_instance;

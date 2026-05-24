-- V2.6.0 — Execution queue enrichment (priority, schedule, retries).
-- See ADR-009. Status values unchanged (queued, running, success, error, …).

ALTER TABLE llx_knot_execution
  ADD COLUMN priority TINYINT NOT NULL DEFAULT 5,
  ADD COLUMN scheduled_at DATETIME NULL,
  ADD COLUMN next_retry_at DATETIME NULL,
  ADD COLUMN max_attempts TINYINT NOT NULL DEFAULT 3,
  ADD COLUMN worker_id VARCHAR(36) NULL,
  ADD COLUMN backoff_strategy VARCHAR(32) NOT NULL DEFAULT 'exponential';

ALTER TABLE llx_knot_execution
  ADD KEY idx_knot_execution_entity_queue (entity, status, priority, scheduled_at, rowid);

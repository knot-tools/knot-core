-- V2.6.1 — Persist structured Knot failure payloads on executions (rich UI / ADR-007).

ALTER TABLE llx_knot_execution
  ADD COLUMN error_payload TEXT NULL;

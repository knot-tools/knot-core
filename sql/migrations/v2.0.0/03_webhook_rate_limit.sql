-- Migration V2.0.0 — 03 : rate-limit columns on webhook + dedicated table
ALTER TABLE llx_knot_webhook
    ADD COLUMN rl_window_start INT NOT NULL DEFAULT 0,
    ADD COLUMN rl_count INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS llx_knot_rate_limit (
    bucket VARCHAR(64) NOT NULL PRIMARY KEY,
    window_start INT NOT NULL DEFAULT 0,
    hits INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

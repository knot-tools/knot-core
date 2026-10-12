-- Generic fixed-window rate-limit buckets (webhooks, API guards).
-- Folded from sql/migrations/v2.0.0/03_webhook_rate_limit.sql.
-- RateLimiter::ensureTable remains as a runtime safety net.
CREATE TABLE llx_knot_rate_limit (
  bucket varchar(64) NOT NULL PRIMARY KEY,
  window_start integer NOT NULL DEFAULT 0,
  hits integer NOT NULL DEFAULT 0
) ENGINE=innodb;

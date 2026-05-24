# Execution queue — operator configuration

## Cron-only mode (default)

Knot drains `llx_knot_execution` from the Dolibarr cron hook (`CronWorker`). There is no bundled long-running PHP daemon in V2.6 — capabilities advertise `queue_modes_available: ["cron"]`.

## Ordering & fairness

After migration **V2.6.0**,eligible queued rows are ordered by:

1. `priority` DESC (higher runs first; default `5`).
2. `scheduled_at` ASC (`NULL` means “as soon as possible”).
3. `rowid` ASC tie-breaker.

## Scheduling & retries

- `scheduled_at` — optional “not before” timestamp (future hook for deferred enqueue APIs).
- `next_retry_at` — backoff gate populated automatically after transient failures.
- `retry_count` — increments on each failed attempt; compared to workflow settings below.

Workflow JSON (`definition.workflow`):

| Field | Meaning |
|-------|---------|
| `executionMaxAttempts` | Max attempts before terminal `error` (default `3`, clamped 1–50). |
| `executionBackoffStrategy` | `exponential` (default), `linear`, or `fixed`. |

`max_attempts`, `backoff_strategy`, and `priority` columns exist on `llx_knot_execution` for storage/API evolution; the cron worker currently derives retry policy from workflow JSON at failure time.

## Worker identifier

`worker_id` tags rows claimed from `queued` → `running` (optimistic `UPDATE`). Cron uses prefixes like `cron-YYYYMMDDHHMMSS-<executionId>`.

## MariaDB / MySQL

ADR-009 documents **`SKIP LOCKED`** vs optimistic locking. V2.6 ships **optimistic locking** compatible with MariaDB **10.5+**.

## Administration UI

Vue — **Executions** screen (`?mode=executions`): tab **History** lists executions via `GET /api/executions.php`; tab **Queue & retries** hits `GET /api/executions.php?action=queue_dashboard` (counts, workflows with queued runs, top retries). Legacy URL **`?mode=queue`** opens the same screen on the queue tab.

Purging stale failures: `POST /api/executions.php?action=purge_failures` + JSON `{ "olderThanDays": 7 }` (requires execute permission + CSRF).

## See also

- [`limits.md`](limits.md)
- ADR-009 — [`../architecture/decisions/ADR-009-mysql-execution-queue.md`](../architecture/decisions/ADR-009-mysql-execution-queue.md)

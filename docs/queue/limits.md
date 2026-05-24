# Execution queue — realistic limits

Knot targets shared hosting: PHP invoked via **web SAPI + cron**, not dedicated worker fleets.

## Throughput expectations

| Scenario | Expectation |
|----------|-------------|
| Cron every 5 minutes | Bounded batches (`CronWorker` processes up to **10** queued rows per tick). |
| Shared CPU | Do **not** expect tens of sustained executions per second. |
| Long workflows | A single execution blocking PHP may overlap following cron ticks — rely on `single_instance` workflows when isolation matters. |

## Database pressure

- Aggregates (`statusCounts`, queue dashboard) are lightweight `GROUP BY` queries scoped per entity.
- Purge removes terminal **`error`** rows older than *N* days — destructive; gated behind confirmation in UI.

## Compared to Redis / RabbitMQ

See **ADR-002**. Knot deliberately stays on **MySQL-native** queue semantics for portability.

## Future capability flags

`capabilities.features.queue_priorities` becomes **`true`** automatically once migration `v2.6.0/01_execution_queue.sql` is applied (detected via `priority` column).

Long-running worker daemons remain **documentation-only** unless explicitly added later.

# Observability dashboard (session UI)

## Scope

The **Observability** screen (`?mode=observability`) loads aggregated metrics via **`GET /custom/knot/api/observability.php`**.

It complements:

- **`GET /custom/knot/api/health.php`** — operator-oriented snapshot (version, cron, doctor checks).
- **`GET /custom/knot/api/metrics.php`** — Prometheus scrape / optional JSON for heatmap when metrics are enabled globally.

## Privacy

The observability endpoint returns **counts and durations only**. It never exposes `input_data`, `output_data`, or free-text error bodies from `llx_knot_execution_log`.

## Parameters

| Query | Range | Default |
|-------|-------|---------|
| `days` | 1–365 | 7 |
| `limit_types` | 1–200 | 40 |

## Implementation notes

- SQL is scoped by **`entity`** (`$conf->entity`).
- Heavy rollups (`llx_knot_metrics`) were deferred: bounded queries on existing tables stay sufficient until volume requires indexing or summary tables (ADR-015).

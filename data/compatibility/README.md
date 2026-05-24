# Knot compatibility snapshots

Bundled JSON files under `snapshots/` illustrate the `knot.snapshot.v1` format used by
`SchemaSnapshotter` (see ADR-011). Runtime snapshots for live instances should be stored under
`documents/knot/compatibility/snapshots/` via `api/compatibility.php` (`snapshot_save`) or CLI helpers.

## Shipped reference files (V2.7.1+)

| File | Notes |
|------|--------|
| `dolibarr-21.0.4.json` | Reference structure for pilots (`facture`, `commande`, `propal`); `dolibarr_version` reflects source instance |
| `reference-diff-demo.json` | **Synthetic** file for offline diff demos — not captured from a customer instance |
| `sample-v1.json` / `sample-v2.json` | Doc/test samples; excluded from **`bundled_snapshots`** catalogue |

Keep each JSON **under ~1 MiB**; snapshots hold **technical** schema shapes only (no hostnames, credentials, or client business data).

CLI helpers:

- `scripts/generate-schema-snapshot.php` — dumps pilots via Dolibarr bootstrap; optional **`--output path.json`** (default: stdout).
- `scripts/compare-schema-snapshots.php` — offline diff + breaking classification.
- `scripts/analyze-workflow-impact.php` — cross-check workflows vs breaking rows.
- `scripts/check-workflow-compatibility.php` — combines compare + optional workflow hints.

Pilot slugs in tooling and docs: **`facture`**, **`commande`**, **`propal`** (`PilotDocuments`).

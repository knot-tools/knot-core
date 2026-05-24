# Dolibarr schema compatibility (operator notes)

See **ADR-010** (pilot scope), **ADR-011** (snapshot storage), and **`data/compatibility/README.md`** for bundled files and CLI.

## Pilot objects

Official pilot slugs match **`PilotDocuments`**: **`facture`**, **`commande`**, **`propal`** (not `commande_client`).

## Bundled snapshots

| File | Role |
|------|------|
| `sample-v1.json` / `sample-v2.json` | Structural diff samples for docs/tests |
| `dolibarr-*.json` | Reference snapshot captured from a real Dolibarr line (version in JSON `dolibarr_version`) |
| `reference-diff-demo.json` | **Synthetic** offline demo baseline (not from production) |

Listing and loading of reference files (excluding `sample-*`) use **`BundledSnapshotCatalog`** and compatibility API actions below.

## Internal API

Base path: **`/custom/knot/api/compatibility.php`**

| Action | Method | Notes |
| --- | --- | --- |
| `snapshot_live` | GET | Runtime snapshot for pilots (`workflow.read`) |
| `sample` | GET | Bundled `sample-v1.json` (`workflow.read`) |
| `bundled_snapshots` | GET | Metadata list for JSON under `data/compatibility/snapshots/` except `sample-*.json` (`workflow.read`) |
| `bundled_snapshot` | GET | `file=dolibarr-x.json` basename only; strict allowlist under snapshots dir (`workflow.read`) |
| `diff` | POST | `{ "action": "diff", "baseline": {…}, "target": {…}, "workflows"? }` — requires `workflow.write` + CSRF |
| `snapshot_save` | POST | Persist under documents dir (`admin.configure_module`) |

## CLI helpers

| Script | Purpose |
| --- | --- |
| `scripts/generate-schema-snapshot.php` | Emit snapshot JSON to **stdout**, or **`--output /path/file.json`** |
| `scripts/compare-schema-snapshots.php` | Offline diff two files |
| `scripts/analyze-workflow-impact.php` | Workflow hints vs baseline |
| `scripts/check-workflow-compatibility.php` | Batch compatibility gate |

## Vue surface

Open **`/custom/knot/workflows/preview.php?mode=compatibility`** (requires Knot UI bundle). Tab *Compare* supports pasted JSON, bundled sample, and **loading bundled reference snapshots** from the catalogue.

# Demo instance — Dolibarr module activation log

Records **demo-only** module switches used for Knot compatibility measurement. Prefer modules **without third-party SaaS credentials**.

| Date (UTC) | Module | Enabled? | Notes |
|------------|--------|----------|-------|
| 2026-05-02 | inventory | n/a | `scan-all-dolibarr-classes.php` on `knot-dolibarr-app` (Dolibarr 21.0.4 image), `KNOT_SCAN_INCLUDE_CUSTOM=1`; **405** class files inventoried (`dolibarr-classes-full-inventory.json`). |
| 2026-05-02 | inventory | n/a | **Plesk** `/var/www/vhosts/demo.knot.tools/httpdocs` (prod DOCUMENT_ROOT), `include_custom=1`, PHP **`/opt/plesk/php/8.3/bin/php`** — **405** classes; artefacts from remote `/tmp/`, CLI uploaded to `custom/knot/scripts/` first. |
| — | expedition | varies | Enables shipments REST probes + `expedition` MAP validations |

Extend this table whenever the canonical demo rotates.

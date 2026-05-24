# Auto-update checklist — Dolibarr ↔ Knot catalog drift

Purpose: repeatable operator steps when Dolibarr minor/patch bumps land.

## Weekly / on-demand monitoring

GitHub Action **`.github/workflows/dolibarr-coverage-monitoring.yml`** (schedule + `workflow_dispatch`) runs:

1. `php scripts/compatibility/check_dolibarr_version.php --print-catalog-counts`
2. `php scripts/scan-all-dolibarr-classes.php` writing throwaway JSON (validates script health)
3. `vendor/bin/phpunit --filter ObjectFactoryMapInventoryTest`

## Manual refresh when Dolibarr source updates

1. Export `KNOT_COMPAT_DOL_ROOT` (or pass `--dol-root`) to a checkout matching production.
2. Regenerate catalog + audit tables:
   ```bash
   php scripts/compatibility/export_dolibarr_audit_tables.php \
     --dol-root="$KNOT_COMPAT_DOL_ROOT" \
     --write-catalog=data/compatibility/dolibarr-catalog.json
   ```
3. Regenerate full inventory + classification:
   ```bash
   php scripts/scan-all-dolibarr-classes.php \
     --dol-root="$KNOT_COMPAT_DOL_ROOT" \
     --write-inventory=data/compatibility/dolibarr-classes-full-inventory.json \
     --write-classification=data/compatibility/dolibarr-classes-classification.json
   ```
4. Update `php scripts/compatibility/check_dolibarr_version.php` expectations if the GitHub tag prefix changes.
5. Run `vendor/bin/phpunit` + Playwright opt-in gates (`KNOT_COVERAGE_SCHEMAS_FETCH=1`, `KNOT_SCALE_PROBE=1`).

## Breaking changes

When Dolibarr removes or renames classes / `$fields`, bump `ObjectFactory::MAP`, refresh catalog JSON, document user-facing slug removals in `CHANGELOG.md`.

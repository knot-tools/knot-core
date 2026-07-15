# Starter templates

Pre-wired workflows to discover Knot in under 30 minutes.
Import from the UI (`Workflows` → **Import**) or via `POST /api/workflows.php`
with a Knot 1.0 export JSON.

**Dashboard star journeys** deep-link here with `?mode=workflows&starter=<slug>`.

| # | File | Use case | Trigger | Key connectors |
|---|------|----------|---------|----------------|
| 01 | `01-hello-world.knot.json` | First workflow | manual | `logic.set`, `notification.alert` (audit only) |
| 02 | `02-relance-facture-impayee.knot.json` | **Star** — overdue invoice | daily cron | `dolibarr.sql_query`, `logic.loop`, `action.email` |
| 03 | `03-webhook-to-task.knot.json` | Webhook → task | webhook | `logic.set`, `dolibarr.object` |
| 03b | `03-facture-validee-email-bancaire.knot.json` | Validated invoice email | Dolibarr event | `action.email` |
| 04 | `04-devis-to-facture.knot.json` | **Star** — quote → invoice | Dolibarr event | `logic.if`, `dolibarr.specialized` |
| 05 | `05-backup-quotidien.knot.json` | Self-backup notify | nightly cron | `dolibarr.sql_query`, `action.email` |
| 06 | `06-showcase-power-logic.knot.json` | Logic showcase | manual | `logic.*` only |
| 07 | `07-new-customer-welcome.knot.json` | **Star** — welcome email | Dolibarr event | `action.email` |
| 08 | `08-overdue-invoice-escalation.knot.json` | Escalation path | cron | `action.email`, logic |
| 09 | `09-webhook-crm-sync.knot.json` | Webhook CRM sync | webhook | HTTP / Dolibarr |

## Conventions

- Schedules import with `isActive: false` so import does not fire emails at 09:00.
- Replace `REPLACE_WITH_SMTP_CREDENTIAL_ID` and `REPLACE_WITH_ADMIN_EMAIL` placeholders.
- `notification.alert` = **audit log only** (does not send). Prefer `action.email` for real mail.

## Quick test

1. Dashboard → pick a star journey → Workflows Import.
2. Open editor → **Simulate** → fix credentials → **Run**.
3. Confirm KnotCronWorker is enabled in **Doctor** before relying on async Run.

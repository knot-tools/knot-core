# Integrator checklist — time to first success

One-page path from a fresh Dolibarr instance to a first successful Knot run.
Target: **under 15 minutes** for an admin who already has Dolibarr access.

## Prerequisites

- Dolibarr **V20+**, PHP **8.1+**, MySQL 5.7+ / MariaDB 10.5+
- Admin (or equivalent) rights to install custom modules and enable cron
- SMTP configured in Dolibarr if you plan to use **`action.email`**

## Checklist

### 1. Install Knot Core

Pick one:

| Path | Steps |
|------|--------|
| **ZIP** | Download from [knot.tools/downloads](https://knot.tools/downloads/knot-core/latest) → extract into `htdocs/custom/knot/` |
| **GitHub** | Clone / pull [knot-tools/knot-core](https://github.com/knot-tools/knot-core) into `htdocs/custom/knot/` |

Do **not** nest an extra `knot/` folder inside `custom/knot/`.

### 2. Activate the module

1. Dolibarr → **Home → Setup → Modules/Applications**
2. Find **Knot** → enable
3. Confirm permissions for your user (`knot.workflow.read` / write / admin as needed)

### 3. Complete the setup wizard

Open **Knot** from the left menu. The first-run wizard asks for:

- Engine enablement (`KNOT_ENGINE_ENABLED`)
- Basic retention / health defaults

Finish the wizard before opening the editor.

### 4. Confirm cron jobs are enabled

Knot registers three Dolibarr cron jobs with **`status=1` (enabled) by default** on a fresh install:

| Job | Role |
|-----|------|
| `KnotCronWorker` | Drains the execution queue (async **Run**) |
| `KnotRetentionWorker` | Purges old logs / audit rows |
| `KnotHealthWorker` | Stale-run detection + health metrics |

**Verify:** Home → **Admin tools → Scheduled jobs** (or equivalent cron UI). All three Knot jobs must show **enabled** (`status=1`). If a job was disabled after upgrade or by an older install, re-enable it — otherwise **Run** stays queued and never finishes.

Also ensure the Dolibarr cron runner itself is scheduled on the host (CLI cron calling `cron_run_jobs.php`, or the panel equivalent).

### 5. Import a starter template

In Knot:

1. Open **Marketplace** → **Templates** (or **Workflows** → starter import)
2. Pick a **starter** template (e.g. manual trigger + logic, or a simple Dolibarr path)
3. Click **Use this template** — the editor opens with the canvas ready

Avoid templates that need Pro Pack connectors until Pro Pack is installed and licensed.

### 6. Simulate (dry-run)

In the editor toolbar:

1. Click **Simulate** (or **Test**)
2. Wait for the panel to show per-node results
3. Fix lint / config issues if any (Problems panel → optional « Copy fix for chatbot »)

Simulate does **not** require cron. It runs in-request.

### 7. Run (queued execution)

1. Activate the workflow if it is still a draft
2. Click **Run**
3. Open **Executions** — status should move from queued → running → success

If it stays **queued**: return to step 4 (cron disabled or host cron not firing). The UI may show a cron health hint after queue.

## What “alert” means in Core

| Connector | Behaviour |
|-----------|-----------|
| **`notification.alert`** (Core) | **Audit log only** — writes to `llx_knot_audit_log`. Does **not** send email, Slack, Discord, or webhooks. |
| **`action.email`** (Core) | Real SMTP via Dolibarr mail config |
| **`notification.alert_fanout`** (Pro Pack) | Multi-channel fan-out (Slack, Discord, webhook, formatted alert email) |

## Smoke reference (automated)

Playwright narrative: `tests/e2e/specs/zero-to-hero.spec.ts` (marketplace template → editor → Simulate/Run controls visible). Requires E2E auth env.

## Demo environment (demo.knot.tools)

Reproducible path for operators validating the suite shell:

1. Open **Accueil** (`?mode=home`) — product chooser, not the Core dashboard.
2. Open **Santé** (`?mode=suite-health`) — confirm cron banner is green / last run recent.
3. **Knot Core** → **Marketplace → Templates** (or Workflows starters) → **Use this template**.
4. **Simulate**, then **Run** + **Executions** (depends on host cron).

If a previous Migration journey pollutes Discovery, use Mission Control « Start a new migration » (journey state reset) before retesting Migration.

## Related docs

- [Admin guide index](README.md)
- [Marketplace (admin)](marketplace.md)
- [Connectors inventory](../connectors-inventory.md)
- [Deployment](../deployment.md)
- [Suite Accueil / Santé](../decisions/adr-extension-navigation.md) (navigation contract)

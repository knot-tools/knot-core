# Knot User Guide

Knot lets Dolibarr teams build **visual workflow automations** directly inside Dolibarr, without a mandatory cloud service. This guide summarises how to get value from Knot safely.

## Welcome

Knot is a Dolibarr module (**Knot Tools** brand). You design node-based workflows (triggers, actions, conditions) that orchestrate Dolibarr data and, with compatible extensions, external HTTP and SaaS APIs. Execution stays on your server; secrets are encrypted at rest.

## Recommended flow

1. **Enable the module** — Dolibarr administrator activates Knot under *Setup → Modules* (Custom).
2. **Complete the setup wizard** — cron, permissions, SMTP check, first-run completion flag.
3. **Create credentials** — *Credentials* stores API keys, OAuth tokens and similar data (AES-256-GCM).
4. **Author or import a workflow** — use the *Editor* to draw the graph, or import JSON from *Assistant* / `.knot.json` / ZIP bulk.
5. **Simulate and inspect** — *Simulate* runs a synchronous dry path where supported; *Executions* shows traces, logs and replay.
6. **Activate only when validated** — active workflows run on their triggers (manual, cron, webhooks, Dolibarr hooks). Review **risk** labels for write/delete/pay operations before enabling.

## Main features

- **Visual editor** — nodes, edges, JSON configuration, data pinning, drag-and-drop mapping between outputs and inputs.
- **Executions** — per-node input/output (masked secrets), duration, retries, replay from a chosen node, exportable context.
- **Credentials** — create, update, test, encrypted storage; never logged in clear text.
- **Assistant** — generates a portable prompt for any chatbot, then imports the returned JSON into the editor.
- **Approvals** — human pause via the Approval node and the approval inbox.
- **Conflicts** — reporting when Knot workflows may overlap native Dolibarr automations or duplicate triggers.

## Best practices

- **Single source of truth** — for one business rule, prefer either native Dolibarr automation **or** Knot, not both fighting the same event.
- **Guardrails** — use *Filter* or *If / Else* so several workflows do not write the same record unintentionally.
- **Pinning** — pin outputs while designing; avoid pinned production data in live workflows.
- **Errors** — when an execution fails, open the node detail; Knot maps many engine errors to documented hints (`docs/troubleshooting.md` in the module).

## Documentation map

- **Architecture & security** — `docs/architecture.md`, `docs/security.md`
- **Connectors & extensibility** — `docs/connectors.md`, `docs/extensibility.md`
- **Operators & admins** — `docs/admin-guide.md`, beta pack under `docs/beta-testers/`
- **English mirror (short)** — `docs/user-guide.en.md`

## Support

Issues and feature requests: project repository (see `README.md` in the module root). Security-sensitive reports: follow `docs/security.md` contact guidance.

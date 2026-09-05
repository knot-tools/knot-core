<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="img/brand/knot-core-horizontal-dark.png">
  <img alt="Knot Tools — Knot Core, visual workflow automation for Dolibarr" src="img/brand/knot-core-horizontal-light.png" width="520">
</picture>

### **Visual workflow automation for Dolibarr**

*A modern node-based editor, self-hosted next to Dolibarr, to orchestrate your ERP and integrations.*

**Knot Tools™** is a registered trademark. This repository ships **Knot Core**, the GPL-3.0 Dolibarr module. Product and extension information: **[knot.tools](https://knot.tools)**.

> **Public beta** — product site: **[knot.tools](https://knot.tools)**. Documentation: **[docs.knot.tools](https://docs.knot.tools/)**.

[![Status](https://img.shields.io/badge/status-public%20beta-8B5CF6?style=flat-square)](https://knot.tools)
[![CI](https://github.com/knot-tools/knot-core/actions/workflows/ci.yml/badge.svg)](https://github.com/knot-tools/knot-core/actions/workflows/ci.yml)
[![Version](https://img.shields.io/github/v/release/knot-tools/knot-core?style=flat-square&color=8B5CF6)](https://github.com/knot-tools/knot-core/releases/latest)
[![Dolibarr](https://img.shields.io/badge/Dolibarr-V20%2B-1F2937?style=flat-square)](https://www.dolibarr.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![PSR-12](https://img.shields.io/badge/code%20style-PSR--12-1F2937?style=flat-square)](https://www.php-fig.org/psr/psr-12/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?style=flat-square&logo=typescript&logoColor=white)](https://www.typescriptlang.org)
[![Vue](https://img.shields.io/badge/Vue-3.5-42B883?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org)
[![Vite](https://img.shields.io/badge/Vite-8-646CFF?style=flat-square&logo=vite&logoColor=white)](https://vite.dev)
[![Tailwind](https://img.shields.io/badge/Tailwind-3-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com)
[![Tests](https://img.shields.io/badge/tests-PHPUnit_·_Vitest-EC4899?style=flat-square)](#tests)
[![License](https://img.shields.io/badge/license-GPL--3.0-green?style=flat-square)](LICENSE)

</div>

---

## At a glance

**Knot Core** is a Dolibarr module that turns your ERP into a visual automation platform. You draw workflows on a canvas; Knot runs them synchronously for tests and via Dolibarr cron in production.

- **Self-hosted** on the same stack as Dolibarr
- **Native Dolibarr** entities, permissions, multi-company, audit trail
- **Open source** — Knot Core is GPL-3.0; optional commercial extensions (Pro Pack, Migration) are distributed separately via **[knot.tools](https://knot.tools)**
- **Execution stays on your infra**; outbound calls use connectors you configure

See [`docs/license-boundary.md`](docs/license-boundary.md) for the Core vs extension boundary.

---

## Requirements

| Component | Version |
|-----------|---------|
| Dolibarr | V20+ |
| PHP | 8.1+ |
| MySQL / MariaDB | 5.7+ / 10.5+ |

---

## Install

Download the latest release from **[knot.tools/downloads/knot-core/latest](https://knot.tools/downloads/knot-core/latest)** or clone this repository into `htdocs/custom/knot/`.

```bash
cd /path/to/dolibarr/htdocs/custom/
git clone https://github.com/knot-tools/knot-core.git knot
cd knot/frontend && npm ci && npm run build
```

Enable the module in Dolibarr and open **Knot** from the menu. Operator guide: [`docs/admin-guide/`](docs/admin-guide/).

---

## Tests

This repository includes PHPUnit and Vitest suites (no Dolibarr instance required for the default CI jobs).

```bash
composer install
vendor/bin/phpunit

cd frontend && npm ci && npm test && npm run build
```

CI: [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

---

## Documentation

| Doc | Purpose |
|-----|---------|
| [`docs/architecture.md`](docs/architecture.md) | Backend / frontend architecture |
| [`docs/user-guide/`](docs/user-guide/) | End-user guide |
| [`docs/admin-guide/`](docs/admin-guide/) | Install / ops |
| [`docs/connectors.md`](docs/connectors.md) | Connector contract |
| [`docs/security.md`](docs/security.md) | Threat model, crypto |
| [`docs/workflow-format.md`](docs/workflow-format.md) | Workflow JSON |
| [`docs/openapi.yaml`](docs/openapi.yaml) | Internal API OpenAPI 3.1 |

More guides on **[docs.knot.tools](https://docs.knot.tools/core/getting-started/welcome/)**.

---

## Licence

**GPL-3.0-or-later** — see [`LICENSE`](LICENSE). Commercial extensions use separate licences when loaded through Knot Core's extension interfaces; see [`LINKING-EXCEPTION.md`](LINKING-EXCEPTION.md).

Dependency licences: [`LICENSES.md`](LICENSES.md).

**Trademark.** **Knot Tools™** is a registered trademark. Third-party use requires permission of the rights holder.

---

## Security

Do **not** open a public issue for vulnerabilities. See [`SECURITY.md`](SECURITY.md) — disclosure to **security@knot.tools**.

---

<div align="center">

**Knot Tools™ — Visual workflow automation for Dolibarr** (*Knot Core*).

</div>

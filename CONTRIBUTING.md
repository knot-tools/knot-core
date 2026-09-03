# Contributing to Knot Core

Thank you for considering a contribution to **Knot Core**, the GPL-3.0 Dolibarr workflow module published at **[github.com/knot-tools/knot-core](https://github.com/knot-tools/knot-core)**.

## What we welcome

- **Bug reports** with reproduction steps, environment details, and redacted logs → [open an issue](https://github.com/knot-tools/knot-core/issues/new/choose).
- **Feature suggestions and use cases** → GitHub issue or email **`contact@knot.tools`**.
- **Documentation fixes** → pull request or issue labelled `documentation`.
- **Security reports** → email **`security@knot.tools`** following [`SECURITY.md`](SECURITY.md). **Never** in a public issue.

## Pull requests

During the **public beta**, **`knot-tools/knot-core` does not accept pull requests from outside the maintainer team**. Forking is disabled on the repository; issues and security reports remain the right channel for community input.

When we open external contributions again, pull requests should:

- Solve a tracked issue or a clearly scoped improvement.
- Include tests for behavioural changes (PHPUnit / Vitest as applicable).
- Update `CHANGELOG.md` under `[Unreleased]` for user-visible changes.
- Follow the conventions below.

We aim for an initial review within **5 business days** once external PRs are enabled. See [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md).

## Style and conventions

- **Backend:** PHP 8.1+, namespace `Knot\`, **PSR-12** (`phpcs.xml.dist`), `declare(strict_types=1)`, type hints, no inline SQL outside repositories.
- **Frontend:** Vue 3, TypeScript strict, Tailwind with `k-` prefix, Vite.
- **Tests:** PHPUnit for backend, Vitest for frontend. New behaviour ships with new tests.
- **Commits:** **Conventional Commits** (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`, `perf:`, `style:`, `build:`, `ci:`). Subject in imperative present, ≤ 72 characters, no trailing period.
- **Dependencies:** MIT, Apache-2.0, BSD, ISC, LGPL, or CC0 only. **No n8n code or derivatives**, no fair-code / SUL / Commons Clause / BSL.

## Local checks before pushing

```bash
composer install
vendor/bin/phpcs --standard=phpcs.xml.dist class/
vendor/bin/phpunit --no-coverage

cd frontend && npm ci && npm run build && npm test
```

CI runs on **PHP 8.1 / 8.2 / 8.3** and **Dolibarr 20 / 21 / 22 / 23 / 24** (`dolibarr-matrix`, PHP 8.2). Failures on `phpcs`, `phpunit`, `composer audit`, `npm audit`, or Gitleaks block merge.

## Authorship and licence

By submitting a contribution, you agree it will be licensed under **GPL-3.0-or-later**, the licence of this repository.

## Where to start

| Resource | Location |
|----------|----------|
| Architecture | [`docs/architecture.md`](docs/architecture.md) |
| Connectors | [`docs/connectors.md`](docs/connectors.md) |
| Security model | [`docs/security.md`](docs/security.md) |
| User / admin guides | [`docs/user-guide/`](docs/user-guide/), [`docs/admin-guide/`](docs/admin-guide/) |
| Public docs site | [docs.knot.tools](https://docs.knot.tools/) |

Commercial extensions (**Pro Pack**, **Migration**) live in separate repositories and are distributed via [license.knot.tools](https://license.knot.tools).

---

*Last reviewed: 2026-05-24.*

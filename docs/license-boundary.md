# License boundary — Core (GPL) vs extensions (PolyForm)

Canonical reference for developers and agents. Founder decision **May 2026**.

## Products

| Artifact | Licence file | What it covers |
|----------|--------------|----------------|
| Knot Core module | [`LICENSE`](../LICENSE) (GPL-3.0-or-later) | Engine, UI, Dolibarr connectors shipped with Core, API, tests |
| Linking exception | [`LICENSE-EXCEPTION.md`](../LICENSE-EXCEPTION.md) | Draft — GPL Core loading PolyForm extensions (counsel pending) |
| Knot Pro Pack | `pro-pack/LICENSE.md` (PolyForm Shield) | Premium connectors — **not** in Core repo |
| Knot Migration | `knot-migration/LICENSE.md` (PolyForm Shield) | Migration product — private repo |
| Third-party deps | [`LICENSES.md`](../LICENSES.md) | npm/Composer inventory (MIT, Apache, …) |

## Distribution channels

| Channel | Core | Pro Pack | Migration |
|---------|------|----------|-----------|
| Dolistore.com | ✅ Free listing | ❌ | ❌ |
| GitHub public | ✅ | ❌ | ❌ |
| license.knot.tools | ✅ Alert fallback only (Core) / primary (extensions) | ✅ Purchase + ZIP | ✅ Purchase + ZIP |

## Runtime boundary

```
┌─────────────────────────────────────────┐
│ Knot Core (GPL)                         │
│  ExtensionRegistry                      │
│  LicenseValidator → DolistoreValidator  │
│    (HTTP license.knot.tools)            │
└──────────────┬──────────────────────────┘
               │ discovers knot-extension.json
               ▼
┌─────────────────────────────────────────┐
│ Extension module (PolyForm)             │
│  htdocs/custom/knotpropack/ …           │
│  LicenseGate on connector execute()     │
└─────────────────────────────────────────┘
```

- Core **must not** embed Pro Pack connector implementations (except optional
  stubs removed when Pro Pack installed — see connector migration docs).
- Extensions **must not** claim GPL in headers or README.

## Public Utility API (GPL linking exception)

Listed in [`LICENSE-EXCEPTION.md`](../LICENSE-EXCEPTION.md) (WORKING DRAFT — counsel
validation required). Extensions may call these Core classes through the linking
exception without becoming GPL:

| Class | Role |
|-------|------|
| `Knot\Security\HttpClient` | Outbound HTTP client |
| `Knot\Security\UrlPolicy` | SSRF defence helper |
| `Knot\Security\OAuth2Helper` | OAuth 2.0 flow helper |
| `Knot\Engine\ExpressionResolver` | Workflow expression evaluator |
| `Knot\Repository\AuditLogRepository` | Audit log writer |
| `Knot\Extension\LicenseValidator` | Extension licence validator |
| `Knot\Licensing\Bootstrap` | Licensing bootstrap helper |

Future additions must carry an `@api` PHPDoc annotation and be listed here and in
`LICENSE-EXCEPTION.md`. The copyright holder will not narrow this list retroactively
(see exception text).

## Manifest licence block

```json
"license": {
  "type": "commercial",
  "validation": "dolistore",
  "productId": "knot-pro-pack"
}
```

| Field | Meaning |
|-------|---------|
| `validation: none` | Free extension (Core built-ins) |
| `validation: local` | Legacy file-based licence |
| `validation: dolistore` | **Legacy name** — validates via license portal |
| `validation: license` | **Preferred alias** (V2.5.1+) — same behaviour as `dolistore` |

The PHP class remains `DolistoreValidator` until a rename is justified.

## Free Core stays free

- No artificial licence gate on Core engine features.
- TierGate / nagware must not block free workflows; commercial CTAs only for
  Pro Pack connectors and Migration features.

## Checklist before tag / sale

- [ ] `grep -ri 'GPL' pro-pack/README` — no product licence GPL
- [ ] Website Pro Pack pages say **source-available / PolyForm**
- [ ] Dolistore listing text = Core only
- [ ] `LICENSE-EXCEPTION.md` marked draft until counsel sign-off

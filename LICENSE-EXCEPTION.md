> ⚠️ WORKING DRAFT — COUNSEL VALIDATION REQUIRED BEFORE ANY PUBLICATION OR SALE.
> This exception is not effective until validated by qualified legal counsel.
> Revised: 2026-05-22 (v2 — Public Utility API explicit list + governing law + extended exclusion).

# Knot Tools™ Core — GPL Linking Exception (draft)

Knot Tools™ Core is licensed under the GNU General Public License version 3
or later (GPL-3.0-or-later), with the following exception, granted by the sole
copyright holder, Sébastien Audel (trading as EXIATIS).

## Exception

As a special exception, the copyright holder gives permission to load
Knot Tools™ Core components, and to link or combine Knot Tools™ Core with
independent modules (hereafter "**Extensions**") that communicate with
Knot Core exclusively through its **public extension API**, regardless of
the license terms of these Extensions, and to copy and distribute the
resulting combined work under terms of your choice for the Extension part,
provided that the conditions in section "**Conditions**" below are met.

For the purposes of this exception, the **public extension API** of
Knot Tools™ Core comprises:

### (i) Public extension contracts (interfaces and traits)

- `Knot\Connectors\ConnectorInterface`
- `Knot\Connectors\DryRunAware`
- `Knot\Connectors\CredentialAware`
- `Knot\Connectors\RiskAware`
- the `#[Knot\Connectors\Connector]` PHP attribute
- any class, interface, trait, or method explicitly annotated `@api` in its
  PHPDoc by the copyright holder.

### (ii) Public extension loading mechanism

- the `Knot\Extension\ExtensionRegistry` dynamic loading mechanism, and the
  manifest format `knot-extension.json` it consumes.

### (iii) Public Utility API (utility services intended to be consumed by Extensions)

- `Knot\Security\HttpClient` — outbound HTTP client.
- `Knot\Security\UrlPolicy` — SSRF defence helper.
- `Knot\Security\OAuth2Helper` — OAuth 2.0 flow helper.
- `Knot\Engine\ExpressionResolver` — workflow expression evaluator.
- `Knot\Repository\AuditLogRepository` — audit log writer.
- `Knot\Extension\LicenseValidator` — extension licence validator.
- `Knot\Licensing\Bootstrap` — licensing bootstrap helper.
- any class added to the Public Utility API in future versions, identified
  by an `@api` PHPDoc annotation and listed in `docs/license-boundary.md`.

The copyright holder may extend the Public Utility API in future versions
of Knot Tools™ Core by adding new classes or interfaces and tagging them
`@api`. Such additions automatically benefit from this exception. The
copyright holder will not narrow the Public Utility API in a way that
would retroactively diminish rights already granted under this exception.

## Conditions

This exception applies only if **all** the following conditions are met:

**(a) No substantial code embedding.**
The Extension does not incorporate, copy, or embed substantial portions of
Knot Tools™ Core's source code beyond what is strictly necessary to call
the Public Extension API. Trivial inclusions (interface stubs, signature
copies as required for static analysis, automatically generated proxies)
are permitted.

**(b) GPL compliance for the Core portion.**
You comply with GPL-3.0-or-later for the Knot Tools™ Core portion of the
combined work, including making the Knot Tools™ Core source code available
as required by the GPL.

**(c) Communication exclusively through the public extension API.**
The Extension communicates with Knot Tools™ Core exclusively through the
public extension API defined above. Direct use of internal classes
(classes located outside the API listed above, or classes marked
`@internal`) is not covered by this exception.

## Scope and limits

### Original works of the copyright holder only

This exception applies only to the original works authored by the copyright
holder within Knot Tools™ Core. It does not extend to:

- the file `core/modules/modKnot.class.php`, which derives from Dolibarr's
  `DolibarrModules` class and remains governed by GPL-3.0-or-later without
  exception;
- any other file in Knot Tools™ Core that is itself a derivative work of a
  third-party GPL-licensed work, to the extent of that derivation;
- third-party libraries and dependencies, which remain governed by their
  respective licences (see `LICENSES.md`).

### Future versions, non-retroactive

This exception is granted for current and future released versions of
Knot Tools™ Core. It is not retroactive in a way that diminishes rights
already granted under the plain GPL-3.0-or-later for prior released
versions.

### Distribution of the combined work

You may copy and distribute the resulting combined work under terms of
your choice **for the Extension part only**, including but not limited to
proprietary terms, source-available terms (such as PolyForm Shield 1.0.0,
which is the licence chosen by the copyright holder for Knot Tools™ Pro
Pack and Knot Tools™ Migration), or any open source licence compatible
with GPL-3.0-or-later. The Knot Tools™ Core portion of the combined work
remains governed by GPL-3.0-or-later.

## Disclaimer

This exception does not waive any other right of the copyright holder
under copyright law, trademark law (see Knot Tools™ trademark, INPI no.
5257612), or any other applicable law. The Knot Tools™ name, logo, and
related branding remain subject to French and international trademark
law and are not licensed by this exception.

## Governing law

This exception is governed by **French law**. Any dispute arising out of
or in connection with this exception that cannot be resolved amicably
within thirty (30) days of written notice shall be subject to the
exclusive jurisdiction of the **Tribunal de commerce de Paris**, without
prejudice to mandatory rules of consumer protection, competition law, or
any other applicable mandatory provisions of law.

## Reference and contact

For questions about the scope of this exception, contact:
`contact@knot.tools`

Public reference document tracking the boundary between Knot Tools™ Core
and PolyForm extensions:
[`docs/license-boundary.md`](docs/license-boundary.md).

---

*Document version: v2 (2026-05-22 — drop-in replacement of v1 draft).*
*Status: WORKING DRAFT pending counsel validation.*

# Changelog

**Knot Core** is in **public beta** under **GPL-3.0**. Downloadable releases:
**[knot.tools/downloads/knot-core/latest](https://knot.tools/downloads/knot-core/latest)**.
Source, tags, and GitHub Releases:
**[github.com/knot-tools/knot-core](https://github.com/knot-tools/knot-core)**.

## [Unreleased]

## [2.13.17] - 2026-09-01

### Fixed

- **Licence persist:** encrypted activation codes are now stored in
  `llx_knot_config` (`licensing.activation_enc.<extensionId>`) as well
  as the signed cache. Inspect restores the code from cache, then
  config, so a Core update that drops `activationCodeEnc` from
  `*.cache.json` no longer forces testers to re-type keys.
- **Offline grace:** a signed cache without `activationCodeEnc` uses
  the 14-day offline grace (`fallbackFromCache`) instead of flipping
  the UI to INVALID/`missing` after TTL.

## [2.13.16] - 2026-09-01

### Fixed

- **Dolistore ZIP:** public snapshot now ships `lib/load_dolibarr.inc.php`
  (entry bootstrap). Missing it 500'd `workflows/preview.php` on zip-only
  installs (demo). `publish-to-public.sh` INCLUDE_DIRS now includes `lib`.

## [2.13.15] - 2026-07-15

### Changed

- **Dolistore packaging compliance:** ZIP name is now
  `module_knot-<version>.zip` (wiki / Deploy); CDN keeps alias
  `knot-<version>.zip` (same bytes). Module ID reserved range
  **262871–262880** (`numero` 262871, rights 262872–262876). Entry points
  load Dolibarr via `lib/load_dolibarr.inc.php` (custom + root installs).
- **UX polish (score-5):** trigger panels fully i18n (webhook save-first hint),
  inspector Test tab honesty banner (local simulation ≠ live run), unified
  `KEmptyState` empty states on Workflows (starter import CTA) and Inbox,
  Suite Home next-action CTAs (Workflows / Install Pro / Open Migration),
  Doctor links to Modules disable + `docs/uninstall.md` (no purge UI), and
  FR copy cleanup on Accueil / Editor / Doctor. Fresh-install smoke adds an
  async drain check (`CronWorker` tick → execution success).
- **Pro Pack upsell honesty:** locked palette nodes open activate / Pro hub
  (not tooltip-only); Simulate surfaces `license_required` with CTA.
- **Discovery scores:** `docs/testing/discovery-pre-dolistore.md` retargeted
  to suite scores~5 (Pro 0.1.10 + Migration 0.21.9 signed on license-vm).
- **OfficialManifestSignatures:** Migration primary pin after Workspace nav
  removal (transition keeps previous 0.21.9 digest).

### Fixed

- **Assistant prompt:** stop teaching `itemsPath="{{$json.items|rows}}"` and
  `$json` on `logic.if` left — prefer `{{$nodes.<producerId>.json.*}}` /
  `{{$loop.item.*}}` so first-import stays free of `expression_json_chain`
  (aligned with editor lint + chatbotFix DSL contract).
- **Editor edges:** KnotEdge uses solid stroke (SVG gradient `url(#…)` could
  stay invisible until a node drag); stronger `updateNodeInternals` retries
  after load / catalog / fitView.
- **Lint panel:** dedupe PHP `KNOT_DSL_*` with local TS codes (same finding
  shown once). REAL-KNOT-L03/L04 seed expressions use `$nodes.*`.

## [2.13.14] - 2026-07-14

### Fixed

- **Starter DSL hygiene:** all `examples/starter/*.knot.json` use `$nodes.*` /
  `$loop.item.*` so ProblemsPanel shows **0** `expression_json_chain` warnings
  (`StarterExamplesLintCleanTest`).
- **Apply across volumes:** `Installer::swap` falls back to recursive copy when
  `rename()` fails (EXDEV between `DOL_DATA_ROOT` staging and `custom/`).
- **Apply lab HTTP:** `ZipDownloader` allows `http://localhost` /
  `http://127.0.0.1` artefacts for local signed labs (production still HTTPS-only).

### Changed

- **Updates UX:** card layout per product, human status badges (Update available /
  Up to date / Ahead of channel), trust banner (Ed25519 + rollback), no purchase
  CTA on Core, and a clear “all current” empty state. Starter **10** adds nested
  If/Switch/Loop plus enriched HTML email report (corridors + route tags).
- **Docker Apply N−1:** standalone `docker/docker-compose.apply-n1.yml` +
  `scripts/docker_apply_n1_bootstrap.sh` / `docker_apply_n1_prepare_lab.sh` so
  operators can test Core Apply from **2.13.12 → N** without the `:ro` bind-mount
  (ports **8088** / **8199** by default). Proven **2.13.12 → 2.13.14**.
- **Pre-Dolistore validation:** Assistant LLM fixtures +
  `assistant-import-lint.spec.ts`, fresh-install smoke
  (`scripts/docker_fresh_install_smoke.sh`), discovery scores in
  `docs/testing/discovery-pre-dolistore.md`, honesty known-limits (CodeNode /
  n8n import / alert / Assistant external chatbot).

## [2.13.13] - 2026-07-14

### Fixed

- **Cron jobs enabled on install/upgrade:** descriptor `status => 1` (already)
  plus `init()` UPDATE so existing installs with `status=0` are re-enabled
  (async Run / retention / health). Guarded by `ModKnotCronJobsTest`.
- **EditorView Vitest:** mock `onViewportChange` / `setViewport` (CI Frontend build).
- **Suite Accueil ≠ Core dashboard:** `?mode=home` renders `SuiteHomeView`
  (product chooser for Core / Migration / Pro Pack). Core dashboard stays on
  `?mode=dashboard` under Knot Core. Suite **Santé** (`?mode=suite-health`) and
  **Mises à jour** are top-level before Configuration; Updates removed from Core
  children. `SuiteHealthPanel` lives on the Santé page (not the Core dashboard).
  Accueil uses `KHero` mesh band + staggered product tiles (brand-first suite landing).
- **Suite Cmd+K / single rail (S1):** Command palette Navigate includes Accueil,
  Santé, Updates, Configuration; keyboard arrows/Enter work across workflows,
  extension entries, and nav. Migration hides its local sidebar whenever
  `aside.knot-nav` is present (option B single chrome).

- **Suite sidebar hierarchy:** Accueil (`mode=home`) at the top; Core children
  nest under **Knot Core** (incl. Observabilité); **Configuration** is suite-level
  (after extensions); Migration `settings` no longer appears in the submenu.
  Empty `data-knot-ext-nav-hash` no longer marks every Core child `is-active`
  (purple outline bug).

- **Product truth (C0):** n8n import marked **out of scope** (not shipped);
  Core UI density control not advertised; Pro Pack `simulate()` docblocks
  aligned with LicenseGate (already enforced in code).

- **Sidebar branding:** brand name is **Knot Tools**; footer tagline
  « Automatisation · Migration · Extensions ». Golden **Knot Core** parent
  nests Core-only entries; Marketplace / extensions stay top-level.

- **Host layout ≤880px:** inline `knot-host-layout-guard` in `preview.php` / `setup.php` mirrored
  the rail `padding-left` without the `knot-host.css` mobile override, so stacked nav still reserved
  256px and caused ~8px horizontal overflow (assistant no-overflow E2E). Guard now zeroes padding
  under `max-width: 880px`; `.knot-nav` uses `box-sizing: border-box`.
- **Marketplace E2E selectors:** specs no longer require a page-level “Marketplace” heading (editorial
  home uses Spotlight). Shared `expectMarketplaceShell` checks `data-mode` + topbar navigation.
  Bundled / template gallery tests open `#/templates` (hash router) instead of `?tab=` alone.
- **Marketplace `#/templates` tab sync:** `StorefrontTabsBlock` selected Packs even on the Templates
  hash route when `?tab=` was absent; initial tab (and route watch) now follow `#/templates` / `#/packs`.
- **Unified sidebar option B:** Lucide→Font Awesome icon map (`switch`/`settings`/`lifebuoy`);
  Core leftnav children get hash `is-active`, journey badges via `KnotCore.navigationBadges`,
  tour anchors (`data-tour=sidebar-journey|sidebar-help`), and humanized label fallbacks when
  Dolibarr langs miss Vue `nav.*` keys.

### Added

- **C1 time-to-value:** Core cronjobs default to `status=1` on install; Workflows multi-select
  checkboxes for bulk ZIP export; Simulate timeout offers « Retry as Run »; Run queued shows
  cron health hint via `health.php`; chatbot fix prompt includes DSL contract + incremental
  mode; `repairWorkflowDefinition` auto-repairs edges/ids/defaults before chatbot; starter
  templates 07–09; E2E `zero-to-hero.spec.ts` + responsive matrix 1366×768 / 1280×720 / h768.
- **C4 polish (partial):** human execution log lines (`humanExecutionLog.ts` + simulation panel);
  editor selection **and viewport** restore via `editorUiState` sessionStorage; ADR extension
  navigation (`docs/decisions/adr-extension-navigation.md`); Core forwards `ui.navigation` into
  `KNOT_EXTENSIONS` + leftnav children when the extension mode is active; proofs index
  `docs/proofs/README.md` + **`docs/proofs/reference-module.md`** (introspection, risk-grammar,
  Ed25519 updates); `useEditorWorkflowApi` seam wired through EditorView CRUD/simulate/run.
  Inspector Test tab empty state points to canvas **Simulate** (i18n ×6; raw JSON optional/collapsed).
  risk-grammar §3 permission pills remain **PARTIAL** (honest status table). Editor toolbar at
  1366×768: icon-first labels below `xl` + `overflow-x-hidden` to reduce action-row overflow.
- **Suite health panel (C2-4):** `SuiteHealthPanel` on `?mode=suite-health` —
  Core / Pro Pack / Migration installed **and published** versions (via `updates.php`),
  update chip, license status per product, cron scheduler state, and live execution
  counters. i18n ×6. Error state offers **Retry**.
- **Star journey + cron banner (product polish):** dashboard `StarJourneyPanel` (3 SME paths →
  Workflows deep-link) and `CronHealthBanner` when KnotCronWorker is missing/disabled/never ran;
  Workflows shows starter import hint for `?starter=`; Inspector Test tab leads with human summary
  (raw JSON collapsed); Assistant framed as prompt + repair; `notification.alert` label clarifies
  audit-only (no send); ConnectorsView tip + palette fallback spell out audit-only vs
  `notification.alert_fanout` (Pro Pack).
- **Chatbot fix catalogue (C1-1a):** `buildChatbotFixMessage` embeds installed connector slugs from
  the capabilities/connectors API (fallback Core catalogue when unavailable).
- **E2E (C1-2 / C1-3c):** Playwright projects `chromium-dsf-125` / `chromium-dsf-150`;
  `import-export.spec.ts` asserts multi-select Export ZIP (download when list non-empty).
- **Updates apply — actionable error messages (C2-3):** `license_invalid`,
  `release_version_mismatch`, and `extension_unknown` error codes now surface dedicated toasts
  with recovery guidance (i18n ×6), complementing the existing `activation_code_missing`,
  `license_download_token_denied`, `backend_unreachable`, and `release_signature_invalid` toasts.
- **InboxView i18n:** approval inbox title, actions, empty state and decision toasts use `$t` / `inboxPage.*` (6 locales).
- **E2E licence prep (C1/C2):** Playwright `license-monetization-prep.spec.ts` — asserts
  `dolistore_licensing_ready` stays false, `license_status` shape, mocked activation modal flow,
  marketplace CTA not pointing at license checkout. Full prod signed chain remains deferred.
- **Editor — Edit JSON dialog:** toolbar action to view the current workflow JSON and paste a
  corrected one (chatbot round-trip) straight onto the canvas — same normalizer/repair pipeline
  as import (`normalizeWorkflowImport`), marks the editor unsaved, no bulk re-import needed.
- **Editor — Problems panel:** « Copy fix for chatbot » action (shared `lib/chatbotFix.ts`
  builder with `AssistantView`) — copies current lint findings + workflow JSON as a correction
  prompt. Beta feedback follow-up on `KNOT_DSL_EXPRESSION_JSON_CHAIN` triage.

### Changed

- **Legal pointer:** `docs/legal/cgv-knot-migration-brouillon.md` → slim beta
  access terms at https://license.knot.tools/legal/terms (ADR-31 opt-in).
- **OfficialManifestSignatures:** pin Pro Pack `0.1.10` and Migration `0.21.9`
  manifest signatures (transition pins retained for prior releases).

### Documentation

- **C5 commercial support:** [`docs/runbooks/commercial-support.md`](docs/runbooks/commercial-support.md)
  operable for solo operator — business days Mon–Fri, timezone
  **America/Martinique (UTC−4)**, mailbox ticket store, billing hand-off,
  reply templates; removed pure « draft » placeholder status for beta use.
- **C1 integrator first success:** one-page checklist
  [`docs/admin-guide/integrator-first-success.md`](docs/admin-guide/integrator-first-success.md)
  (install → activate → wizard → starter → Simulate → Run; cron must stay
  `status=1`). **Demo environment** section documents the seeded demo path for
  replaying TTV. Linked from the admin-guide index.

### Testing

- **Visual baseline Accueil:** `?mode=home` added to Playwright visual-baseline
  primary views (suite landing regression guard).
- **Updates apply Pro Pack:** E2E no longer hard-codes 0.1.3→0.1.4 — applies when
  an update is available, skips when already latest / Apply disabled.
- **Marketplace copy (S5):** locked template CTAs use beta programme wording
  (knot.tools), not « Acheter / Buy Pro Pack ».
- **Marketplace discovery E2E:** `marketplace-discovery.spec.ts` asserts
  `home_discovery` mount + external CTAs stay off `license.knot.tools`.
- **Dolistore package readiness (S6):** `python3 scripts/package_dolistore.py`
  → `build/knot-2.13.12.zip` **VERDICT: PASS** (local, no Dolistore upload).

### Changed

- **Marketplace locked CTAs:** i18n `lockedCtaBuyProPack` aligned with
  `buyOnLicensePortal` (beta honesty, pre-checkout).
- **C2 monetisation ops (draft):** commercial support runbook
  (`docs/runbooks/commercial-support.md` — channels, severity, evidence bundle);
  operator Apply error cheat-sheet (`docs/runbooks/updates-apply-operator.md`) plus
  quick-reference section in `updates-apply.md` for `activation_code_missing`,
  `release_signature_invalid`, `license_download_token_denied`, `backend_unreachable`.
  `package_dolistore.py` has no `--dry-run` (only `--skip-audit` / `--skip-gitleaks`);
  full pack + audit **must PASS** before any commercial GPL / Dolistore release.
  Does **not** flip `dolistore_licensing_ready`.
- **Beta known-limits:** CodeNode marked **not shipped** (FR + EN) — removes the false
  implication that a Pro Pack PHP sandbox exists.
- **Extensibility:** unified sidebar option B documented as landed (Core forwards
  `ui.navigation`), not as future tense.

### Tooling

- **Inbox E2E:** `inbox.spec.ts` assertions aligned with localized headings and empty state.

### Fixed

- **Updates apply — channel + version guards (C2-1b):** the `channel` field from the frontend
  request body is now forwarded to the license download-token endpoint (previously only the
  global `KNOT_RELEASE_CHANNEL` was used, causing the backend to potentially resolve the wrong
  channel). A new `release_version_mismatch` guard (HTTP 409) rejects apply when the backend
  resolves a different version than the one the operator selected.
- **Updates apply (extensions):** `Installer::prepare()` adopts the archive top-level folder when
  it differs from the live install directory (e.g. ZIP ships `knotmigration/` but the extension
  was deployed under `custom/knot-migration/`) — previously the apply failed with
  « manifest.json missing at top-level folder » at beta testers'. The mismatch error now names
  the folder shipped by the archive. Onboarding doc aligned on `custom/knotmigration/`.
- **Updates view:** actionable error toasts for `activation_code_missing` (activate the licence
  first), `license_download_token_denied` and `backend_unreachable` instead of the generic
  apply failure copy (i18n ×6).
- **`DescriptorCache`:** replace `GLOB_BRACE` (unavailable on non-GNU libc — musl/Alpine) with
  explicit glob patterns so descriptor hashing works on every supported platform.
- **`OfficialManifestSignatures`:** correct the Migration transition-pin comment — the pinned
  digest belongs to the 0.21.7 manifest (verified against the signed release ZIP), not 0.21.5.

## [2.13.12] - 2026-06-08

### Added

- **Assistant lint loop:** post-import server+local lint in `AssistantView`, copy-fix message for external chatbot.
- **WorkflowValidator:** warn-only rules for unknown `objectType` slugs, fragile `{{$json.*}}` chains, invalid `logic.if` operators.
- **Editor canvas:** dynamic branch handles from connector `outputs`, coloured edge labels/markers, execution-path animation (`executedEdgeIds` + branch-aware handles), flow dot on animated edges, invalid-connection preview, category colour tokens, animated auto-layout, canvas legend, quick-add from handles, auto-layout on assistant import (`layout=1`).
- **Assistant (Tier 1):** slim external-chatbot prompt with hello-world embed, anti-patterns,
  exhaustive Core connector specs, contextual recipes, Dolibarr events, optional Tier 2 annex.
- **`AssistantPreflight`:** blocks prompt generation when Pro Pack connectors are required but
  unavailable; Marketplace CTA in `AssistantView`.
- **Starters:** `00-email-test-manuel.knot.json`, `03-facture-validee-email-bancaire.knot.json`.
- **Workflows list:** delete action with confirmation modal (`bulkWorkflows` delete).
- **Connectors catalog:** JSON Schema `properties` in config fields table, usage examples, i18n search.
- **Editor:** palette gating for unavailable Pro connectors; responsive toolbar badge; `TestSplitButton` k-* tokens.
- **Credentials:** WhatsApp Cloud inline help (Meta tokens, not OpenAI); `labelKey`/`descriptionKey` on credential fields.
- **Docs:** `docs/browser-support.md`, `docs/llm/assistant-prompt-spec.md`, beta-troubleshooting refresh.
- **E2E:** Playwright Firefox + WebKit projects; `viewports.ts` matrix; `no-overflow-matrix`, `visual-baseline`, `zoom-a11y`, `workflow-delete` specs.

### Known issues

- **Workflows list — Export ZIP:** bulk selection (`selectedIds`) is not wired yet; the button stays
  disabled until multi-select is implemented.

### Fixed

- **`ExpressionResolver`:** bracket index paths (`rows[0].iban`) resolve like dot notation (`rows.0.iban`).
- **`action.email`:** literal `\n` in LLM-generated bodies become HTML line breaks.
- **`logic.if` / `IfConditionOperator`:** normalize `>=`, `gte`, and related operators at runtime and on import.
- **`WorkflowDefinitionNormalizer`:** repair Assistant imports (`sql`→`query`, objectType aliases, if operators, email body).
- **Frontend import parity:** `workflowDefinitionRepair.ts` mirrors server normalizer before save/activate.
- **`dolibarr.read_object`:** canonical ObjectFactory slugs only (no `invoice`/`order` aliases); prompt catalog prints inline enum values.
- **`logic.filter`:** translated operator labels via `enumLabelKeys` (6 locales).
- **`NodeInspectorBody`:** `trigger.dolibarr_event` panel loads via `canonicalConnectorId`.
- **Starter 03:** `objectType=facture`, `{{$nodes.*}}` expressions for chained reads.
- **Host layout:** rail gutter via `#id-right` padding (fixes setup/configuration overlap);
  `#id-container` forced to `display:block` (eldy table shell); explicit `knot-tokens.css`
  on setup.php; inline `:root` fallbacks in layout guard;
  data-heavy views (Workflows, Executions, Connectors, Credentials, Book, Audit, Variables,
  Approvals) use full-width `knot-view-shell` instead of centered `max-w-*` caps.
- **Executions list:** persist `duration_ms` when a run finishes; show duration from
  `started_at`/`ended_at` for legacy rows; icon actions (view panel, full page, retry) on every row.
- **Pro Pack settings URL:** `KNOT_BASE_URL` fallback when `DOL_URL_ROOT` missing; `ui.onboarding` in manifest.
- **`preview.php`:** inject `window.DOL_URL_ROOT`.
- **Import:** `WorkflowImportLegacyStepsError` for Zapier `trigger`/`steps`; explicit i18n errors in Assistant.
- **Starter 02:** `query` field + `logic.loop` `itemsPath`/`realIteration`.
- **`workflow-format.md`:** `sourceHandle`/`targetHandle` (not `sourcePort`).
- **knot-migration:** `KTargetVersionSelect` badge wrap, cloning stepper truncate.

### Tests

- `AssistantPreflightTest`, expanded `WorkflowAssistantPromptBuilderTest`, `normalizeWorkflowImport` legacy test,
  `ProPackSettingsView.spec.ts`, `KTargetVersionSelect` badge containment.

## [2.13.11] - 2026-06-07

### Changed

- **Product / UX:** highlight **`action.email`** as a free Knot Core connector (catalog
  callout, palette copy, i18n FR/EN + locales). Clarify **`notification.alert`** (audit-only)
  vs **`action.email`** (SMTP) vs Pro Pack fan-out / Gmail API.
- **Starter example:** `examples/starter/02-relance-facture-impayee.knot.json` — drop
  obsolete `credentialId`; document Core-only SMTP path.

### Documentation

- Align connector counts and mail boundary post-V2.8.1 (`docs/ecosystem.md`,
  `docs/connectors-inventory.md`, beta invitation template, LLM analysis resync).

### Tests

- **`ShowcaseStarter02WorkflowEngineTest`** — guards starter overdue-invoice reminder uses
  free Core `action.email` (validate + dry-run simulate).

## [2.13.10] - 2026-06-01

### Fixed

- **Extension manifest verify:** `ManifestSignatureVerifier` now validates the on-disk
  `knot-extension.json` (signed payload) instead of the `ManifestSchema` normalised
  copy, which added defaults and broke Ed25519 checks (`LICENSE_INVALID` / `TAMPERED`
  on Pro Pack / Migration after Apply). `ExtensionRegistry`, `updates_apply.php`, and
  `DolistoreValidator` pass the manifest file path through the licence inspect chain.

## [2.13.9] - 2026-06-01

### Added

- **`ManifestSignatureVerifier`:** cryptographic Ed25519 verification of extension
  manifests at load time (release signing key); reduces dependency on static pin list.
- **Credential schema normalizer**, **SQL table reference validator**, **Dolibarr table
  catalog**, **workflow assistant prompt builder**, WhatsApp connector docs and example.

### Fixed

- **Host layout:** `#knot-app` offset synced to measured nav width (JS + CSS guard);
  Dolibarr CSS double-`?` URL fix via explicit `<link>` tags in preview/setup.
- **Responsive layout** e2e spec; editor/connectors grid at 880px breakpoint.

## [Unreleased — archived batch notes]

### Fixed — Responsive host layout (preview.php blank canvas)

- **`css/knot-host.css`:** expand Dolibarr `#id-right` / `.fiche` when `.knot-nav` is
  present and give `#knot-app` an explicit `width: calc(100% - var(--knot-nav-width))`
  so the Vue host is not collapsed to **0px** beside the fixed rail.
- **`frontend/src/styles/index.css`:** remove `container-type: inline-size` from `#knot-app`
  (containment on the host amplified the collapse inside Dolibarr's narrow wrapper).
- **`workflows/preview.php` / `admin/setup.php`:** cache-bust `knot-host.css` and
  `knot-tokens.css` via `filemtime` (Cloudflare on demo caches static CSS 7 days).
- **Follow-up:** reserve rail space with `#id-right { padding-left }` instead of
  `#knot-app { margin-left + 100vw }` (sidebar no longer overlaps the editor); compact
  « Quitter vers Dolibarr » footer button with collapse chevron pinned to the right;
  zero top padding on `#id-container` / `#id-right` and `--knot-dolibarr-topbar-h`
  for the gap under Dolibarr's top menu.
- **`workflows/preview.php` / `admin/setup.php`:** load Knot CSS via explicit `<link>`
  tags in `$knotHead` (Dolibarr's `llxHeader` CSS array produced invalid
  `?v=…?lang=…` URLs) plus inline `#knot-host-layout-guard` beside the fixed rail.

### Added — WS14 transverse (beta onboarding & ops)

- **Beta onboarding:** [`docs/beta-testers/onboarding.md`](docs/beta-testers/onboarding.md) — install Core,
  wizard, Pro Pack / Migration licence activation (no Core activation code).
- **Apply recovery runbook:** [`docs/runbooks/apply-update-recovery.md`](docs/runbooks/apply-update-recovery.md) —
  one-shot ZIP restore when in-app Apply fails (`release_signature_invalid`, rollback `failed`, or
  extension licence **TAMPERED** after a bad swap).
- **Examples audit:** all `core/examples/**/*.knot.json` and `pro-pack/examples/*.knot.json` checked —
  no `llx_propale` or other catalogued SQL typos; propal samples use `llx_propal`.
- **i18n parity (WS3/WS4/WS7/WS10/WS11):** `KnotNavExitDolibarr` in six Dolibarr `knot.lang` locales;
  `sql_unknown_table` / `sql_unknown_table_hint` and `dolibarr.sql_query` field hints in six Vue locales.
- **Version coherence:** no semver bump in this batch — display version stays **2.13.8**
  (`modKnot` / `Version::FALLBACK` / `frontend/package.json` unchanged).

### Added — Credentials regression tests (WS7)

- Vitest `CredentialsView.spec.ts` — legacy `fields[]` credential schema renders secret inputs in the modal.

### Added — Assistant & SQL grounding (WS4)

- **Assistant prompt:** `WorkflowAssistantPromptBuilder` grounds LLM prompts with
  curated Dolibarr table names from `ObjectFactory` / introspection
  (`DolibarrTableCatalog`); explicit SQL rules (propal → `llx_propal`, never
  `llx_propale`, `fk_statut`, multi-entity filter). `api/assistant.php` uses
  `ConnectorRegistry::allWithExtensions()` for the connector catalog.
- **SQL lint on save:** `WorkflowValidator` warns on unknown `llx_*` tables in
  `dolibarr.sql_query` nodes (`SqlTableReferenceValidator`) with typo hints;
  mirrored client-side in `validator.ts` for instant editor feedback.
- **Error surfacing:** `DolibarrErrorTranslator` maps SQL failures to
  `KNOT_DOLIBARR_SQL` (`DolibarrSqlError`); `WorkflowEngine` and
  `dolibarr.sql_query` propagate structured Knot payloads instead of generic
  execution errors.
- **Docs / i18n:** beta troubleshooting (entity scope, SQL lint, execution codes);
  `connectors.dolibarr.sql_query` field hints in six Vue locales.
- **Tests:** `WorkflowAssistantPromptBuilderTest`, `DolibarrTableCatalogTest`,
  `SqlTableReferenceValidatorTest`; SQL cases in `DolibarrErrorTranslatorTest`
  and `WorkflowValidatorTest`.

### Changed — Responsive shell (WS3)

- **Editor grid:** replaced fixed Tailwind `260px 1fr 320px` columns with CSS
  variables (`--knot-editor-palette-w`, `--knot-editor-inspector-w`) and
  `@container` stacking at **880px** (`--knot-layout-breakpoint`), aligned with
  `.knot-nav` responsive rules in `knot-host.css`. Global overflow guards on
  `#knot-app` and grid children (`min-width: 0`, `max-width: 100%`,
  `overflow-x: clip`).
- **Connectors catalog:** same breakpoint via `.knot-connectors-layout` +
  `.knot-view-shell` overflow guards.
- **Host nav:** `.knot-nav__exit` link beside the collapse control → Dolibarr
  home (`index.php?mainmenu=home`); i18n `KnotNavExitDolibarr` (six Dolibarr locales).
- **E2E:** `core/tests/e2e/specs/responsive-layout.spec.ts` and
  `test-playwright/tests/responsive-layout.spec.ts` (`npm run test:responsive`).

### Fixed — Pro Pack credentials UI (WS7)

- **`CredentialSchemaNormalizer`** converts legacy Pro Pack `fields[]` credential
  schemas to JSON-schema `properties`/`required` at the API boundary
  (`api/connectors.php`, `api/credentials.php` with extension connectors).
- **`CredentialsView.vue`** frontend fallback for legacy `fields[]` shape.

### Fixed — Extension manifest verification (TAMPERED on Apply)

- **Cryptographic manifest verification.** `DolistoreValidator` now verifies
  `license.manifestSignature` with the pinned **release** Ed25519 public key
  (`ManifestSignatureVerifier`, same canonical JSON as `license/bin/sign_manifest.php`).
  Any editor-signed extension release is accepted without bumping Core pin digests.
- **Transition fallback.** `OfficialManifestSignatures::map()` remains as a digest
  fallback during the rollout window when crypto verify is unavailable.

### Changed — Extension manifest release process

- **No Core bump per extension version.** Re-sign the manifest, publish the extension ZIP,
  and Apply — Core no longer requires a synchronized pin update for each new digest.
  See `docs/runbooks/extension-manifest-release.md`.


### Fixed — Marketplace UX polish

- **Pricing hidden when no price defined.** `ProductDetailLayout.vue` previously rendered a
  fallback pricing card showing **"—"** for any pack without `priceMonthlyCents` /
  `priceYearlyCents`. Knot Pro Pack and Knot Migration pages now render no pricing column at
  all when the editorial JSON omits the `pricing` block, instead of a placeholder. Triggered
  by the editorial `pricing` removal on `license.knot.tools` (Knot Pro Pack / Migration).
- **Tier badge contrast WCAG 2.2 AA.** `MarketplaceSpotlight.vue` was passing
  `tone="info"` to `SignalBadge` regardless of tier (giving `#0ea5e9` on `#f0f9ff` =
  **2.60:1**, failing AA). New `tierTone` mapping (`pro/enterprise → premium`,
  `migration → success`, `core/free → info`, `beta/new → warning`) and three new tokens
  (`--knot-color-{info,success,warning}-strong`) bring every badge to **≥ 4.68:1 in light**
  and **≥ 7.19:1 in dark**. Full ratio table in `docs/design-system.md`.

### Added — Audits

- **Bundle composition tracked** (top 25 buckets, `vite-bundle-visualizer` treemap in
  `dist/bundle-stats.html`): `src/views` 25.8%, `src/i18n` 21.2%, `@vue-flow/core` 11.7%,
  `src/components` 10.3%, `@vue/runtime-core` 6.1%. No lodash/moment/dayjs accidentally
  bundled — total 2.59 MB pre-gzip, 474 kB gzipped.
- **WCAG measurement table** in `docs/design-system.md` for the 5 SignalBadge tones × 2 modes.

### Added — Marketplace UX refoundation (schema v2)

- **Editorial schema v2:** structured home (`spotlight`, `collections`), product/template pages
  (`hero`, `pricing`, `tabs[]`), taxonomy — canonical docs
  **`docs/marketplace-editorial-schema.md`** + JSON Schema; **`version: 2`** enforced by
  **`EditorialValidator`** (Core + license).
- **Block-driven Vue shell:** `MarketplaceShell`, `BlockRenderer`, extended hash routes
  (`#/product/`, `#/template/`, `#/news/`, `#/category/`, `#/collection/`, `#/search`) via
  **`useMarketplaceRoute`**; detail layouts + drawer; **`home_discovery`** for v2 home.
- **Operator runbooks:** **`docs/runbooks/marketplace-monitoring.md`** (editorial-meta probe every
  5 min, stale > 7d, payload > 200 KB, **`license/bin/monitor_editorial.sh`**),
  **`marketplace-incident.md`**, **`marketplace-rollback.md`**.
- **Docs:** Marketplace UX patterns in **`docs/design-system.md`**; shell + routes in
  **`docs/frontend-architecture.md`**; full smoke journey in **`docs/marketplace-manual-qa.md`**.

### Changed — Marketplace (P1a)

- **Home routing v2 priority:** `editorialLayoutForRoute` and `EditorialMerger` prefer
  `spotlight` / `collections` over legacy `home.layout` (fixes stale-cache teaser-only home).
- **Shell wired:** `MarketplaceTopBar`, `MarketplaceSearchBar` (autocomplete + recent queries),
  `MarketplaceBreadcrumb`, `MarketplaceFilterChips` mounted in `MarketplaceShell`.
- **Collections query resolver:** `resolveCollectionCards()` drives `MarketplaceCuratedRow` from
  editorial `query.sort` / `category` / `limit` (license v2 JSON).
- **Asset cache bust:** `preview.php` appends `?v={Knot version}` on `knot-app.js` bundles.

- **Public UI links:** `frontend/src/lib/marketplaceLinks.ts` centralises knot.tools URLs
  (product pages `/pro-pack/`, `/migration/`, beta `/beta/?products=…`, Core download
  `/downloads/knot-core/latest`). Editorial and catalog CTAs pass through
  `sanitizeMarketplaceHref()` — no clickable Marketplace link targets `license.knot.tools`.
- **`editorial-fallback.json`** and marketplace i18n strings updated to match knot.tools
  product pages and beta programme wording.
- **`CatalogCache`:** catalog snapshot TTL uses **±10% symmetric jitter** around the 6h base
  TTL (still persisted per row as `ttlSeconds`) to spread refetch waves.

### Added — Marketplace (P1a)

- Block-driven storefront: **`storefront_tabs`** block (Packs / Templates / Bundled tabs)
  replaces removed **`legacy_catalog`**; enriched **`editorial-fallback.json`** home layout
  (ecosystem cards, compare plans, FAQ) and product pages (Pro Pack, Migration).
- **`MarketplacePromoArt.vue`** — bundled SVG illustration when CDN images are unavailable;
  editorial fallback no longer references unresolved `cdn.knot.tools` assets.
- **`HighlightBlock.vue`** registered in block registry; Hero, Banner, and Ecosystem blocks
  honour editorial props (CTA, items[], promo strip).
- Editorial pipeline for Knot Marketplace: bundled `data/marketplace/editorial-fallback.json`,
  (`marketplace.catalog_cache.{lang}`) with jittered TTL, `EditorialMerger` / `EditorialValidator`,
  `SidebarBadge` helper, unified `api/marketplace.php` response fields `editorial` and `sidebarBadge`.
- **`EditorialMerger::remoteBlockedByKillSwitch`** + guard in **`api/marketplace.php`** so cached
  overlays carrying **`meta.killSwitch: true`** never replace bundled fallback client-side (defense
  in depth with license `EditorialLoader`).
- **`POST /api/marketplace_track.php`** — whitelist-only Marketplace telemetry (`cta_click`,
  `template_instantiated`, `product_page_visit`, `news_visit`, `banner_dismissed`) persisted to
  `llx_knot_audit_log` as `marketplace.track`, gated by **`ApiAuth`** + CSRF +
  **`RateLimiter` 60 req/min/user**.
- **`workflows/preview.php`** emits a tightened **Content-Security-Policy** meta tag when
  **`mode=marketplace`**.
- **`frontend/scripts/i18n-check.mjs`** + **`npm run i18n-check`** — `marketplace.*` key parity vs
  **`en_US.json`** for `fr_FR`, `es_ES`, `de_DE`, `it_IT`, `pt_PT`.
- **`KNOT_MARKETPLACE_PREVIEW_TOKEN`**: **`CatalogClientFactory`** forwards Dolibarr global into
  **`CatalogClient`** so fetches hit **`/api/catalog-preview.json?token=…`** instead of **`catalog.json`**.
- **`MarketplaceUnavailable`** emits **`retry`** + external pricing link; **`MarketplaceEmptyState`**
  for storefront/tab empty views; **`ComparePlansBlock`** accordion layout below **`md`**.
- **`EditorialMerger`**: **`templatePages`** / **`newsPages`** slug merge (same rules as **`productPages`**).
- **`EditorialValidator`**: **`LUCIDE_ICON_WHITELIST`** gates optional **`icon`** on editorial blocks.
- **`docs/marketplace-manual-qa.md`**; **`docs/marketplace-release-checklist.md`** (automated gate +
  operator sign-off); schema doc (**`useMarketplaceRoute` ↔ `BlockRenderer`**,
  **versioning procedure**, **`icon`** notes); **`docs/design-system.md`** Marketplace emoji convention.
- **`docs/marketplace-editorial-schema.md`** + **`docs/marketplace-editorial-schema.json`**
  (Draft-07 narrative schema), **`docs/admin-guide/marketplace.md`** operator checklist, &
  **`docs/extensibility.md`** Marketplace block-driven subsection.
- **Design tokens**: `--k-shadow-knot-premium`, `--k-radius-knot-lg`, typography helpers
  **`.k-display-1`** / **`.k-display-2`**, **`frontend/src/styles/knot-tokens.css`** re-export.

### Changed — Marketplace (P2)

- Editorial root allow-list: stray top-level keys now fail **`root_unknown_key`**; schema **`version`**
  must stay **`≤ 2`** (`3+` rejected until a coordinated bump).
- **`EditorialMerger`**: overlays that introduce blocks with unknown **`type`** are ignored; patches that
  would rewrite an existing **`id`** to an unsupported **`type`** fall back to the bundled fragment for
  that slot (no partial merge poisoning).
- Rich-text validation covers **`rich_text` / `richText`** bodies plus nested **`html`**, **`content`**,
  **`excerpt`**, **`props`** string fields alongside legacy **`body`**.

### Added — Marketplace (P4 prefetch + telemetry UX)

- **`useMarketplacePrefetch`** composable wired from **`MarketplaceView`** plus hover targets on
  **`EcosystemBlock`** and **`ProductCardBlock`** to warm heavyweight route bundles before navigation.
- Admin-only **`api/marketplace_stats.php`** + **`MarketplaceStatsReader`** summarise Marketplace CTA /
  storefront interactions (30 day aggregate) surfaced in **`admin/setup.php`** with new
  **`KnotMarketplaceCtaAnalytics*`** strings across locales.
- Bundled **`editorial-fallback.json`** carries **`rich_text`** bodies for multilingual news stubs
  (`pro-pack-updates`, `core-security-practice`) in addition to the refresh article.

### Added — Marketplace operator UX

- **`CatalogClient::catalogFetchUrlForDiagnostics()`:** returns the effective GET URL used for
  catalog fetches (public JSON vs **`/api/catalog-preview.json`** when
  **`KNOT_MARKETPLACE_PREVIEW_TOKEN`** is set).
- **i18n — Marketplace error chrome:** `marketplace.unavailableRetry` and
  `marketplace.unavailableExternal` (six Core locales) for the unavailable / retry actions.

### Added — Marketplace (P4 closure)

- **`docs/marketplace-release-checklist.md`** — automated CI/local gate + operator sign-off before
  Marketplace-heavy tags; manual matrix cross-linked from **`docs/marketplace-manual-qa.md`**.
- **Playwright (`test-playwright/tests/marketplace.spec.ts`):** hash scroll-restore smoke and CSP
  console silence on home → product journey.
- **Mobile polish:** marketplace host bottom padding (sticky CTA safe area) and horizontal gallery
  scroll-snap under **`640px`** in **`frontend/src/styles/index.css`**.

### Deferred — Phase 5 (out of P1–P4 scope)

- Auto-suggest template search heuristics and Twig admin form editor for editorial JSON on
  `license.knot.tools` — tracked separately; edit JSON in git + **`validate_editorial.php`** for now.


### Added — i18n

- **Connector catalog i18n:** `labelKey` / `descriptionKey` on palette nodes; ProblemsPanel and EditorView use vue-i18n keys across six locales.
- **i18n tooling:** `scripts/i18n/apply-connector-catalog-i18n.mjs`, `gen-missing-connector-i18n.mjs`.
- **Demo deploy:** `scripts/demo_knot_vm_deploy_migration.sh` for Migration bundle on demo VM.

### Changed — i18n

- **FR Core UI:** residual English strings translated in `fr_FR.json` (connectors, editor, nav).
- **DE/ES/IT/PT:** connector catalog keys aligned with `en_US.json` pivot.

## [2.13.6] - 2026-05-25

### Fixes — licensing

- **Official manifest transition pins:** restore `0.21.4` / `0.1.4` digests as deprecated pins so
  sites on those builds are not marked `tampered` after Core `2.13.5` (fixes blocked extension Apply
  with HTTP `422` / `license_invalid`).
- **`sync_extension_manifest_signature.py`:** auto-deprecate the former primary pin when
  `--deprecate-previous` is omitted (prevents wrong transition hex on manifest bumps).

### Tooling

- **`scripts/demo_knot_vm_common.sh`:** prefer SSH key over `sshpass` when
  `~/.ssh/demo_knot_tools` is present (wrong password in `ssh_operator.env` no longer blocks deploy).

### Documentation

- **`docs/runbooks/extension-manifest-release.md`:** transition pin semantics, sequence diagram,
  and 2026-05-25 demo incident post-mortem.

## [2.13.5] - 2026-05-24

### Added

- **Updates Apply — extension DB migrations:** optional manifest `postApply` block
  (`contractVersion`, `autoload`, `migrationRunner` FQCN). Core loads the extension runner after
  a successful file swap via `ExtensionPostApplyRunner`; response includes a `migrations` log array.
- **Updates Apply — audit log:** `updates.apply.started`, `.swapped`, `.migrated`, `.failed`, and
  `.rolled_back` entries in `llx_knot_audit_log`.

### Changed

- **Updates Apply — automatic file rollback:** Core and extensions roll back the on-disk tree when
  post-swap SQL migrations fail (`Installer::rollback()` after swap commit window).
- **Updates Apply — HTTP semantics:** `422` when migration fails but rollback restored files;
  `500` when rollback itself fails (`details.rollback`: `restored` | `failed`).
- **Migrator (Core):** fail-fast on first SQL error instead of recording `error:` status and
  continuing.
- **Updates Apply:** `set_time_limit(600)` at request start; `ExtensionRegistry::clearCache()` after
  each successful swap.
- **Official manifest pins:** Migration and Pro Pack primary digests updated for `postApply` manifests.

### Documentation

- **`docs/runbooks/updates-apply.md`**, **`docs/extensibility.md`**, **`docs/testing/updates-e2e-extensions.md`**
  — post-apply migration contract and operator checklist.
- **Updates UI:** migration success toasts and distinct `422` / `500` error copy (FR + EN).
- **GitHub community profile:** public `CONTRIBUTING.md`, issue templates, updated `SECURITY.md` integrity links (`/downloads/verify/`), Ed25519 policy for releases ≥ 2.13.4; `CHANGELOG` header reflects public beta on GitHub.
- **`SECURITY.md`:** fix French spelling (`chiffrés`).

### Fixes — updates

- **`ExtensionRegistry`:** ignore installer stash folders matching `*.backup.*` so post-apply scans do not target stale trees (demo E2E apply regression).
- **`DolistoreClient`:** rename cURL response variable in `postWithCurl()` to avoid shadowing the POST body parameter.
- **`UpdatesView`:** force-refresh notify cache after a successful apply (`load(true)`).

### Public beta (in progress)

- **UI markers:** `KNOT_RELEASE_CHANNEL` (default `beta`) exposes `releaseChannel` in `api/health.php`; global `KBetaBadge.vue` footer + `KNOT_DEMO_MODE` gated `KDemoBanner.vue` (demo instance only).
- **Update channel:** `GithubReleasesClient` primary manifest → `knot-tools/knot-core`; `releases.json` ZIP URL → `knot.tools/downloads/knot-core/latest`.
- **Release tooling:** `scripts/release/publish-to-public.sh`, `scripts/release/build-core-release.sh`, `docs/RELEASE.md`, `CODE_OF_CONDUCT.md`, `NOTICE`.
- **Legal audit:** `docs/legal/pre-public-leak-audit.md`, `docs/legal/public-release-readiness.md` (founder sign-off before public push).
- **Pro Pack framing:** positive `proPackRecommendation.*` i18n; beta tester docs updated.

### Documentation

- User-facing documentation centre published at **docs.knot.tools** (Knot Tools™), complementing in-repo `core/docs/` technical guides.
- **Runbook:** [`docs/runbooks/docs-docker-migration-screenshots.md`](docs/runbooks/docs-docker-migration-screenshots.md) — Playwright Migration captures on Docker (licensing profile, `llx_knot_config`, fingerprint pin, troubleshooting).

### Tooling / community

- **Public GitHub (`knot-core`):** synthetic Stripe test vector in `RuntimeLoggerTest` (avoids Secret Scanning false positives); `CODE_OF_CONDUCT.md` and PR template in public snapshot; CONTRIBUTING clarifies maintainer-only PRs during beta.
- **Audit pre-push:** `scripts/release/audit-pre-push.sh` — mandatory gitleaks + rules 02/03 greps before every commit/push; **knot-core** target auto-runs extended snapshot audit (see `.cursor/rules/05-validation-before-push.mdc`).
- **Docs Docker licensing:** `scripts/lib/DocsLicensingProfile.php` pins `Acme Solar`, `licensing.local_salt`, and optional `licensing.docs_pinned_fingerprint` when `KNOT_DOCS_SEED_V1` is set; `seed_docs_migration.php` restores prior licence bindings and writes `value_hex` signatures; `repair_docs_migration_license_cache.php` refreshes empty cache signatures; `seed_docs_migration_ui.php` seeds analysis history and Discovery journey for Migration Playwright captures.

## [2.13.4] - 2026-05-23

### Security — updates

- **Release signatures:** `ReleaseVerifier::CORE_SIGNATURE_MANDATORY_FROM` gates mandatory Ed25519 on Core ZIP apply from **2.13.4**; commercial extensions always require **`signature_hex`**. Applies map **`422`** **`release_signature_invalid`** with rollback-safe messaging.
- **`DolistoreClient` / Updates:** JWT download-token and product signature metadata align with **`KNOT_RELEASE_CHANNEL`**.

### Documentation

- **Runbooks:** `docs/runbooks/updates-apply.md` — signature policy, **`KNOT_RELEASE_CHANNEL`** matrix, Core vs extension rules.
- **Troubleshooting:** `docs/troubleshooting/extension-signature-invalid.md`.
- **E2E checklist template:** `docs/testing/updates-e2e-extensions.md`.
- **`docs/ecosystem-handoff.md` / `docs/legal/public-release-readiness.md`** — Core+extension signing posture; **`pro-pack-mirror`** abandoned for updates.

## [2.13.3] - 2026-05-23

### Terminology (public beta)

From **2.13.x**, user-facing copy prefers **« Knot Pro Pack adds … »** over legacy
**« connectors moved / migration »** wording. Historical CHANGELOG entries for V2.5.0b
remain accurate for upgrades; new installs use the free GPL ZIP and optional extensions.

### Fixed

- **Editor:** canvas height chain (`App.vue`, `EditorView.vue`, responsive CSS) so Vue Flow fills the shell.
- **Extensions i18n:** Core passes `en_US` / `fr_FR`; Migration and Pro Pack bundles normalise via `normalizeCoreLocale()`.
- **Sidebar:** Marketplace after Dashboard with premium chrome; extension items in marketplace section; removed native `pro-pack-migration` inject.
- **Pro Pack hub:** `?mode=pro-pack` with Connectors + Settings tabs; legacy `pro-pack-migration` and Marketplace migration tab redirect to the hub.
- **Licensing:** `license_activate.php` writes `licensing.license.activated` audit entries; prominent cache-write failure alert in activation modal; PHPUnit cache persistence test for `knot-migration`.

### Changed

- **Marketplace:** removed Migration tab (assistant lives in Pro Pack hub).
- **Manifests:** Pro Pack `mode=pro-pack`, `category=premium`; Migration menu in marketplace section (re-sign required); `ManifestSchema` accepts `ui.menu.section=marketplace`.
- **Ops:** atomic deploy rule documented when `OfficialManifestSignatures` or extension manifests change.

## [2.13.2] - 2026-05-21

### Fixed

- **Updates notify (private GitHub):** Core resolver falls back to
  `license.knot.tools/api/core/releases.json` when GitHub `releases.json`
  returns 404 (`UpdateLatestResolver`, source `live_license_releases`).

### Changed

- **UI branding:** dashboard pill, app title, updates table, floating banner,
  left nav lockup (`knot-leftnav.tpl.php`), setup wizard hero, editor palette
  header, and Dolibarr module label use **Knot Core** (FR/EN + `knot.lang`).

## [2.13.1] - 2026-05-21

### Legal / docs

- Align product copy with founder decisions D1–D3 (Core free, PolyForm extensions,
  `license.knot.tools` only): i18n, `dolistore-licensing.md`, ADR-004 superseded,
  `CLAUDE.md` / `AGENTS.md` / `ecosystem.md`, beta-testers.
- Counsel draft hub: `docs/legal/dpa-vendors.md`, `registre-traitements-art30.md`,
  `cgv-knot-migration-brouillon.md`, `juridiction-note.md`.
- Refresh `docs/audits/ecosystem-collection.md` Partie J for external legal analysis.

### UI

- **Updates floating banner** (global): S2 snooze — Later (7d) + Ignore version;
  shared `useUpdatesPoll`; `KNOT_ENTITY` in preview.php.

## [Unreleased]

### Added

- **Demo self-service (docs/ops):** GDPR Art.&nbsp;30 register entry T-008; runbooks
  `demo-dolibarr/integration-tests-setup.md`, `self-service-operations.md`,
  `pre-launch-checklist.md` (29 blocking gates); PHPUnit `PurgeExpiredDemoUsersTest`
  (CLI contract + `create_demo_user.php` non-admin guard); Playwright pack
  `test-playwright/demo-self-service/` (plan §5.5).

### Fixed

- **Licensing:** re-pin Pro Pack `manifestSignature` (Ed25519 over real
  `knot-extension.json` payload; removes false Migration digest copy).

### Docs

- **Testing:** refresh `docs/testing/engine-coverage-beta-report.md` (2026-05-23
  Clover segments: Engine+Cron 93.7 %, Security 92.6 %, Connectors 84.2 %).

### Added

- **Release audit:** `scripts/audit_release_zip.py`, manual checklist
  `docs/release-audit-checklist.md`, optional `pre-commit-release-zip.sh`,
  CI workflow `release-audit.yml` (Pro Pack / Migration / generic commercial ZIPs;
  complements `audit_dolistore_zip.py` for Dolistore Core packages).
- **Legal / licensing:** root `LICENSE` (GPL-3.0-or-later), `LINKING-EXCEPTION.md`
  (draft counsel), ADR-021, `license-boundary.md`, `license-portal.md`,
  `compliance-status-2026-05.md`, trademark audit annex.
- **Manifest:** accept `validation: license` alias (normalised to `dolistore`).
- **Engine:** `EngineConnectorResolver` merges Core + extension connectors for
  cron worker and sub-workflow execution, with Core-only fallback when the
  extension registry fails.
- **UI:** updates badge on dashboard (`UpdatesBadge` → `/api/updates.php`; badge links `?mode=updates`).
- **UI:** Updates screen (`UpdatesView`, `?mode=updates`) lists `/api/updates.php` entries and POSTs `updates_apply.php`; manual **Check for updates now** bypasses the 24h notify cache (`?force=1`); setup wizard links to forced check.
- **UI:** license deactivation modal on Connectors and Marketplace pack cards (FR/EN i18n).
- **UI:** workflow folders sidebar on Workflows list (`folderId` in list API).
- **UI:** `KProBadge` on Connectors catalog and Marketplace pack cards.
- **Nav:** Pro Pack migration menu only when Pro Pack extension is loaded (ADR-20); sidebar collapse toggle + dense nav tokens.
- **UI:** shared `normalizeWorkflowImport` for assistant/file JSON import; API rejects empty create payloads (`workflow_empty_payload`).
- **UI:** workflows/executions/audit/credentials tables scroll horizontally; editor back link + responsive toolbar.
- **UI:** clearer editor 404 when workflow missing (entity hint).
- **UI:** license failure CTA in `ExecutionErrorPanel` opens global activation modal.
- **UI:** `KnotExpressionInput` wired in inspector `DynamicForm` expression mode.
- **UI:** `MigrationBanner` global scan on Workflows list (Pro Pack connector migration).
- **API:** `license_download_token.php` proxies `POST /api/license/download-token` on `license.knot.tools`.
- **API:** `updates_apply.php` — admin + CSRF apply path for Core (public `releases.json` / optional manual URL) and commercial extension slugs (`knot-pro-pack`, `knot-migration`).
- **Updates:** Core notify resolver — GitHub `releases.json` primary, then
  `license.knot.tools/api/core/releases.json`, then `/api/products/knot/latest`
  (`UpdateLatestResolver`, sources `live_license_releases` / `live_license`);
  apply Core unchanged (GitHub only).
- **Docs:** `docs/runbooks/updates-apply.md` (apply flow + rollback hints).
- **Tests:** Playwright `tests/e2e/specs/updates-smoke.spec.ts`; `UpdateStatusCacheMultiEntityTest`; `OfficialExtensionManifestSignatureTest`.
- **Legal v2.1 (2026-05-22):** expanded `LINKING-EXCEPTION.md`; internal GDPR procedures; VM re-sign Pro Pack + Migration manifests; compliance status refresh.
- **Manifest release ops:** runbook `docs/runbooks/extension-manifest-release.md`,
  sync script `scripts/sync_extension_manifest_signature.py`, 90d transition pins
  in `OfficialManifestSignatures`, `tampered` hints in Connectors + Setup.
- **CI:** `golden-introspection.yml` workflow (manual dispatch, Dolibarr 21.0.4 checkout, non-blocking).

### Changed

- **`ForkDetector`:** multiple official digests per extension (primary + deprecated);
  clearer `tampered` messages in `DolistoreValidator`.

### Fixed

- **Editor:** restore fixed 3-column layout (palette / canvas / inspector). The
  responsive `xl:` single-column grid stacked panels inside Dolibarr's content
  area and hid the Vue Flow canvas; stacking is limited to viewports under 900px.
- **i18n:** sync `connectorsPage.detail.manifestOutdated` keys across de_DE, es_ES, it_IT, pt_PT.
- **Tooling:** remove no-op `curl_close()` from `ZipDownloader` and `GithubReleasesClient` (PHP 8.5 deprecation).
- **Release audit:** `audit_release_zip.py` infers `knot-core` from Dolistore ZIP
  names, accepts root `LICENSE`, exempts Composer `installed.*` in shipped
  vendor, and skips licensing-policy docs for the Pro Pack open-source guard.
- **Packaging:** exclude `phpstan.neon.dist` from Dolistore Core ZIP.
- **Licensing:** PHPStan level 6 — `LicenseCache` documents `activationCodeEnc`,
  `DolistoreClient` expiry extraction, `DolistoreValidator` audit domain context,
  `CronWorker` workflow array typing.
- **Licensing:** `DolistoreClient` calls `POST /api/license/check` with
  `activation_code` and `instance_fingerprint`; parses production `verdict`
  envelope from `license.knot.tools`.
- **Licensing:** `license_activate.php` stores encrypted activation code in
  `LicenseCache` for silent refresh (`ActivationCodeProtector`).

## [2.12.1] - 2026-05-20

### Fixed

- **Engine:** post-claim guard for `single_instance` workflows closes a TOCTOU
  where two queued executions could both reach `running`; losers return to
  `queued`. `runOnce` uses atomic `tryClaimExecution` like the cron worker.

### Added

- **Docs:** execution paths (sync simulate 25 s vs async queue / HealthWorker)
  in `docs/testing/known-limits.md` and expanded `docs/operations.md`.

### Changed

- **Frontend:** editor warning when a workflow uses HTTP, AI, or SaaS nodes
  (queued Run vs time-capped Simulate).

### Tooling / CI

- **PHPUnit (K2):** drop deprecated `ReflectionMethod::setAccessible()` calls in
  `ObjectActionExtendedTest`; remove no-op `curl_close()` from HTTP clients and
  license/gallery API endpoints; `ObjectAction::assignFields()` skips dynamic
  schema keys when aliases exist (PHP 8.5 deprecations); avoid `new Class()->method()`
  chaining in tests (PHP 8.4+ parse — use `(new Class())->method()` for 8.1 CI).
- **CI (K2):** `php-tests-85-dev` job pins `php-version: '8.5'` (experimental,
  `continue-on-error`); ci-runbook §3.1 documents invalid `8.5snapshot` tag.
- **PHPStan:** clear level-6 findings in `class/Engine/` (typed context arrays,
  dead match arm, redundant guards); align test `DoliDB` stubs with
  `affected_rows(mixed $resultset = null)` signature from `tests/stubs/dolibarr.php`.
- **PHPStan:** clear `class/Api/`, `class/Security/`, `class/Errors/`, and
  `class/Capabilities/`; add `Conf` Dolibarr stub for static analysis.
- **PHPStan:** clear remaining level-6 findings across `class/`; add Dolibarr
  class stubs under `tests/stubs/` for static analysis; PHPStan CI job is now
  blocking (removed `|| true` waiver).
- **PHPStan:** stub `http_get_last_response_headers()` for PHP ≤8.3 runners;
  simplify `StateExtractor::resolveStatusProperty()` (property_exists only).
- **Mission Docker smoke:** extend Dolibarr HTTP wait to ~6 min with in-container
  curl fallback on slow GitHub runners.
- **Docker dev stack:** set `DOLI_CRON: 0` on the web container — `DOLI_CRON: 1`
  runs cron-only mode in the official image and never starts Apache (broke
  mission-docker-smoke and local `docker compose up`).
- **OpenAPI:** add `.spectral.yaml`, fix `docs/openapi.yaml` response codes and
  security blocks so Spectral lint passes (workflow `openapi.yml`).
- **Frontend i18n:** add `lint:i18n:compile` (ICU compile check) to CI and Vitest.

### Fixed

- **E2E mission-internal:** read CSRF from `window.KNOT_CSRF_TOKEN` on preview.php
  (setup.php redirects to dashboard on fresh Docker when first-run is incomplete).
- **Realistic seed:** set `code_client` / `code_fournisseur` to `SEED-REAL-*` codes so
  mission-internal SQL picks customers; mission Docker smoke re-seeds with `REPLACE=1`.
- **Frontend i18n:** escape literal `{` / `}` in locale strings that document Knot
  expression syntax (`{{$json…}}`, `{{$vars.ref}}`, etc.) — unescaped braces crashed
  vue-i18n at runtime (`SyntaxError: Empty placeholder`) in the editor, variables page,
  and logic node inspector.
- **Migrator:** sort migration version directories with `version_compare` (same
  bug class as Knot Migration — prevents `v2.10.0` running before `v2.9.0`).

### Tooling / CI

- **Dolibarr matrix:** add Dolibarr **23.0** integration job on push to `main`
  (parity with Migration and `test-playwright` compatibility matrix).

### Note — beta validation batch (2026-05-19)

Commit `feat(i18n): ship level-C FR/EN cut for v2.12.0 private beta` also
shipped risk-grammar UI gaps, Playwright a11y/perf specs, PHPCS zero on
`class/`, PHPStan bootstrap, CI PHP 8.4 fix, and Vitest harness fixes — not
only i18n. Follow-up commits use scoped Conventional Commit subjects.

## [2.12.0] - 2026-05-19

### Added

- **Activation guard:** `WorkflowRiskAnalyzer`, `WorkflowActivationGuard`, API field `critical_activation_acknowledged`, column `activation_warning_dismissed`, audit `workflow.activate.critical`.
- **Editor:** `WorkflowActivationDialog` on save → active; `KnotNodeRiskBadge` on canvas; `useWorkflowRiskSummary`.
- **Notifications:** `useToast`, `KConfirmDialog`, `useConfirm` — replaced `alert()` / `confirm()` in core views.
- **Admin:** `admin/setup.php` action `run_migrations` (Migrator only).
- **Testing:** `CronWorker::runWithContext()` / `runOnceWithContext()` seams; `tests/Engine/CronWorkerTest.php` (scripted `DoliDB`, 17 scenarios). Engine+Cron PHPUnit line coverage **≥ 80 %** (725 tests).
- **API error envelope:** `JsonResponse` includes `error_code` (same value as `code`) on standard, fatal, and `knotError` responses.
- **User-visible API i18n:** Vue keys `errors.api.*`, `errors.validation.*`, `errors.import.*` in six locales; `api.ts` (`knotApiErrorMessage`, `formatWorkflowImportWarningLine`); `workflowImport.*` for bulk-import confirm dialog.
- **Validation/import codes:** `WorkflowValidator` and `WorkflowImportAnalyzer` emit `messageKey` + `messageParams` for UI translation; Problems panel uses `formatValidationIssueMessage`.
- **Docs:** `docs/user-guide/en|fr/README.md` expanded; `docs/beta-testers/en/` (11 EN pages); language selector on `docs/beta-testers/README.md`.
- **Risk grammar V2.12 (UI):** workflow list `riskWorstLevel` API + pastille ; MiniMap couleurs risque ; `TestSplitButton` pour workflows `critical` ; specs Playwright `risk-grammar-ui`, `a11y-critical-screens`, `perf-smoke` ; `@axe-core/playwright` in E2E package.
- **Dev tooling:** `phpstan/phpstan` ^2.1 in `require-dev` (618 L6 findings documented ; CI still non-blocking).
- **CI runbook:** Migration `KNOT_CORE_CI_PAT` secret documented ; PHP 8.5-dev job uses `8.4`.
- **Admin i18n:** `admin/setup.php` uses Knot language keys (`KnotSetup*`, etc.) matching `langs/en_US|fr_FR/knot.lang`; PHPUnit `tests/Lang/SetupPhpKnotLangParityTest` guards key parity.
- **Connectors i18n (Core):** All `class/Connectors/**/*.php` connectors expose `labelKey` / `titleKey` / `descriptionKey` / `enumLabelKeys` where applicable; `connectors.*` keys in six locale JSON files; PHPUnit `ConnectorCoreI18nMetadataTest` guards metadata and schema (no legacy string `title` / `description` / `enumLabels` in field definitions).
- **Extensions sidebar:** `ui.menu.placement` (`start` | `end`) — insert premium entries before the first native item of a section (Knot Migration above Dashboard).
- **Risk parity:** shared fixture `tests/fixtures/risk-parity-workflows.json`, `WorkflowRiskParityTest`, Vitest mirror in `workflowRiskParity.test.ts`.
- **Tests:** `ExtensionRegistryMigrationCompatibilityTest` (Knot Migration floor Core >= 2.12.0).

### Changed

- **Frontend i18n (views & components):** `ConnectorsView` (catalog detail panel), `CredentialsView` (table, modal, OAuth, toasts), and `DynamicForm` (expression toggle, HTML hint) fully keyed under `connectorsPage.detail.*`, `credentialsPage.*`, and `dynamicForm.*` (1384 keys, six locales). `DynamicForm` Vitest mounts with `vue-i18n`.
- **Frontend i18n (views & components):** `BookView`, `WebhookPanel`, `SimulationSidePanel`, `ExecutionWaterfall`, `DolibarrPicker`, `DolibarrEventPanel`, `TestSplitButton` (no FR fallbacks in `t()`), `DarkModeToggle`, `EditorView` (starter workflow node labels via `editor.starter*`); new/updated keys across six locales.
- **Frontend i18n (views):** `WorkflowsView`, `VariablesView`, `ExecutionsView`, `ExecutionDetailView`, `AuditView`, and `AssistantView` use `useI18n` / `t()` for user-visible copy.
- **Docs:** regenerated `docs/audit/i18n-coverage-report.md`; `docs/i18n.md` (FR+EN official, connector `connectors.*` key convention), `docs/ux/risk-grammar.md` (partial V2.12 status + precedence §1.1), beta known-limits and private-beta release notes.
- **Tests:** `SetupPhpKnotLangParityTest` lives under `tests/Lang/` (was `tests/Admin/`).
- **`api/compatibility.php`:** Live snapshot and compatibility catch-all errors return generic messages; details are logged server-side only.
- **i18n:** Onboarding wizard copy is fully keyed (`onboarding.*`, `marketplace.*` for starter cards); editor mapping panel, versions list, and schedule modal use `editor.mappingUpstreamEmpty`, `editor.versionsLoading`, and `editor.scheduleActive`.

### Fixed

- **Execution status vs node errors:** `CronWorker` and sync `execute.php`
  mark an execution as `error` when any node logged `status = error`.
- **EditorView:** restored `showStarterBanner` / `selectedMeta` computed (build break).
- **i18n:** realigned all locales to `en_US` key tree ; Vitest `NodeInspectorBody` harness with vue-i18n.
- **CI:** PHP 8.5-dev job uses `8.4` instead of invalid `8.5snapshot`.
- **CI:** PHPUnit exit 1 from PHP warning when `$conf` is null in `CronWorker::rotateRuntimeLogIfNeeded()`; ESLint unused directive in `CommandPalette.vue`.
- **UI overlays:** toasts, confirm/activation dialogs, command palette, and dark-mode toggle render via `Teleport` to `document.body` with z-index above Dolibarr chrome; toasts offset with `--knot-dolibarr-chrome-top` (`preview.php`).
- **Inspector tabs:** node inspector tabs use a 3-column grid (two rows) instead of a single overflowing row; field labels wrap with the expression toggle.

### Docs

- `docs/release/v2.12-private-beta.md`, `docs/frontend/notifications.md`, beta-testers known-limits 2.12.

## [2.11.0] - 2026-05-19

### Changed

- Minor release bump for beta G-P2 E2E and P0 doc closure.

### Added

- **E2E bêta (G-P2):** Playwright `beta-dolibarr-ui-smoke` (UI Dolibarr tiers), `inspector-tabs-beta` (onglets inspector), `import-negative-ui` (JSON invalide sur liste workflows).

### Docs

- **FAQ / known-limits / data-privacy:** MISSION vs REAL-KNOT, rétention (`KNOT_RETENTION_*` 30j / 90j), couverture tests G-P2 partielle dans `coverage-gaps-pre-beta.md`.

### Tooling / CI

- **Dolistore ZIP audit:** `scripts/audit_dolistore_zip.py` runs **gitleaks** (`--no-git`) plus structural checks; `package_dolistore.py` invokes it automatically after each build (`--skip-audit` / `--skip-gitleaks` for local only). Documented in `docs/admin-guide.md` §9 and `.cursor/rules/knot-dolistore-package-audit.mdc`.
- **P0 audit bêta:** G1–G5 closed (local evidence + demo matrix) — `docs/testing/p0-audit-plan-closure.md`; plan todos overstated items reconciled.

## [2.10.0] - 2026-05-19

### Fixed

- Editor toolbar **Simulate** button: `aria-label` and label text use i18n (`editor.simulate`) so Playwright and assistive tech match EN/FR (replacing a French-only `title`).

### Added

- **Beta tester support:** troubleshooting SQL **propale** / `dolibarr.object`, FAQ version **2.10.x** recommandée, doc dette PHPStan/PHPCS ([`docs/testing/phpstan-phpcs-beta-debt.md`](docs/testing/phpstan-phpcs-beta-debt.md)), tests `ObjectActionPropalLineMappingTest` + import analyzer propal.

- **Beta readiness audit (recommended GO checklist).** Tester docs aligned
  (`known-limits`, FAQ, `beta-go-recommended-status`), engine coverage report
  (`docs/testing/engine-coverage-beta-report.md`), CSRF exceptions doc,
  blocking `lint:i18n` in CI, Spectral OpenAPI workflow
  (`.github/workflows/openapi.yml`). Tests: `WorkflowImportSecurityTest`
  (G-P2-07), `ProPackManifestParityTest`, REST E2E
  `beta-commercial-propal-chain.spec.ts`, Simulate dry-run banner in
  `SimulationSidePanel.vue`.

- **Node inspector — `Comment` tab.** A fifth tab joins
  `Form / Advanced / Reliability / Test` in the node inspector
  (`frontend/src/components/inspector/NodeInspectorBody.vue`).
  Operators can attach a free-form note to each node, persisted at
  the node level in the workflow JSON (`notes` field, already part
  of `docs/workflow-format.md`). Save/load and undo/redo are wired
  through `EditorView.setSelectedNotes()` and round-trip through
  `buildDefinition()` / `applyWorkflow()` so the comment survives
  workflow export/import. Storage is sibling of `config` to keep
  notes untyped against connector schemas. Use case from a beta
  tester: documenting why a node exists, who relies on it, and
  links to tickets — without leaking the note into the runtime
  config payload. Test:
  `NodeInspectorBody.test.ts › renders the comment tab with the
  current note and emits update:notes on input`.

- **Shared Vue runtime contract for ADR-20 extensions (Phase 6g §L2)** :
  `frontend/src/main.ts` publie désormais `window.__KNOT_VUE__` et les
  globales plates `KnotSharedVue`, `KnotSharedPinia`, `KnotSharedVueI18n`,
  `KnotSharedVueRouter` au tout début du boot (avant
  `installKnotCore()`). Les extensions UI peuvent maintenant marquer
  `vue`, `pinia`, `vue-i18n` et `vue-router` comme `external` dans leur
  Vite config + `output.globals` (voir `knot-migration/frontend/vite.config.ts`
  pour l'exemple de référence) pour éviter de doubler le runtime Vue
  dans leur bundle IIFE. Résout pour de bon le bug `Cannot read
  properties of null (reading 'ce')` qui apparaissait dès qu'une
  extension montait un composant Core (`KHero`, `KGlassCard`…) dans
  son propre Vue tree : les deux instances partagent désormais le
  même renderer. Les extensions encore bundlées contre Core 2.9.x
  continuent de fonctionner (les globales sont additives) mais ne
  bénéficient pas du gain de taille (~140 KB gzip économisés). La
  pin `requires.knot >= 2.10.0` dans le manifest d'une extension la
  désactive proprement sur une Core 2.9 ou antérieure. Version bump
  Core 2.9.0 → 2.10.0 motivé par cet ajout au contrat ADR-20.
- **`ManifestSchema` accepte une `ui.navigation` optionnelle (Phase 6g §L2)** :
  ajoute la validation d'une déclaration hiérarchique de la
  sous-navigation d'une extension (`[ { key, labelKey, items: [ {
  key, labelKey, icon, hash, badge? } ] } ]`). Core ne rend pas
  directement cette structure : elle est normalisée et exposée
  dans le manifest pour que (a) l'extension stamp out son shell de
  navigation sans dupliquer ses routes et (b) un futur Cmd+K
  global puisse lister ces entrées sans booter chaque extension.
  Forme strictement validée (sections + items kebab-case uniques,
  hash commençant par `#`, labelKey/icon non vides). Les manifestes
  qui n'incluent pas `navigation` restent valides (rétrocompat).
- **Tokens de design — density modes & Mission Control shell (Phase 6g §L2)** :
  `css/knot-tokens.css` expose trois jeux de variables additionnels :
  `--knot-density-{pad-x,pad-y,row,card-pad,card-gap,font}` (mode
  par défaut « comfortable » + overrides `html[data-knot-density="cozy"]`
  et `html[data-knot-density="dense"]`) et `--knot-mc-{sidebar-w,
  sidebar-w-collapsed,topbar-h,drawer-w,content-max,card-radius,
  section-gap}` pour partager la géométrie du futur shell « Mission
  Control » entre Core et les extensions hôtes. Aucun composant
  existant ne change tant qu'il ne consomme pas ces nouveaux
  tokens (additif).
- **`window.KnotCore.ui` exposes the Core design-system primitives to
  extension bundles (ADR-20 §4.3 — Phase 6e Lot 4 close-up)** : nouvelle
  propriété `ui: KnotCoreUi` sur la surface runtime
  `KnotCoreSurface` qui permet aux bundles d'extensions (Knot
  Migration et à venir) de réutiliser **les objets composants Vue**
  `KHero`, `KGlassCard`, `KEmptyState`, `KSkeleton` et
  `KAnimatedCounter` exposés par Core, au lieu d'embarquer une copie
  locale dans chaque bundle. L'extension les enregistre sur sa
  propre instance Vue (`app.component(...)` ou par import via le
  helper `coreUi.ts` côté Knot Migration). La surface reste
  ouverte : `KnotCoreUi` déclare chaque primitive comme optionnelle
  (`unknown`) pour que l'ajout futur de nouvelles primitives ne
  casse pas les bundles construits contre la liste actuelle. Core
  remplit `ui.KHero` etc. dans `frontend/src/main.ts` après
  `installKnotCore()` ; les extensions qui montent leur Vue tree
  pendant `onBeforeMount` (la voie recommandée) trouvent toujours
  la propriété peuplée. 1 nouveau test Vitest dans `knotCore.test.ts`
  vérifie que `installKnotCore()` part avec un sac `ui` vide
  modifiable. 126 tests Vitest verts.
- **Section « UI extension (ADR-20) » ajoutée à
  [`docs/extensibility.md`](docs/extensibility.md)** : documente le
  contrat manifest `ui.{menu, bundle, requiredPermission,
  ctaIfMissing, onboarding}`, la sidebar dynamique
  (`SidebarPresentation::buildExtensionItems()`), l'injection des
  bundles par `workflows/preview.php`, la surface runtime
  `window.KnotCore` complète (registerExtension, mountExtension,
  apiFetch, persistedState, openLicenseActivationModal, ui K*) et
  le cycle de vie côté extension. Référence canonique : ADR-20
  dans `knot-tools/migration`.

### Renamed

- **`frontend/src/views/MigrationAssistantView.vue` →
  `ProPackMigrationView.vue` (Phase 6e Lot 4)** : aligne le nom du
  fichier avec son `mode` Dolibarr (`pro-pack-migration`, renommé
  en commit `bdef502` pour libérer le mode bare `migration` au
  profit de l'extension Knot Migration). Le contenu reste
  identique ; seul l'import dans [`App.vue`](frontend/src/App.vue)
  et l'entête du fichier sont mis à jour. Pas de changement
  runtime — `git mv` préservé pour garder l'historique.

- **`GET api/updates.php` + `Knot\Updates\{UpdateClient,UpdateStatusCache,UpdateChecker}` — notify-only auto-update check (Phase 7d, slice A)** : nouveau endpoint qui agrège la version installée de Knot Core (`Knot\Version::current()`) et celle de chaque extension découverte par `ExtensionRegistry::discover()`, et la compare à la dernière release publiée sur `license.knot.tools/api/products/{slug}/latest` (endpoint déjà exposé par `ProductLatestController` côté backend, donc aucune migration serveur). `UpdateClient` fait l'appel HTTP cURL (timeouts agressifs : 3s connect, 6s total, `Accept: application/json`, `User-Agent: InstallationIdentity::knotCoreUserAgent('UpdateCheck')`), tolère les pannes réseau et les 404 (retourne `null` + `lastError`) et normalise la réponse en `{slug, version, channel, publishedAt, zipSize, zipSha256, signatureKid}`. `UpdateStatusCache` persiste le dernier appel réussi dans `llx_knot_config` sous la clé `updates.cache.{slug}` (TTL 24h, multi-entity natif via `KnotConfigRepository`). `UpdateChecker` orchestre le cache-aside : entrée fraîche → retour `source: 'cache'` sans réseau ; entrée stale ou absente → tentative `UpdateClient::fetchLatest()` ; succès → `source: 'live'` + refresh cache ; échec avec cache disponible → `source: 'cache_stale'` + `error` ; échec sans cache → `source: 'unavailable'` + `latestVersion: null`. La décision « update disponible » utilise `version_compare($installed, $latest, '<')` (semver-aware, gère `0.6.0 < 0.7.0` mais aussi `2.9.0 < 2.10.0` et `1.0.0-beta1 < 1.0.0`). Notify-only par design : la réponse ne contient AUCUNE URL de téléchargement (le download licencié reste exclusivement via `api/license_download_token.php` + JWT court). Auth : `ApiAuth::requireRight('knot', 'workflow', 'read')` (même droit que `marketplace.php` et `health.php`). 7 nouveaux tests PHPUnit dans `tests/Updates/UpdateCheckerTest.php` couvrent les cinq scénarios (live / cache / cache_stale / unavailable / no-op quand installed == latest), le filtrage des slugs vides et la sémantique de `compareVersions()`. Tous les 647 tests Core restent verts. Cette première vague prépare la slice B (mirror GitHub Pro Pack auto déclenché à la publication d'une release) sans précipiter le débat « one-click install » qui reste explicitement hors scope.

### Fixed

- **`SignatureVerifier::verify()` accepts both hex and base64 encoded Ed25519 signatures (hotfix Phase 7a-bonus)** : the `license.knot.tools` backend emits detached signatures via `sodium_bin2hex(sodium_crypto_sign_detached(...))` (128-char lowercase hex), which is the documented wire format and what `api/license_activate.php` persists in `LicenseCache.signature` (`value_hex` from the backend response). The verifier however only accepted base64 (`base64_decode($sig, true)`), so every cached verdict failed signature check, `DolistoreValidator::verdictFromCache()` returned `STATUS_TAMPERED`, and `ExtensionRegistry::active()` excluded the extension — net effect: an extension stayed invisible in the sidebar even after a successful licence activation, because the cache it had just written could not be re-verified on the next request. The verifier now auto-detects the encoding (preferred hex when the input is exactly 128 chars of `ctype_xdigit`, fallback to base64 otherwise), keeping full backward compatibility with any legacy callers that still pass base64. 2 new PHPUnit tests in `SignatureVerifierTest` cover hex-only and hex/base64 equivalence; all 149 licensing+extension tests stay green.
- **Dolibarr core CSRF auto-rejected legitimate POST to licence endpoints (hotfix Phase 7a-bonus)** : `api/license_activate.php` and `api/license_deactivate.php` are POST endpoints called from `knot-migration/admin/setup.php` (vanilla JS `fetch`) with a `X-Csrf-Token` header that Knot's own `CsrfGuard::verify()` checks. Dolibarr `main.inc.php` however runs an auto-CSRF check earlier in the pipeline (when `MAIN_SECURITY_CSRF_WITH_TOKEN=2`) which 403s any POST that does not carry `?token=…` in the URL or `token` in the form body — that check fired before our PHP entry point even ran, so the JSON response was Dolibarr's HTML login page which broke the `JSON.parse` in `admin/setup.php`. Both endpoints now `define('NOCSRFCHECK', '1')` to bypass the legacy URL-based CSRF guard; `CsrfGuard::verify()` continues to provide equivalent protection from the header that the JS client can safely set.

### Removed

- **Mode développement de licence (`KNOT_LICENSE_DEV_MODE` + `LicenseValidator::STATUS_DEV_OVERRIDE`) — retrait complet** : suppression de la constante PHP `LicenseValidator::DEV_MODE_CONSTANT`, du statut `STATUS_DEV_OVERRIDE`, des méthodes `LicenseValidator::isDevOverrideActiveFor()` / `LicenseValidator::devOverrideSnapshot()`, de la branche correspondante dans `LicenseValidator::inspect()` et `LicenseValidator::ensureValid()`, et du whitelist `STATUS_DEV_OVERRIDE` dans `ExtensionRegistry::discover()`. Côté HTTP : la check `license_dev_mode` et le champ `licenseDevMode` sont retirés de `api/health.php` ; le bandeau rouge "Mode dev licence ACTIF" et l'`use` associé sont retirés de `admin/setup.php` ; le type `licenseStatus` de `KnotExtensionMeta` (`frontend/src/lib/knotCore.ts`) ne liste plus `'dev_override'`. Tests : suppression de `tests/Extension/LicenseValidatorDevOverrideTest.php` et du test `ExtensionRegistryTest::testCommercialExtensionUnderDevModeOverrideIsLoaded`. Docs : suppression de `docs/dev-license.md`, purge des références dans `docs/licensing.md`, `docs/ecosystem.md`, `docs/beta-testers/known-limits.md`, `docs/audits/gpl-compliance/04-licensing-and-anti-tivoization.md`, `docs/openapi.{yaml,json}`, `api/license_status.doc.php` et `scripts/support/diagnose_extensions.php`. Motivation : éviter qu'un attaquant ou un futur contributeur ne réutilise ce code comme vecteur de contournement du système de licence. Pour le développement et la beta, le flow officiel est désormais : (a) générer un code d'activation côté backend `license.knot.tools` à la demande, (b) saisir ce code dans `LicenseActivationModal` (Vue) ou `admin/setup.php` (extension) pour qu'une licence Ed25519 signée soit câblée dans le `LicenseCache` local — exactement comme un client final. Plus aucune voie privilégiée dans le code.

### Added

- **`POST api/license_deactivate.php` — self-serve licence deactivation endpoint (Phase 7a-bonus)** : nouveau pendant de `api/license_activate.php` qui relaie un appel vers `license.knot.tools/api/license/deactivate` (livré côté serveur en Phase 7c-full) et nettoie le `LicenseCache` local sur succès. Auth identique (`knot/admin/configure` + CSRF strict), corps JSON `{activation_code, extension_id}` — l'`activation_code` est re-demandé à l'admin car `LicenseCache` ne le persiste pas (cf. `docs/runbooks/licensing-tech-debt.md`, item 2). Sur succès : retourne `{deactivated, license_id, remaining_seats, fingerprint, extensionId, cacheDeleteWarning}` et le prochain passage de `ExtensionRegistry::discover()` reclasse l'extension en `license_invalid` (donc disparition de l'entrée sidebar). Sur échec : forward verbatim de la réponse backend (404 `unknown_activation_code`, 404 `unknown_binding`, etc.) pour que l'UI puisse l'afficher. Cet endpoint complète le flow self-serve commercial qui doit être en place avant la beta Knot Migration. Pas de test PHPUnit dédié (même approche que `license_activate.php` — endpoint thin HTTP+cache testé indirectement et par smoke E2E).

- **`window.KnotCore.openLicenseActivationModal(extensionId, label?)` + event bus `knot:extension-license-activated` (ADR-20, Knot Migration Phase 7a)** : nouvelle méthode exposée sur la surface runtime `KnotCoreSurface` qui permet aux bundles d'extensions (Knot Migration et à venir) de déclencher la modal d'activation de licence Core depuis leur propre Vue tree sans dupliquer le formulaire de saisie d'`activation_code`, le calcul de fingerprint, le call `/api/license_activate.php` et l'écriture dans `LicenseCache`. Côté shell, `App.vue` monte désormais `LicenseActivationModal.vue` au niveau racine, écoute l'événement `knot:open-license-activation` dispatché par `openLicenseActivationModal()` (avec auto-résolution du label depuis `KNOT_EXTENSIONS` quand l'appelant n'en fournit pas), et redispatch `knot:extension-license-activated` sur `window` après une activation réussie avec le payload `{ extensionId, fingerprint, verdict }` ({@see KnotLicenseActivatedDetail}). Les extensions s'abonnent à cet événement (en filtrant sur leur `extensionId`) pour transitionner leur UI sans `window.location.reload()` ni round-trip HTTP supplémentaire. 3 nouveaux tests Vitest dans `knotCore.test.ts` couvrent (a) dispatch avec label explicite, (b) fallback sur le label des métadonnées, (c) rejet d'un `extensionId` vide avec warning. 23 tests Vitest verts (20 anciens + 3 nouveaux). Rebuild `dist/knot-app.{js,css}` joint.

### Fixed

- **Payload `window.KNOT_EXTENSIONS` désaligné de l'ADR-20 (Knot Migration, slice 7b)** : `core/workflows/preview.php` émettait les anciens noms `requiredPermission` / `hasPermission` alors que le contrat ADR-20 §4.2 (et la suite de tests `KnotExtensionContext` côté Knot Migration) attend `requiresPermission` / `userHasPermission`. Conséquence : la lecture TypeScript résolvait `userHasPermission === undefined`, ce qui faisait basculer `FirstVisitGate` sur layout A « admin sans permission » pour toute extension, même quand l'utilisateur courant avait la permission Dolibarr. Le payload PHP émet maintenant **les deux noms** simultanément (rétro-compat pendant un cycle de release) et ajoute aussi `status` (manquant) et `licenseExpiresAt` (manquant) prévus par le contrat. `KnotExtensionMeta` côté TS Core est mis à jour pour exposer les nouveaux champs canoniques tout en conservant les alias `@deprecated` historiques, et les fixtures de tests (`knotCore.test.ts`, `KnotExtensionMount.test.ts`) émettent les deux jeux de clés. Aucun changement runtime côté shell Core lui-même : Core consomme déjà les anciens noms, qui restent dans le payload. Rebuild `dist/knot-app.{js,css}` joint.

- **Page blanche dans le shell Vue après navigation depuis Setup (ADR-20, Knot Migration, slice 7)** : remplacement du package legacy CommonJS `dagre@^0.8` par son successeur ESM-natif `@dagrejs/dagre@^3.0` dans `frontend/src/lib/autoLayout.ts`. Avec Vite 8 + Rollup 5, le bundle IIFE produit pour `dist/knot-app.js` n'arrivait plus à initialiser `dagre.graphlib.Graph` (le default export du wrapper CJS finissait à `undefined` dans le wrapper IIFE, ce qui faisait planter le mount Vue avec `TypeError: Cannot read properties of undefined (reading 'Graph')` dès qu'on quittait le `Setup` pour atteindre une vue qui s'appuie sur l'auto-layout). Le nouveau package expose `{ graphlib, layout }` en exports nommés ESM, consommables directement par Rollup sans interop CJS. `LICENSES.md` mis à jour (MIT, même upstream Cytoscape Consortium). Rebuild `dist/knot-app.{js,css}` joint.
- **Extensions commerciales en `KNOT_LICENSE_DEV_MODE` ignorées (ADR-20, Knot Migration, slice 7)** : `Knot\Extension\ExtensionRegistry::discover()` accepte désormais `LicenseValidator::STATUS_DEV_OVERRIDE` dans la liste des statuts qui débloquent une extension (en plus de `STATUS_VALID` et `STATUS_NOT_REQUIRED`). Avant ce fix, une extension dont le manifest déclarait `licensing.validation: dolistore` restait marquée `license_invalid` même après avoir défini la constante Dolibarr `KNOT_LICENSE_DEV_MODE=<extension-id>` censée la débloquer en environnement de dev / démo — résultat : pas d'entrée sidebar, pas de bundle injecté dans `workflows/preview.php`, extension invisible. Nouveau test PHPUnit `ExtensionRegistryTest::testCommercialExtensionUnderDevModeOverrideIsLoaded` (en `@runInSeparateProcess` à cause du `define()`) garantit la non-régression.

### Added

- **Diagnostic générique des extensions actives (`scripts/support/diagnose_extensions.php`)** : nouveau script CLI/HTTP réservé aux admins Dolibarr qui dump le résultat de `ExtensionRegistry::discover()` (id, status, licenceInfo, manifest UI, bundles JS/CSS résolus, ctaIfMissing, droits requis) pour identifier rapidement pourquoi une extension n'apparaît pas dans la sidebar ou pourquoi un bundle ne charge pas. Auto-détection du `main.inc.php` Dolibarr (parcours d'ancêtres puis `DOL_DOCUMENT_ROOT` puis `KNOT_DOL_DOCUMENT_ROOT`), aucun chemin ni domaine en dur, fonctionne sur tout déploiement (local Docker, Plesk, hébergement mutualisé). Utilise `Knot\Licensing\Bootstrap::buildExtensionRegistry()` si disponible (chemin de production complet avec `DolistoreValidator` câblé) ou retombe sur un `ExtensionRegistry` minimal sinon, en signalant le mode utilisé dans la sortie pour éviter toute ambiguïté de diagnostic.

### Changed

- **Renommage du mode Core `migration` en `pro-pack-migration` (ADR-20, slice 5)** : la vue Vue native `MigrationAssistantView.vue` (assistant de migration des connecteurs déplacés de Core vers le Pro Pack en V2.5.0b) est désormais routée sur `?mode=pro-pack-migration` au lieu de `?mode=migration`. Trois fichiers touchés côté Core : `frontend/src/App.vue` (case du `switch` de `view`), `workflows/preview.php` (`$allowedModesFull`), commentaires PHPDoc dans `class/Migration/ConnectorMigration.php` et `api/migration_scan.php`. Objectif : libérer le slot kebab-case `migration` pour que Knot Migration puisse le revendiquer comme extension UI via `knot-extension.json` (`ui.menu.mode: "migration"`) sans déclencher le garde-fou de collision installé au slice 3 (Core gagne toujours sur collision, log warning, extension masquée). Aucun changement de comportement de l'assistant Pro Pack : la vue, ses i18n et son API `marketplace.php['migration']` (clé de payload, pas une URL) sont intactes. Tout consommateur qui pointait en dur vers `?mode=migration` pour atteindre l'assistant Pro Pack doit basculer sur `?mode=pro-pack-migration` ; aucune référence n'existe côté `pro-pack/`, `pro-pack-mirror/`, `website/` ou `test-playwright/` au moment du rename (validé `rg`). Les fixtures de tests d'extensions UI (`tests/Extension/ManifestSchemaTest.php`, `ExtensionRegistryTest.php`, `SidebarPresentationTest.php`, `frontend/src/lib/__tests__/knotCore.test.ts`, `KnotExtensionMount.test.ts`) qui utilisaient déjà `mode: 'migration'` comme nom symbolique d'extension cible deviennent cohérentes avec l'ADR sans modification. 644 PHPUnit verts + 121 Vitest verts cumulés. L'ADR-20 est désormais complètement applicable côté Core ; Phase 6f peut commencer à réécrire Knot Migration comme extension UI consommant les surfaces des slices 1 → 5.

### Added

- **Persistance serveur de `KnotCore.persistedState` (ADR-20, slice 4)** : nouvelle table `llx_knot_extension_state` (migration idempotente `sql/migrations/v2.10.0/01_extension_state.sql`) + `Knot\Extension\ExtensionStateRepository` (UPSERT par UNIQUE KEY `(entity, extension_id, fk_user, state_key)`, scoping multi-tenant strict, quotas `MAX_KEY_LENGTH=128` / `MAX_VALUE_BYTES=65535` / `MAX_KEYS_PER_PAIR=200`, validation kebab-case stricte des IDs d'extension). Nouvel endpoint `api/extension_state.php` qui multiplexe GET (renvoie `{state: {key:value, …}}` pour la paire utilisateur courant × extension), POST (upsert d'une clé, CSRF obligatoire, body `extension_id`/`key`/`value`) et DELETE (suppression d'une clé ou wipe complet si `key` absent, CSRF obligatoire). Refus systématique des IDs d'extension absents de l'`ExtensionRegistry` actif (404 `unknown_extension`) pour empêcher tout squatting de la table par un client mal intentionné, plus contre-mesure anti-flood 429 `quota_exceeded`. Côté frontend, `persistedState(id)` retourne désormais un store hybride : cache localStorage synchrone (zero rewrite côté extension) + miroir HTTP best-effort en arrière-plan. Nouvelles méthodes `pull(): Promise<void>` (rehydrate le cache depuis le serveur — appelée par les extensions au boot pour récupérer un état écrit depuis un autre navigateur) et `flush(): Promise<void>` (attend que la file d'écritures se vide, utile avant navigation post-onboarding). 12 tests PHPUnit (`ExtensionStateRepositoryTest`) + 5 nouveaux tests Vitest (`pull/flush/POST/DELETE/error-tolerance`), tests existants conservés (644 PHPUnit verts, 121 Vitest verts). Slice 5 renommera le mode Core `migration` pour libérer le slot extensions, après quoi Knot Migration peut commencer à consommer ces 3 surfaces (manifest UI, sidebar item, persistedState HTTP).

- **Runtime API `window.KnotCore` + bootstrap des extensions UI (ADR-20, slice 3)** : `workflows/preview.php` découvre les extensions UI actives via `ExtensionRegistry`, injecte un payload `window.KNOT_EXTENSIONS` (id, label, version, mode, bundles JS/CSS, requiredPermission, statut licence, droits utilisateur, onboarding), étend `$allowedModes` avec les `ui.menu.mode` déclarés et charge automatiquement les `<link rel="stylesheet">` + `<script defer>` de chaque extension. Côté frontend, `installKnotCore()` (importé en tête de `main.ts`) installe le singleton `window.KnotCore` AVANT le boot Vue, exposé aux bundles d'extensions chargés en `defer` : `registerExtension(id, { mount, unmount? })`, `extension(id)`, `extensions`, `persistedState(id)` (backing localStorage scopé pour slice 3, sera remplacé par un backend HTTP au slice 4 sans changement de surface), `apiFetch(path, init?)` (joint `KNOT_API_BASE`, ajoute CSRF + Accept JSON), `mountExtension`/`unmountExtension`. Le composant `KnotExtensionMount.vue` consomme cette surface : si l'URL `?mode=<x>` matche le mode d'une extension active, `App.vue` rend `<KnotExtensionMount>` au lieu d'une vue native — le composant attend l'événement `knot:extension-registered` si le bundle de l'extension n'a pas encore fini de charger, puis appelle `KnotCore.mountExtension(id, el)` qui passe le contexte typé (`meta`, `apiBase`, `csrfToken`, `baseUrl`, `locale`, `coreVersion`, `persistedState`, `apiFetch`) au handler de l'extension. État d'erreur visuel quand `KnotCore` manque, quand l'extension n'est pas active ou quand le mount échoue. Validation des modes URL passe à `alphanohtml` + regex stricte `^[a-z][a-z0-9-]{0,63}$` pour autoriser les ids kebab-case côté extension. Collision de mode entre Core et une extension : Core gagne (log warning) ; le slice 5 renommera Core's `migration` en `pro-pack-migration` pour libérer le slot pour Knot Migration. 22 tests Vitest ajoutés (116 verts cumulés). Aucun changement sur le store d'état persistant côté DB — Knot Migration peut commencer à consommer la surface dès le slice 4 avec son SQL repository en place.

- **Sidebar — injection des entrées d'extensions (ADR-20, Knot Migration, slice 2)** : `core/tpl/knot-leftnav.tpl.php` boucle désormais sur `ExtensionRegistry::active()` après son `$items` natif et fusionne les entrées contribuées par les extensions au bon endroit (section `dashboard`/`operations`/`catalog`/`admin`, tri par `position` puis id pour stabilité). Toute la logique est portée par une classe helper `Knot\Extension\SidebarPresentation` (pure transformation) testée isolément, ce qui garde le partial dumb. Gating des permissions : entrée affichée si `requiredPermission` est satisfaite ; admin sans la permission obtient l'entrée + badge si l'extension déclare `onboarding.ctaIfPermissionMissingForAdmin` ; user simple sans la permission ne voit rien. Icônes acceptées en forme courte (`package`) ou longue (`fa-package`). Discovery best-effort : toute exception (autoload manquant, Bootstrap indisponible) est loguée et n'interrompt jamais le rendu nav. Styles CSS `.knot-nav__item--ext` (hook neutre) et `.knot-nav__item--ext-cta` (pastille jaune `!` pour signaler à l'admin qu'il reste un setup à faire) ajoutés dans `css/knot-host.css`. Attributs `data-knot-ext-id` et `data-knot-ext-cta="admin-setup"` posés sur les `<a>` correspondants pour faciliter Playwright. 13 tests PHPUnit ajoutés (632 verts cumulés, +13). Aucun changement runtime côté Vue dans ce slice — le clic sur l'entrée d'extension renvoie pour l'instant sur `?mode=<extension-mode>` qui sera consommé par le slice 3.

- **Extension manifest — section `ui` optionnelle (ADR-20, Knot Migration)** : `ManifestSchema::validate()` accepte un nouveau bloc `ui` dans `knot-extension.json` qui décrit l'intégration UI d'une extension dans le shell Knot Core (entrée de menu gauche, bundle JS/CSS chargé au runtime, permission Dolibarr requise, CTA fallback, hooks d'onboarding admin). Champs validés : `ui.menu.{label, labelLang, mode, section, icon, position}`, `ui.bundle.{js, css, globalEntry}`, `ui.requiredPermission` (forme Dolibarr `module.perm[.subperm]`), `ui.ctaIfMissing.{label, url}` (URL http/https obligatoire), `ui.onboarding.{adminSetupRequired, adminSetupUrl, ctaIfPermissionMissingForAdmin}`. Défauts appliqués : `menu.section=operations`, `menu.icon=puzzle`, `menu.position=1000`, `onboarding.adminSetupRequired=false`. Sections de menu autorisées : `dashboard|operations|catalog|admin`. `ExtensionRegistry::active()` et `discover()` exposent désormais la clé `ui` (null si absente ou manifest invalide), prête à être consommée par `core/tpl/knot-leftnav.tpl.php` (slice 2) et le bootstrap Vue (slice 3). Pas de chargement runtime ni de rendu sidebar dans ce slice — purement schema + plumbing PHP. 13 tests PHPUnit ajoutés (619 verts cumulés). Premier slice de l'implémentation Core de l'ADR-20.

### Documentation

- **`README.md`** — statut commercial harmonisé pour les extensions : **Knot Pro Pack** marqué « commercial extension » dès la section *At a glance* (cohérent avec l'écosystème en bas du fichier) ; **Knot Migration** reclassé en « commercial extension, currently used in-house to onboard new beta testers ; standalone release planned at a later stage ». Pas de prix ni de date — renvoi vers **knot.tools** pour les conditions commerciales. Wording miroir appliqué dans `knot-tools/.github` (profile README EN + FR) pour cohérence éditeur.
- **`docs/runbooks/ci-runbook.md`** : nouveau runbook opérationnel CI (audience : maintainers + agents IA). Inventaire des 5 workflows (`ci`, `security`, `dolibarr-coverage-monitoring`, `ground-truth-scheduled`, `mission-docker-smoke`), versions épinglées et leurs raisons (Node 20 / PHP 8.1+/8.2/8.3 / Dolibarr V20-V22 / `actions/checkout@v5` / `actions/setup-node@v5` / `actions/setup-python@v6` / `gitleaks 8.30.1`), 13 pièges hard-won documentés (PHP 8.4 chaining, PSR-4 case-sensitivity Linux, PSR-12 file header order, LineLength=SHOULD pas MUST, `gitleaks-action` payant, `npm audit --omit=dev`, `composer check-platform-reqs`, `fetch-depth: 0` requis pour gitleaks, gating des jobs lourds, PHPStan advisory, scopes `gh` CLI, `two_factor_requirement_enabled` déprécié 2026-03), checklist d'ajout de workflow, recettes de débogage local par job. **`AGENTS.md`** mis à jour avec un pointeur en tête de section CI + entrée dans « Pour aller plus loin ».
- **`README.md`** refondu pour s'aligner sur le profile README de l'organisation **knot-tools** : bannière responsive `<picture>` (un seul tag), badges de stack à jour (TypeScript 5, Vue 3.5, Vite 8, Tailwind 3, PSR-12, GPL-3.0), bento "What's inside" en `<table>`, sections **Reliability & quality** (605 tests · 2 013 assertions · matrice CI 9 combinaisons V20/V21/V22 × PHP 8.1/8.2/8.3) et **Hardened by design** (AES-256-GCM, mitigation SSRF + DNS-rebinding, signed updates planifiés). Trademark **Knot Tools™** et nom produit **Knot Core** uniformisés. **Toutes les mentions de prix retirées** (renvoi vers **knot.tools** pour les conditions commerciales). Mention **Knot Migration** ajoutée à l'écosystème (usage interne, release standalone planifiée).

### Tooling / CI

- **PHP_CodeSniffer** : ruleset projet **`phpcs.xml.dist`** — étend `PSR12` strict (toutes règles `MUST` enforced : ordre du file header, accolades, indentation, visibilité, blank lines, ordre des `use`…) tout en désactivant `Generic.Files.LineLength` (la seule règle PSR-12 `SHOULD`, pas `MUST`). Aligné avec la pratique standard de l'écosystème (Symfony, Laravel, PHPUnit, Composer, Magento : aucun ne bloque la CI sur LineLength). Évite des splits hasardeux sur strings i18n, signatures Dolibarr et payloads signés au profit de checks structurels stricts. Job CI `lint-php` débarrassé du contournement `|| true` : toute violation PSR-12 fait désormais échouer la PR.
- **`class/autoload.php`** : ordre du file header corrigé (docblock avant `declare(strict_types=1);`, conforme à PSR-12 §3) — règle `PSR12.Files.FileHeader.IncorrectOrder` était la seule erreur `MUST` détectée par phpcs sur `class/`.

## [2.9.0] - 2026-05-14

### Tests / tooling

- **Compatibilité Dolibarr** : image Compose paramétrable via `KNOT_DOLIBARR_DOCKER_IMAGE` ; `e2e_boot.py` aligné sur l’image officielle `dolibarr/dolibarr:21.0.4-php8.2` (variable `KNOT_DOLIBARR_IMAGE`) ; CI **`dolibarr-matrix`** étendu à **22.0** (PHP 8.2) ; récap dans `docs/testing.md` + `test-playwright/README.md`.

- **Sortie Playwright hors repo `core`** : répertoire **`test-playwright/`** (frère du clone) + variable **`KNOT_PLAYWRIGHT_OUTPUT_ROOT`** ; **`/dist/`** ré-ignoré à la racine `core/` — build obligatoire avant pack/release si tu ne versionnes pas les assets (voir **`test-playwright/README.md`** à la racine `knot-tools/`).

- **Playwright démo** : attributs **`data-knot-test`** sur la grille éditeur (`knot-editor-layout`, `knot-editor-palette`), panneau simulation (`knot-simulation-aside`, **`knot-simulation-close`**), helpers **`tests/e2e/helpers/knot-editor-ready.ts`** alignés ; script **`scripts/demo_knot_vm_build_deploy_knot.sh`** (build + rsync) ; garde-fou **`demo_knot_vm_deploy_knot.sh`** déjà présent si `dist/` absent ; sortie Playwright par défaut **`../test-playwright/`** (frère du dépôt `core/`).
- **PHPUnit** : `ModuleExpectationsTest` (menus `modKnot` vs `ModuleExpectations::MENU_ENTRY_COUNT`) ; `DescriptorCacheFileCountTest` (entrées fichier descripteurs).

### Security

- **HTTP client / SSRF** (`Knot\Security\HttpClient`, `Knot\Security\UrlPolicy`) : nouvelle méthode `UrlPolicy::resolve()` qui retourne l’IP déjà validée ; `HttpClient` épingle cette IP via `CURLOPT_RESOLVE` (host / port / IP) sur la requête initiale **et** après chaque redirection 3xx. Empêche un attaquant qui contrôle un domaine en TTL faible de faire diverger la résolution DNS entre la validation et l’appel cURL (DNS rebinding) et de pointer vers une IP privée / metadata. Tests `tests/Security/UrlPolicyTest.php` enrichis (`resolve()` retourne host/ip/port, ports par défaut par schéma, valeurs nulles pour URL bloquée).
- **CI sécurité** : nouveau workflow `.github/workflows/security.yml` (`gitleaks` historique complet, `composer audit`, `npm audit --omit=dev --audit-level=high`) sur push/PR + cron hebdomadaire ; configuration `.gitleaks.toml` qui allowlist les fixtures de test embarquant volontairement des patterns ressemblant à des secrets (`SecretMaskerTest`, `RuntimeLoggerTest`, `_e2e_phase*.py`).
- **Frontend `vite`** : passage `vite ^5.4.11 → ^8.0.13` (et `@vitejs/plugin-vue ^5.2.1 → ^6.0.6`) — corrige `GHSA-67mh-4wv8-2f99` (esbuild dev server lisible par n’importe quel site web sur le poste du dev). Build prod inchangée fonctionnellement (`knot-app.js` 959 KB / `knot-app.css` 41 KB) ; option `inlineDynamicImports` redondante retirée (Rollup 5 inline déjà en mode IIFE) ; `npm audit` passe à **0 vulnérabilité**.

### Fixed

- **Packaging Dolistore** (`scripts/package_dolistore.py`) : exclusion systématique de `demo/`, `docker/`, `knot-marketplace/`, `docs/audits/`, `docs/runbooks/`, fichiers d’environnement (sauf suffixe `*.example`), fichiers racine optionnels type `REVIEW_*.md`, tout `test-llm*.knot.json`, `AGENTS.md`, `.DS_Store` ; précision dans `docs/admin-guide.md` § Packaging. Ajout d’une étape `composer install --no-dev --optimize-autoloader` (avec restauration du `vendor/` de dev après build, backup déposé sous `build/vendor-dev-backup/` exclu du paquet) — le ZIP final n’embarque plus PHPUnit/PHPStan ; passage de **2220 fichiers / 3.1 MB → 516 fichiers / 2.0 MB** ; option `--with-dev-deps` pour conserver l’ancien comportement si besoin.
- **PHPUnit** : suppression de `ReflectionProperty::setAccessible(true)` dans `tests/Marketplace/CatalogClientFactoryTest.php` (méthode dépréciée en PHP 8.5+ et sans effet depuis 8.1).
- **CI matrice PHP / Dolibarr** : `tests/Licensing/InstallationIdentityTest.php` utilisait la syntaxe `new InstallationIdentity(...)->method()` introduite en **PHP 8.4** ; cassait tous les jobs `PHP 8.1/8.2/8.3 tests`, `Dolibarr 20/21/22 integration` et `dolibarr-coverage-monitoring` (parser fatal avant le premier test). Réécrit en `(new ...)->method()` (compat 8.1+, conforme à la cible Dolibarr V20+).
- **Audit log API** (`api/audit.php`, `AuditLogRepository`) : paramètre **`q`** (recherche full-text serveur sur action, entité, payload, IP, ids) ; filtres **`action_type` / `entity_type`** avec caractères `._-` (types du genre `licensing.refresh.failed`) ; alias optionnels **`actionType` / `entityType`** ; export CSV aligné ; vue **Audit** envoie `q` au lieu d’un filtre client seul.
- **Licence** : même **cooldown** anti-audit pour **`LICENSE_REFRESH_FAILED`** lorsqu’**aucun cache** signé n’existe encore (fichiers `licenses/.refresh-throttle/` via `LicenseCache`) — évite le spam observé sur instances sans payload en cache.
- **Inspecteur / vue-i18n** : exemples de clé d’idempotence avec `{{ … }}` retirés des fichiers de locale (compilation intlify « nested placeholder » au clic sur un nœud Dolibarr) ; textes techniques dans **`frontend/src/lib/idempotencyPlaceholders.ts`** ; placeholder du panneau **AI Prompt** via liaison Vue (`:placeholder`) pour éviter l’interprétation des accolades dans le template ; fermeture du panneau **simulation** réinitialise **`simResult`** et retire **`knot.lastSim.{id}`** du **`localStorage`** (inspecteur plus masqué après dismiss).
- **Capabilities manifest** (`CapabilitiesBuilder`, **`api/capabilities.php`**) : construction de la palette tolérante aux erreurs (snippet marketplace / metadata) pour ne plus faire échouer tout le manifest ; écriture cache ignorée si répertoire non inscriptible ; écran **Capabilities** affiche le détail serveur (`error.details.detail`) pour le diagnostic.
- **`ExtensionRegistry::loadedConnectors()`** : si `getMetadata()` lève une exception sur un connecteur d’extension (Pro Pack ou tiers), ce connecteur est ignoré au lieu de faire échouer tout le manifest — aligné avec la politique « extensions invalides listées, pas de crash » ; test PHPUnit associé.
- **`ConnectorRegistry::all()`** : même tolérance sur les connecteurs Core (premier appel dans le manifest) ; évite un échec total si un seul `getMetadata()` plante sur une instance.
- **`api/capabilities.php`** : si `Bootstrap::buildExtensionRegistry()` échoue (licensing / persistance config), repli sur un **`ExtensionRegistry`** par défaut pour que le manifest reste publiable.
- **Setup Extensions** (`admin/setup.php`) : découverte des extensions avec **`Bootstrap::buildExtensionRegistry($db)`** au lieu de `new ExtensionRegistry()` — corrige l’état **LICENSE_INVALID** / « no DolistoreValidator wired » sur l’écran **Extensions Knot installées** alors que le runtime Dolistore est pourtant disponible.
- **UTF-8** : `.htaccess` à la racine du module (`AddDefaultCharset`, `Header` + `FilesMatch` sur `*.md` quand Apache sert le fichier) ; `docs/deployment.md` explique le cas **nginx statique** (Plesk), un **snippet `charset utf-8`** pour `/custom/knot/docs/*.md`, et le **BOM UTF-8** sur `docs/beta-testers/*.md` pour l’affichage navigateur sans `charset` dans l’en-tête HTTP.
- **Traductions** : suppression du `&` dans `KnotMarketplaceUiChrome` et `KnotStep2Title` (`en_US` / `fr_FR`) pour éviter l’affichage littéral `&amp;` après échappement HTML.
- **Menus** : seuil d’enregistrement attendu porté à **7** (entrée Observability) via **`Knot\Module\ModuleExpectations`** (`admin/setup.php`, `api/health.php`) ; libellés de contrôle moins ambigus.
- **Introspection** : manifest **Capabilities** avec **`objects.descriptor_file_count`** (lignes du fichier cache) en complément de **`supported_count`** ; écran Capabilities (i18n), Doctor (`supportedSlugCount`), carte setup #knot-introspection et `docs/api/capabilities.md` clarifient l’écart fichier vs slugs API.

### Improved

- **Exécutions** : le libellé workflow pointe vers **`mode=execution&execution_id=`** ; lien secondaire **« Edit workflow »** vers l’éditeur.
- **Éditeur** : sections de palette **sans résultat** masquées lors d’une recherche ; bannière **starter** (exemple initial) avec **vider le canevas** et **masquer**.
- **Docs** (beta-testers) : marque **Knot Tools** ajoutée dans les titres / accroches des `.md` (module **Knot** conservé pour l’UI et le technique).
- **Docs** (beta-testers) : `README.md` — liens expliqués pour affichage navigateur en texte brut, entrée **LICENSES.md** dans *Lien rapide*, engagement éditeur nuancé (**license.knot.tools** pour extensions payantes, pas de télémétrie produit cachée).
- **Docs** : README — marque déposée **Knot Tools**, mention dans l’écosystème et section **Trademark** sous Licence ; nom produit **Knot** inchangé pour le module.
- **Docs** : section **Journal d’audit** dans `docs/api-reference.md` ; `api/audit.doc.php` et spec **OpenAPI** regénérée (`composer run docs:openapi`) — paramètres `q`, filtres, alias, export CSV ; droit documenté **`workflow.read`** (cohérent avec `audit.php`).

## [2.8.4] - 2026-05-04

### Améliorations

- **Licence Dolistore** : fenêtre anti-spam pour les audits **`LICENSE_REFRESH_FAILED`** et **`LICENSE_GRACE_ENTERED`** (`LicenseAuditThrottlePolicy`, `LicenseCache`, `DolistoreValidator`) — évite de saturer `llx_knot_audit_log` sur erreurs réseau répétées ; tests PHPUnit dédiés.
- **`preview.php`** : **302** vers le tableau de bord si `mode` inconnu (non vide) ; item nav **Workflows** actif lorsque `mode=editor` avec `workflow_id>0` ; **Executions** actif sur la vue détail (`mode=execution`).
- **Exécutions** : filtre serveur **`status`** (`ExecutionRepository`, **`api/executions.php`**, doc OpenAPI) ; liste SPA synchronisée avec **`?status=`**, cartes KPI cliquables (accessibilité clavier), libellés i18n ; tests repository multi-entité.
- **Simulation** : titres lisibles dans le panneau simulation et la trace plein écran (carte id → libellé canvas).
- **Inspecteur** : textes d’aide et exemples de placeholder **idempotence** selon le type de nœud (générique / Dolibarr / HTTP).
- **Modal données de test** : libellés i18n sans mention de version interne ; onglet **Auto stub** désactivé avec message produit.
- **Credentials** : page via **vue-i18n**, bandeau sans nom de table SQL ; entrée menu Dolibarr **`KnotFeature3Title`** alignée avec la page.
- **Observabilité** : lien rapide vers **`mode=executions&status=failed`** en complément de la liste générale.
- **i18n** : nav Dolibarr (`knot-leftnav.tpl.php`) ; blocs **observabilité** alignés **DE/ES/IT/PT** sur `en_US` ; nouvelles clés **credentialsPage**, **inspector**, **testDataModal**.

### Tests

- PHPUnit **`InternalApiAuthMatrixTest`** : inventaire **`api/*.php`** (hors `*.doc.php`), garde-fous **CSRF** sur mutateurs documentés, liste blanche **`NOLOGIN`**.

### Documentation

- **`CLAUDE.md`** / **`README.md`** / **`website/README.md`** : clarification **prix Core** (**GPL-3.0**, distribution Dolistore + updates optionnelles) vs **Pro Pack** (abonnement séparé, voir knot.tools).
- **`docs/llm/decisions.md`** : décision anti-spam audits licence + filtre exécutions.

## [2.8.3] - 2026-05-04

### Ajouté

- **`InstallationIdentity`** (`class/Licensing/InstallationIdentity.php`) : `deploymentToken` ( preimage `knot-deploy-v2` \| URL canonique \| fingerprint BD sans secrets \| pepper public Knot ) + `deploymentNonce` UUID (**`llx_knot_config`** / `install.deployment_uuid`, **distinct par entité** ) vers `license`/marketplace via en-têtes `X-Knot-Deployment-*` et JSON facultatif (`DolistoreClient`, **`api/license_activate.php`**) — **`instanceId` Dolistore inchangé** ; `SecretMasker` masque les clés `deployment_nonce` et **`deployment_token`** ; tests PHPUnit `InstallationIdentityTest` + **`DolistoreValidatorTest`** (mock forward identité).

### Fixed

- **Setup sidebar** (`admin/setup.php` + **`knot-leftnav.tpl.php`**) : après désactivation du catalogue (**`KNOT_MARKETPLACE_UI_ENABLED`**), la barre latérale Knot sur l’écran **Setup** retire bien l’entrée **Marketplace** (aligned avec `preview.php`).
- **Connector catalog** (`ConnectorsView`): keep the detail pane aligned with category/source/search filters by reconciling `selectedId` after render (`flush: 'post'`); remove redundant **All sources** pill (toggle active source off with a second click to show all sources); `type="button"` on list rows; **`connectorsPage.sourceFiltersLabel`** i18n.
- **Marketplace télémetrie licence** (`api/marketplace.php`, **`CatalogCache`**, **`TemplateClient`**) — sur **`GET`** regroupé, si le catalogue vient de faire un `fetch()` réseau dans la même invocation PHP, **`TemplateClient::all`** réutilise le JSON normalisé complet au lieu d’un second **`GET /api/catalog.json?kind=template`**, évite les lignes **`catalog`** doublonnées dans l’admin **Deployments** (`license`) pour un même clic / chargement Marketplace.

### Tests

- PHPUnit `SecretMaskerTest` : masquage explicite des payloads avec clé **`deployment_token`**.

### Documentation

- Dossier d’audit technique **GPL-3.0** sous `docs/audits/gpl-compliance/` (six axes + synthèse).
- Runbook démo : **`scripts/demo_knot_vm_deploy_pro_pack.sh`** (rsync Pro Pack vers `custom/knotpropack/` sur la démo).
- **`docs/licensing.md`** — **URL publique** et **fingerprint base** (preimage `knot-deploy-v2`), contrôle volumétrique serveur **`license`**, rappel **multi-entité** nonce/token.
- **`docs/llm/decisions.md`** — télémetrie **`DEPLOYMENT_TELEMETRY_DEDUP_SECONDS`**, masquage logs, réutilisation catalogue marketplace.
- Pro Pack (**`pro-pack`** / `docs/audits/gpl-compliance/` boundary) — **`requires.knot` >= 2.8.3** avec cette release Core.

## [2.8.2] - 2026-05-03

### Added

- **Catalog URL** — `CatalogClientFactory` centralise `MAIN_KNOT_LICENSE_BASE_URL` pour `api/marketplace.php`, `api/templates.php` et `TemplateRepository` (voir `docs/architecture.md`).
- **SKU officiels** — `Knot\KnownSkus` + `frontend/src/lib/known-skus.ts`; **ADR-018** (index README décisions).
- **Snippet présentation** — fetch JSON `connector-presentation` (cache `ConnectorPresentationCache`, merge `ConnectorPresentationMerger`, refresh avec `marketplace.php`/`templates.php?action=refresh`); même merge que **`GET /api/connectors`** palette metadata.
- **`manifestSignature`** (optionnelle, 128 hex) sur licences manifest extension ; **`ForkDetector` / `DolistoreValidator`** vérifient l’empreinte officielle (`docs/internal/signing-process.md`).
- **Toggle vitrine** — constante **`KNOT_MARKETPLACE_UI_ENABLED`** (défaut `1`): masque entrée SPA/nav, **`403`** `marketplace_ui_disabled` sur **`/api/marketplace.php`** et **`/api/templates.php`** uniquement ; **sans** blocage **`license_activate`**, **`license_status`**, **`bundled_templates.php`**, **`connectors.php`**. **ADR-019**, **`docs/internal/communication-guidelines.md`**.

### Changed

- **Admin setup** (`admin/setup.php`) : carte Marketplace — interrupteur vitrine catalogue + aide ; audit `config.marketplace_ui_changed`.
- **SPA** (`CommandPalette`, `Dashboard`, `Workflows`, `MigrationBanner`, `TemplatesView`) : liens Marketplace conditionnels ; redirection legacy `?mode=templates` → dashboard si vitrine désactivée.
- **Host** (`workflows/preview.php`) : `?mode=templates` et `?mode=marketplace` redirigent vers le dashboard lorsque la vitrine est désactivée.
- **Pro Pack** `knot-extension.json` — `requires.knot` **>= 2.8.2** (`manifestSignature` alignée catalogue).

### Tests

- PHPUnit `KnotMarketplacePresentationTest`, `ConnectorPresentationMergerTest`; Vitest `marketplaceUi.test.ts`.

### Documentation

- `docs/testing/v2-8-2-validation-report.md`; `docs/missions/v2-8-2-finishing-report.md`; synthèse GPL `docs/audits/gpl-compliance/00-synthesis.md` (mise à jour interrupteur Marketplace) ; roadmap ; `scripts/e2e/http_api.py` (variable d’env si agrégateur désactivée) ; regen OpenAPI.

## [2.8.1] - 2026-05-03

### Fixed

- **`ExtensionRegistry`** : discovery scans every `custom/*/` folder that has a readable **`knot-extension.json`**, not only names prefixed `modKnot*`. Fixes Marketplace / migration UI showing Pro Pack as **not installed** when the Dolibarr module lives under paths such as **`custom/knotpropack/`**.

### Changed

- **Slim Core palette (ADR-017)** : outgoing universal HTTP (`action.http`), SFTP (`action.sftp`), Telegram (`action.telegram`), all AI chat connectors (including `action.ai_ollama`), Stripe/Shopify **named** webhook triggers, and multi-channel **`notification.alert`** fan-out are **Pro Pack only**. Core ships **`notification.alert`** as **audit-only** (no outbound network on that path).
- **Pro Pack** : connector implementations previously kept under Knot Core `class/Connectors/{AI,Saas}/` now ship as **`Knot\Extension\ProPack\Imported\*\`** inside `knot-pro-pack`; thin **`LicenseGate`** wrappers stay in **`Knot\Extension\ProPack\*\`**. New wrappers: **`HttpConnector`**, **`TelegramConnector`**, **`SftpConnector`**, **`OllamaConnector`**, **`StripeWebhookConnector`**, **`ShopifyWebhookConnector`**, **`NotificationFanoutConnector`** (`notification.alert_fanout`). **`requires.knot`** raised to **>= 2.8.1**.
- **`ConnectorMigration::MIGRATED_TO_PRO_PACK`** extended with the ids above so editor banners / scans stay accurate.

### Documentation

- ADR **017**, **`docs/connectors-inventory.md`**, **`docs/network-egress.md`**, **`docs/state-machine-versioning-integration.md`**, **`docs/why-knot.md`**, **`docs/beta/`** validation notes, roadmap/decisions cross-links.

### Tests

- PHPUnit Core **`ConnectorMigrationTest`**, **`AlertActionTest`** (audit-only contract); Pro Pack **`ConnectorMigrationParityTest`** now targets **Imported** bases instead of removed Core classes.

## [2.8.0] - 2026-05-03

### Added

- **Observability (session)** : `MetricsCollector::nodeObservabilityByType()` agrège `llx_knot_execution_log` (runs / erreurs / durée moyenne par `node_type`) ; **`GET /custom/knot/api/observability.php`** (`workflow.read`, fenêtre `days`, borne `limit_types`) ; vue **`ObservabilityView`** (`?mode=observability`) ; entrées menu Dolibarr + nav Knot ; **ADR-015** ; guide **`docs/observability/dashboard-guide.md`**.
- **Ground truth** : **`scripts/ground-truth-check.php --validate-snapshots`** (JSON sous `data/compatibility/snapshots/`, hors `sample-*`) ; workflow GitHub Actions planifié **`.github/workflows/ground-truth-scheduled.yml`** ; PHPUnit **`GroundTruthCheckTest`** ; **ADR-016**.
- **Bundled templates (offline)** : manifest **`data/templates/index.json`**, workflows sous **`data/templates/`**, **`GET /custom/knot/api/bundled_templates.php`** ; onglet Marketplace **Embarqués** ; **ADR-014** ; **`docs/templates/`**.
- **Workflow DSL lint** : schéma JSON **`schemas/workflow-definition-v1.schema.json`**, extension **`WorkflowValidator`**, lint API / **`useWorkflowLinter`** / panneau **Problems** ; catalogue erreurs **`docs/errors/catalog.md`** ; **ADR-013** ; **`docs/dsl/workflow-schema.md`**.

### Documentation

- **`docs/api-reference.md`** (observabilité, bundled templates), **`docs/architecture.md`** (`class/Reporting/`), **`docs/testing.md`** (E2E **`v2-8-smoke.spec.ts`**), **`docs/frontend-architecture.md`**, **`docs/roadmap.md`**, **`docs/missions/v2-8-report.md`**, **`docs/testing/demo-verification-last-run.md`**, index ADR **013–016**.

### Tests

- PHPUnit **`MetricsCollectorNodeObservabilityTest`**, **`BundledTemplatesManifestTest`**, **`PilotDocumentsTest`** (SM éligibilité étendue) ; Playwright **`tests/e2e/specs/v2-8-smoke.spec.ts`** ; Vitest linter / i18n / immutabilité (`frontend/src/__tests__`, **`useWorkflowLinter.test.ts`**).

## [2.7.1] - 2026-05-03

### Added

- **Canvas SM-aware hints** for **`change_status`** on pilot slugs (`facture`, `commande`, `propal`): lazy evaluation (`useCanvasChangeStatusRisk`), TTL cache, `KnotNodeSmHintBadge`, inspector jump from canvas (`docs/ux/state-machine-display.md`).
- **Bundled reference snapshots** under **`data/compatibility/snapshots/`** (`dolibarr-21.0.4.json`, synthetic **`reference-diff-demo.json`**).
- **`BundledSnapshotCatalog`**, capabilities **`schema_versioning`** block (bundled filenames / versions — not live Dolibarr).
- **API** **`GET compatibility.php?action=bundled_snapshots`** and **`GET …&action=bundled_snapshot&file=`** (basename allowlist, `workflow.read`).
- **CLI** **`scripts/generate-schema-snapshot.php --output <path>`** (stdout unchanged when omitted).
- **Vue** **`CompatibilityView`**: load bundled snapshots into baseline/target.
- **Tests**: PHPUnit **`BundledSnapshotCatalogTest`**, extended **`SchemaComparatorTest`**; Playwright **`canvas-sm-aware.spec.ts`**; Vitest **`useCanvasChangeStatusRisk`**.

### Documentation

- **`docs/compatibility/README.md`**, **`data/compatibility/README.md`**, **`docs/api/capabilities.md`**, **`docs/api-reference.md`**, ADR-011 note, **`docs/runbooks/demo-dolibarr/post-deploy-checklist.md`**, **`docs/testing/v2-7-1-validation-report.md`**, **`docs/missions/v2-7-1-finishing-report.md`**.

### Fixed

- **Editor**: inspector config updates (**`setSelectedConfig`** and related) now replace nodes immutably and call **`setNodes`**, so **Vue Flow** propagates **`data.config`** to **`KnotNode`** (canvas SM hints stay in sync).
- **i18n (Vue)**: **`dolibarrObject.idPlaceholder`** no longer embeds `{{…}}` inside vue-i18n messages (message compiler error **Not allowed nest placeholder**), which previously emptied the Dolibarr object form tab for operations where **ID** is shown (e.g. **`change_status`**).
- **Inspector**: **`NodeInspectorBody`** forces the **form** tab synchronously when focusing SM hints; editor inspector **`aside`** exposes **`data-knot-test="knot-inspector-aside"`** for E2E.
- **Dolibarr object inspector**: clarify **discovery vs discovery expert** registry labels (same object list; expert changes schema/validation UX) and show an inline hint under the registry filter.

## [2.7.0] - 2026-05-03

### Added

- **Hybrid Dolibarr state machine** (`Knot\StateMachine\*`): `StateMachineEngineInterface`, L1 `StateExtractor` (`STATUS_*` / `STATUT_*`), L2 `TransitionDetector` (wraps `VerbDiscoverer`), L3 `RuntimeValidator` + file cache under `DOL_DATA_ROOT/knot/state-machine/{DOL_VERSION}/`, `TransitionProbability` UX hints.
- **API**: **`GET/POST /custom/knot/api/state_machine.php`** (`states`, `transitions`, `probable_transitions`, `current`, `transition` with CSRF + `workflow execute` for mutations).
- **Connector bridge**: `ObjectAction::changeStatus` delegates to `RuntimeValidator`; dry-run `simulate()` enriches `change_status` with probable transitions metadata.
- **Schema compatibility toolchain**: `Knot\Compatibility\Versioning\*` (`SchemaSnapshotter`, `SchemaComparator`, `BreakingChangeDetector`, `MigrationReportGenerator`, `WorkflowImpactAnalyzer`), bundled samples under **`data/compatibility/snapshots/`**, CLI helpers (**`scripts/generate-schema-snapshot.php`**, **`compare-schema-snapshots.php`**, **`analyze-workflow-impact.php`**, **`check-workflow-compatibility.php`**).
- **API**: **`/custom/knot/api/compatibility.php`** (`snapshot_live`, `sample`, POST `diff`, POST `snapshot_save`).
- **Vue**: **`CompatibilityView`** (`?mode=compatibility`), inspector Dolibarr object hints for **`change_status`**, **`knotApi`** helpers for SM + compatibility.
- **Capabilities**: pilot **`states_known`**, `features.state_machine_formal`, `schema_versioning`, `ground_truth_check`, clarified **`ground_truth.semantic`**.
- **Tests**: PHPUnit **`tests/StateMachine/`**, **`tests/Compatibility/Versioning/`**; Playwright **`tests/e2e/specs/compatibility.spec.ts`**.
- **Documentation**: ADR **010–012**, **`docs/state-machine/`**, **`docs/compatibility/`**, missions **`docs/missions/v2-7-*`**, **`docs/missions/v2-8-preparation.md`**.

## [2.6.1] - 2026-05-03

### Added

- **Execution failures JSON**: nullable **`error_payload`** on **`llx_knot_execution`** (Migrator **v2.6.1**) populated by **`CronWorker`** via **`ExecutionErrorPayloadCodec`** (bounded TEXT-safe payloads).
- **API**: **`GET /custom/knot/api/executions.php?id=`** returns **`execution.errorPayload`** when present for the Vue execution detail view.
- **Vue**: **`ExecutionErrorPanel`** renders Knot **`error.details.knot`** (simulate toast + execution detail); safe clickable **`doc_link`** filtering; **`ExecutionErrorTranslator`** helpers (**`extractKnotPayloadFromUnknown`**, **`translateExecutionError`**).

### Documentation

- **`docs/ux/error-display.md`**, **`docs/public-docs-convention.md`**, expanded **`docs/architecture/state-machine-design.md`**, **`scripts/check-error-catalog-slugs.php`**, **`README.md`** documentation links row.

## [2.6.0] - 2026-05-03

### Added

- **Architecture foundations (V2.6 mission)** : ADRs **007–009**, normalized errors integration, `api/capabilities.php` + Vue **Capabilities**, workflow bulk **`import_precheck`**, MySQL queue migration (**priority**, **scheduled_at**, **retry/backoff**, **worker_id**) wired through **`CronWorker`**, queue dashboard API + Vue **`?mode=queue`**, operator docs under **`docs/queue/`**, **`docs/api/capabilities.md`**, mission recap **`docs/missions/v2-6-foundations-report.md`**, **`StateMachineEngine`** stub + **`docs/architecture/state-machine-design.md`**. Commercial Dolibarr Store readiness explicitly remains **V3+** (`dolistore_licensing_ready: false` in capabilities).

## Unreleased

### Added

- **Executions UI**: unified **History** and **Queue & retries** tabs under `?mode=executions`; optional `execution_tab=queue`; workflow labels on list/detail via JOIN; queue dashboard adds **`queuedByWorkflow`**; client-side search on history page and queue tables; **`?mode=queue`** remains as alias (sidebar queue entry removed).
- **PHPUnit:** **`ExecutionRepositoryQueueAggregationTest`** covers queue dashboard row mapping (**`topRetryRows`**, **`queuedAggregatedByWorkflow`**).
- **PHPUnit:** **`ExecutionRepositoryErrorPayloadTest`** covers **`error_payload`** decoding on **`fetchOne`** and SQL persistence in **`recordFailureAndScheduleRetry`**.
- **Documentation:** **`docs/security.md`** documents the access boundary for **`execution.errorPayload`** on **`GET /custom/knot/api/executions.php`** (same session, **`knot` workflow read**, entity scope).

- **Inspector schema-driven forms:** `EditorView` loads connector descriptors once at setup (shared cache with Connectors/Credentials), resolves `configSchema` per selected node type (`resolveConnectorSchema.ts`), and passes it to `DynamicForm` for nodes without a dedicated panel. Until the catalog request settles, the Form tab shows a short **loading** line instead of a false “no schema” message. `DynamicForm` supports generic **array-of-objects repeaters** (`x-knot-array-editor`, `minItems`) and a clearer empty-schema message for triggers like manual with no fields. Palette: **`notification.alert`** under **Alerts**. Expanded `getConfigSchema()` metadata (titles, `x-position`, enums) across Core logic/Dolibarr/email paths in scope. Playwright **`inspector-dynamic-form.spec.ts`** stubs `connectors.php` with minimal JSON so CI can assert `logic.if` inspector wiring without depending on the full live connector catalog.

- **Dolibarr object — expert registry & schema :** `objectRegistryMode` persisted on `dolibarr.object` nodes (default `all_except_unverified`), optional `discovery_unverified` mode with `field_view=full` from `api/dolibarr_schemas.php`, widened payload validation, optional `statusMethodCustom` for `change_status`; auto-widen registry when a discovery-only slug would be hidden; Vue i18n for the inspector.
- **Connectors screen :** tab **New connector** (MVP helper with non-official warning, schema copy, node template) and deep-link `?tab=builder`.

### Fixed

- **`logic.stop_error`:** throws **`ExecutionError`** with **`KNOT_EXECUTION_FAILED`** (catalog-aligned) instead of a bare **`RuntimeException`** translated as **`KNOT_DOLIBARR_UNEXPECTED`**. Sync **`execute`** responds **HTTP 400** with structured **`error.details.knot`** — intentional stops are domain faults, not **500** server errors. Playwright **MISSION-15** / **pme-week** controlled-failure expectations updated accordingly.

- **Workflow Assistant :** `assistant.php` accepte un **POST** `application/json` (`{ "action": "prompt", "userRequest": "..." }`) pour éviter les URLs GET longues / faux positifs WAF (réponse **403** avec HTML → *invalid JSON* côté client). Le client Vue utilise désormais POST + en-tête CSRF. Message d’erreur un peu plus explicite quand le corps est du HTML « Forbidden ».

- **Setup — engine health counters:** split `KnotSetupHealthCounters` into five single-placeholder keys (`KnotSetupHealthQueued` … `KnotSetupHealthTimeouts`) and compose the line in PHP. Prevents `Translate::trans` / `sprintf` `ArgumentCountError` when a DB translation override (`llx_overwrite_trans`) mismatches placeholder count (e.g. demo white-screen mid-setup and missing **Run health** form).

### Documentation

- **`docs/testing/demo-verification-last-run.md`** : campagne **2026-05-03** (deploy démo, migration **`error_payload`**, suites E2E complètes + REAL-KNOT).
- **Playwright** : **`error-display-rich.spec.ts`** — **`.first()`** sur libellé MISSION‑15 (évite **strict mode** si para + `pre` dupliquent le texte).
- **`docs/roadmap.md`**, **`docs/dolistore-licensing.md`** : alignés sur le **module `2.6.1`** — chaîne licence **`dolistore` livrée côté Core**, **vagues A/B monétisation**, flag **`dolistore_licensing_ready`**, report **readiness vitrine Store V3+** (`CHANGELOG` `[2.6.0]`).

- **API référence :** [`docs/api-reference.md`](docs/api-reference.md) — section **Assistant workflow** (`assistant.php` POST JSON + GET), en plus de **dolibarr_schemas** (`field_view`), **[`docs/introspection.md`](docs/introspection.md)**, **[`docs/connectors.md`](docs/connectors.md)**, **[`docs/security.md`](docs/security.md)** (déjà alignés au livrable expert + builder).

- **Core vs Pro Pack:** aligned [`docs/extensibility.md`](docs/extensibility.md), [`docs/connectors.md`](docs/connectors.md), [`docs/roadmap.md`](docs/roadmap.md) (semver **`2.6.x`**, monétisation vagues A/B + client **`dolistore`** en code, migration sans rewrite JSON, ~37 Core / 20 ids migrés), [`README.md`](README.md) connecteurs, [`docs/api-reference.md`](docs/api-reference.md) + OpenAPI pointer, [`docs/beta-testers/README.md`](docs/beta-testers/README.md). Canonical migrated ids: `ConnectorMigration::MIGRATED_TO_PRO_PACK` (fixed to match `#[Connector]` metadata).

### Changed

- **Testing / QA assurance:** refreshed [`docs/testing/coverage-gaps-pre-beta.md`](docs/testing/coverage-gaps-pre-beta.md) with a **2026-05-02** “garantie QA” matrix (PHPUnit, Vitest, Playwright projects, REAL-KNOT), tightened CRUD/transition/dashboard estimates after **`demo-erp-pack`** and full **`pme-week`** on demo, and aligned [`docs/testing.md`](docs/testing.md) + demo verification journal cross-links.

- **Dolibarr 21 compatibility snapshot:** regenerated `data/compatibility/dolibarr-catalog.json`, `dolibarr-classes-full-inventory.json`, `dolibarr-classes-classification.json`, `docs/compatibility/_generated_audit_tables.inc.md`, and `docs/compatibility/dolibarr-crud-slug-matrix.md` from **upstream Dolibarr 21.0.4** sources (`export_dolibarr_audit_tables.php`, `scan-all-dolibarr-classes.php`, slug matrix generator). Updated `demo-knot-tools-coverage-status.json` baseline (405 class files scanned, **106** introspection descriptors, **core only** unless `KNOT_SCAN_INCLUDE_CUSTOM=1`).

- **`scan-all-dolibarr-classes.php`:** JSON payloads persist **`dol_document_root_basename`** instead of an absolute **`dolDocumentRootResolved`** path so Tier-1 trees stay free of operator workstation prefixes.

- **Phase 4 pre-beta:** expanded **`docs/testing/demo-validation-matrix-phase4.md`** with a Dolibarr artefact regeneration checklist (§4), additional gates (**`coverage-gates`**, **`P4-COMPAT-REGEN`**, **`P4-COMPAT-GOLD`**), and concrete transition-grid examples ; PHPUnit totals aligned to **530** in Phase 4, **`coverage-audit-pre-beta.md`**, **`docs/testing.md`**, and **`demo-verification-last-run.md`**.

- **Dolibarr introspection scanning:** enforced `SKIP_DIRS` on nested module subtrees (`cache`, `cron`, …) during `ObjectIntrospector::scan()` (module roots stay intact); `export_dolibarr_audit_tables.php` accepts absolute `--write-catalog` filesystem paths alongside relative Knot-core paths.

- **OpenAPI** : `composer run docs:openapi` régénéré (spec YAML/JSON).

### Added

- **Setup (admin UX préexistant + cron visibility):** même action manuelle **Run health check** (`Knot\Engine\HealthWorker`, CSRF) ; traductions **`KnotHealthWorkerRun*`** et **`KnotSetupHealth*`** ; dashboard cron **`KnotHealthWorker`**, **`CRON_DISABLE_JOBS`**, **`data-knot-health-run`** pour Playwright.

- **Pré-bêta — critères de sortie Core** : [`docs/testing/core-beta-exit-criteria.md`](docs/testing/core-beta-exit-criteria.md) (PHPUnit, Vitest, build, E2E optionnel) ; renvoi depuis [`docs/testing/pre-beta-readiness-phase5.md`](docs/testing/pre-beta-readiness-phase5.md) et [`docs/testing/coverage-gaps-pre-beta.md`](docs/testing/coverage-gaps-pre-beta.md).

- **Santé / observabilité** : `api/health.php` expose `failureHeatmap` et `failureHeatmapSince` (agrégats erreur/timeout 7 j via `MetricsCollector::failureHeatmap`) ; tableau de bord Vue heatmap + i18n (`dashboard.*` dans les 6 locales) ; [`docs/observability.md`](docs/observability.md) aligné (fin de l’alias « heatmap à finaliser »).

- **Setup** : action **Invalider le cache licence** (CSRF, admin) pour extensions `license.validation = dolistore` ; chaînes `KnotLicenseCache*` (FR/EN) ; doc [`docs/deployment.md`](docs/deployment.md), [`docs/admin-guide.md`](docs/admin-guide.md) § 1.1.

- **Assistant migration Pro Pack** : libellés via `vue-i18n` (`migration.*`, FR/EN + chaînes miroir EN pour DE/ES/IT/PT).

- **Tests** : `ConnectorMigrationTest::testMigratedListHasExactlyTwentyUniqueIds` (garde-fou effectif 20 ids uniques).

### Documentation

- **`api/health.php`:** Doctor JSON merges **`cron.healthWorker`** alongside the legacy first-row Knot cron summary for tooling parity.

- **E2E — setup health Worker:** `tests/e2e/specs/setup-health-worker.spec.ts` (`@demo-health`): POST Setup health when credentials are present (skip on placeholders); inline **`dolibarrLoginSession`** on login wall; locator prefers **`data-knot-health-run`**, falls back to **`health_worker_run`** hidden action for demos not yet on latest bundle.

- **Doctor + Dolibarr object registry UX:** `health.php` Doctor check `introspection_cache`, JSON card exposing cache path and descriptor totals; inspector MAP versus discovery badges, empty-cache banner, registry filter (shared `descriptorCache` + `fromMap` on `dolibarr_schemas.php?list=` / refresh); consolidated workflow trigger catalogue for `DolibarrEventPanel` (`dolibarrWorkflowTriggers.ts`); PHPUnit `DolibarrCatalogScanGoldenTest` plus nested `SKIP_DIRS` coverage; refreshed portable `dolibarr-catalog.json` from Docker Dolibarr **21** for this drop.

- **Documentation compatibility:** [`docs/compatibility/architecture-generic-dolibarr-connector.md`](docs/compatibility/architecture-generic-dolibarr-connector.md) documents the single **`dolibarr.object`** surface vs mass-generated Connector classes; linked from [`docs/extensibility.md`](docs/extensibility.md), [`docs/compatibility/README.md`](docs/compatibility/README.md), and [`docs/compatibility/connector-generation-decision.md`](docs/compatibility/connector-generation-decision.md). Regenerable **[`docs/compatibility/dolibarr-crud-slug-matrix.md`](docs/compatibility/dolibarr-crud-slug-matrix.md)** (`scripts/compatibility/generate_dolibarr_crud_slug_matrix.php`) documents CRUD × slug for curated **`ObjectFactory::MAP`** rows.

- **Strategic maximal Dolibarr coverage mission:** curated slug **`expedition`** + `ExpeditionLigne` in `ObjectFactory::MAP`; `ObjectAction::addLines()` bypasses Dolibarr non-invoice-shaped **`Expedition::addline`** arity via **`ExpeditionLigne::create()`** fallback (`fk_expedition`); inventory CLI `scripts/scan-all-dolibarr-classes.php` + `data/compatibility/dolibarr-classes-full-inventory.json` / `dolibarr-classes-classification.json` / `demo-knot-tools-coverage-status.json`; docs (**`architecture-genericity-audit.md`**, **`dolibarr-coverage.md`**, **`extending-knot.md`**, **`auto-update-system-report.md`**, **`connectors-generation-report.md`**, **`strategic-coverage-mission-report.md`**), testing matrix **`full-coverage-test-report.md`**; nightly **`.github/workflows/dolibarr-coverage-monitoring.yml`**; **`--print-catalog-counts`** flag on **`check_dolibarr_version.php`**; Playwright **`coverage-gates`** + **`npm run test:demo:coverage-gates`** (opt-in `KNOT_COVERAGE_SCHEMAS_FETCH=1`, `KNOT_SCALE_PROBE=1`, shipments REST probe).

- **Demo ERP pack (API + playbooks, sans UI Dolibarr):** fixtures `tests/fixtures/workflows/demo-erp-pack/*.knot.json` (+ generator `scripts/generate-demo-erp-pack-workflows.php`); Playwright project **`demo-erp-pack`** (`tests/e2e/scenarios/demo-erp-pack.spec.ts`, `npm run test:demo:erp-pack`); playbook `tests/e2e/playbooks/playbook-demo-erp.spec.ts` (idempotence segments COMM/PAY, MySQL gate); helpers `tests/e2e/helpers/demo-erp-{rest,sql}.ts`; PHPUnit `DemoErpPackWorkflowFixturesTest`; Vitest `frontend/src/components/inspector/__tests__/NodeInspectorBody.test.ts` (inspector tabs G-P2-05 partial); spec [`docs/testing/demo-erp-pack-spec.md`](docs/testing/demo-erp-pack-spec.md); cross-link in [`docs/testing/coverage-gaps-pre-beta.md`](docs/testing/coverage-gaps-pre-beta.md) and journal [`docs/testing/demo-verification-last-run.md`](docs/testing/demo-verification-last-run.md).

- **Pre-beta coverage Phase 3:** Playwright spec `tests/e2e/specs/beta-critical-erp-rest.spec.ts` (`@beta-critical`) — Dolibarr REST thirdparty create/read with Unicode/apostrophe payload (skipped without E2E session or valid `DOLIBARR_API_KEY`); helper `dolibarrGetThirdparty()` in `tests/e2e/helpers/pme-week-rest.ts`; operator script `scripts/demo_knot_vm_activate_mission_workflows_db.sh` (opt-in `MISSION_ACTIVATE_CONFIRM=1`, activates `MISSION-*` except `MISSION-15%`). Docs: [`docs/testing/new-tests-implemented.md`](docs/testing/new-tests-implemented.md), [`docs/testing/beta-testers-faq.md`](docs/testing/beta-testers-faq.md); `tests/e2e/.env.example` and [`docs/runbooks/demo-dolibarr/post-deploy-checklist.md`](docs/runbooks/demo-dolibarr/post-deploy-checklist.md) updated.

- **PME-week Playwright scaffold + cron helper:** fixes under `tests/fixtures/workflows/pme-week/` (`thirdparty` slug, import label prefix **`PMEw3-WEEK-*`** to refresh definitions on demos), PHPUnit `PmeWeekWorkflowFixturesTest`, Playwright scenario `tests/e2e/scenarios/pme-week-realistic.spec.ts` resolving fixtures via `tests/fixtures/`, helpers `tests/e2e/helpers/pme-week-{knot,rest,sql}.ts` (**`hasConfiguredDolibarrApiKey()`** ignores `replace_me` placeholders; REST coverage for **`/proposals`** and **`/invoices`** create/line/validate used by gated scenarios **`02b`**/**`02c`** for **PMW-C**/**PMW-D**), Markdown report under `tests/e2e/test-results/pme-week-report.md`, optional **`api/knot_cron_tick.php`**, **`knotCronTick()`** gracefully falls back to **`executions.php?action=run_now`** FIFO draining when cron tick PHP is absent, documented in `docs/testing/pme-week-e2e-*.md` and `docs/testing.md`; manual replay via **`KNOT_PME_MANUAL_SOC_ID`** when REST is disabled.

- **Dolibarr compatibility (MAP + V2.4 scan):** docs [`docs/compatibility/README.md`](docs/compatibility/README.md) and [`docs/compatibility/dolibarr-coverage-audit.md`](docs/compatibility/dolibarr-coverage-audit.md), regenerable annex [`docs/compatibility/_generated_audit_tables.inc.md`](docs/compatibility/_generated_audit_tables.inc.md), portable catalog [`data/compatibility/dolibarr-catalog.json`](data/compatibility/dolibarr-catalog.json); scripts [`scripts/compatibility/export_dolibarr_audit_tables.php`](scripts/compatibility/export_dolibarr_audit_tables.php), [`scripts/compatibility/check_dolibarr_version.php`](scripts/compatibility/check_dolibarr_version.php); classes `Knot\Compatibility\DolibarrCatalogGenerator`, `Knot\Dolibarr\ExtrafieldsSchema` (create/update schemas merge `llx_extrafields` when running under Dolibarr); `ObjectFactory::describe()` reports the built object short class name (works for descriptor-only slugs); `ObjectFactory::getVersionHash()` appends persisted introspection cache hash; PHPUnit `ObjectFactoryMapInventoryTest`, `ExtrafieldsSchemaTest`, `DolibarrCatalogGeneratorTest`, optional `ObjectIntrospectorGoldenTest` (`KNOT_COMPAT_DOL_ROOT`).
- **Internal mission (prod-grade):** Dolibarr seed CLI `scripts/seed-realistic-data.php` + helpers `scripts/lib/RealisticSeed/`; mission workflow fixtures `tests/fixtures/workflows/mission-internal/` (generator `scripts/generate-mission-internal-workflows.php`, bulk import `scripts/mission-internal-import-workflows.php`); Playwright project `mission-internal` bound to `tests/e2e/scenarios/mission-internal.spec.ts`; optional `KNOT_E2E_MYSQL_REMOTE=1` runs read-only SELECT probes via `scripts/demo_knot_vm_e2e_mysql_select.sh` (SSH); `tests/e2e/load-env.ts` preloads `demo/seed.env` / `demo/knot-e2e.env` before Playwright; expression builtin `{{ uniqid }}` for collision-resistant refs within the same second; stress checklists `scripts/stress/*.sh`; reports under `docs/testing/mission-internal-phase*.md`; PHPUnit coverage for seed helpers and mission fixtures (`tests/MissionInternal/`, `tests/Unit/RealisticSeedHelpersTest.php`).
- Starter example **`examples/starter/06-showcase-power-logic.knot.json`**: mono-tenant logic showcase (manual trigger, guarded empty cart, `logic.switch`, nested `logic.if`, `logic.loop` with `realIteration` and `$loop.item` — no HTTP/email); PHPUnit **`ShowcaseStarter06WorkflowEngineTest`**; Playwright **`tests/e2e/specs/showcase-starter-06.spec.ts`** (import via `workflows.php` + sync `execute.php`, asserts five `routeTag`).
- **GitHub Actions:** `.github/workflows/mission-docker-smoke.yml` spins up `docker/docker-compose.yml`, enables Knot, imports mission fixtures under `SEED_REALISTIC_QUICK=1`, runs a PHPUnit slice inside the Dolibarr container, then Playwright project `mission-internal`.
- **Demo reset (operator):** `scripts/demo_dolibarr_reset_demo_full_test_pack.php` + `scripts/demo_knot_vm_reset_demo_full_test.sh` — purge all `queued` Knot executions, drop `REAL-KNOT-*` / `PMEw3-WEEK*` / `QA-TST-*` / legacy `PME-WEEK-*` workflows (per `DEMO_RESET_ENTITIES`, default `1`), re-import E2E fixtures from `tests/fixtures/workflows/pme-week/`, activate all except `WEEK-FAIL`, sync cron schedules, then `REAL_KNOT_REPLACE=1` re-seed via `remote_dolibarr_seed_knot_real_world.php`. Requires `DEMO_RESET_PURGE_CONFIRM=1`.

### Fixed

- **Frontend build:** `NodeInspectorBody.test.ts` queries the JSON textarea via `find('textarea.k-font-mono')` so `vue-tsc` no longer blocks `npm run build` on optional `WrapperArray#at(0)` typing.

- **Playwright `dolibarr-fetch-fields-coverage`:** reads Dolibarr schema payloads from Knot’s **`success.data`** envelope and tolerates **`fetch`** responses with empty **`properties`** when **`x-version-hash`** / **`x-knot-object`** are present (`tests/e2e/specs/dolibarr-fetch-fields-coverage.spec.ts`), so **`npm run test:demo:coverage-gates`** with **`KNOT_COVERAGE_SCHEMAS_FETCH=1`** matches production API behaviour.

- **E2E Dolibarr REST helpers:** central `dolibarrApiUrl()` + optional `DOLIBARR_API_INDEX_BASE` (`tests/e2e/helpers/dolibarr-api-url.ts`) so `demo-erp-rest`, `pme-week-rest`, and the DELIV **`/shipments`** probe hit the correct router when the ERP is served from a subdirectory. **`demo-erp-deliv-shipments-gate.spec.ts`** uses **`dolibarrGetShipments`**, skips on HTML/login walls and Restler **`error`** JSON instead of flaky hard-fails, and accepts list or **`{ data: [...] }`** pagination envelopes.

- **`dolibarr.object` `ObjectAction`:** `ensurePermission` hydrates Dolibarr user rights once (`loadRights` when `all_permissions_are_loaded` is unset/false), maps `change_status` on slug **facture** / **validate** to the same rules as Dolibarr 21 `Facture::validate` (basic **`creer`** vs advanced **`invoice_advance` / `validate`**), supports three-argument `User::hasRight($module, $p1, $p2)` and EN verb synonyms; **`FakeUser`** stub accepts the optional third parameter for PHPUnit.

- **`purge_demo_coverage_seed.php`:** bootstrap via `master.inc.php` and `DOLIBARR_DOCUMENT_ROOT` (infer docroot from deployed `custom/knot`); cascade delete related rows; restrict to entity `1` by default (`COVERAGE_PURGE_ENTITIES=all` to span all entities); require `knot.workflow.write` like other demo CLIs.

- **Dolibarr Object `fetch` / dry-run preview:** output now includes every introspected `$object->fields` column, `array_options` extrafields, and `lines` snapshots from each line `$fields` map (FKs such as `fk_soc` read via aliases like `socid`). Canonical shortcuts `id`, `ref`, `label`, `status` stay merged; password-like field definitions are omitted.

- **E2E demo-erp-pack:** scenario `demo-erp-pack.spec.ts` passes `socid: socId` to REST invoice/proposal helpers (invalid bare `socid` reference broke the suite before contract/payment segments).

- **Workflow refs:** `WorkflowRepository::generateRef()` allocates the next numeric suffix with `MAX(...)+1` on same-day `KW{Ymd}{entity}-*` refs instead of `COUNT+1`, avoiding duplicate `uk_knot_workflow_ref_entity` after workflow deletes or gaps.
- **Dolibarr SQL Query:** result rows use lowercased column keys so expressions like `{{$json.rows.0.rowid}}` work regardless of driver casing.
- **Mission-internal fixtures / demo:** generator uses `llx_societe.siren` (not `idprof1`); MISSION-04/MISSION-14 prefer `TST-ACME` or `SEED-REAL-*` customers; bulk import logs tier blocks and DB errors on stderr when a workflow cannot be created.
- **Demo REAL-KNOT pack (`QA-KNOT-REAL-v4`):** business document refs use `{{timestamp}}` + `{{uniqid}}` in workflow payloads so `./scripts/demo_knot_vm_smoke_real_knot.sh` can run repeatedly without `ErrorRefAlreadyExists`. Upgrading from v3 triggers a one-time purge of stale `REAL-KNOT-*` workflows before re-seed (still use `REAL_KNOT_REPLACE=1` for a full wipe). Smoke script verifies every enqueued execution is `success` and exits non-zero otherwise.

- **E2E Playwright:** `global-setup.ts` logs in when `KNOT_E2E_LOGIN` and `KNOT_E2E_PASSWORD` are set, drops stale `.auth/storage.json` first, writes session to `tests/e2e/.auth/storage.json` (gitignored); `dolibarrLoginSession` validates post-login title and supports `#username`. Specs aligned with current UI (strict-mode `.first()` / roles; Doctor uses status pill; setup uses `toBeAttached` + value on hidden CSRF `input[name=token]`; Templates uses `mode=marketplace&tab=templates`). `findWorkflowIdByLabelPrefix` surfaces non-JSON API responses clearly.
- **E2E mission-internal:** scenarios `test.skip` when mission workflows are absent on the instance (import optional).
- **Licensing / PHP 8.5:** `DolistoreClient` avoids deprecated `curl_close()` (no-op since PHP 8.0). Stream fallback on PHP 8.4+ uses `http_get_last_response_headers()` only; PHP ≤8.3 magic `$http_response_header` handling is isolated in `DolistoreClientStreamLegacy.php` so it is not loaded on modern runtimes (PHP 8.5 deprecates the magic variable even in uncalled methods in the same compilation unit).
- **Executions / Dashboard:** aggregate `counts.failed` now matches failed-looking runs — `ExecutionRepository::statusCounts()` adds a derived `failed` counter (`error` + `timeout`) because the UI and health snapshot expected `failed` while the database uses `error` (and `timeout`).
- **Setup Terminer:** restore `TemplateRepository::seed()` and `seedDemoWorkflows()` after the template-table refactor.
- **Setup `engine_on`:** full `$runKnotGoLive` (same as **Terminer**) only when `KNOT_SETUP_COMPLETED` was still off; otherwise **`$runKnotGoLiveLight`** (missing tables + migrator + cron only — no marketplace template fetch, menu rebuild, or introspection scan). Setup button label: **KnotEngineActivateFirst** until setup is done, then **KnotEngineResume** (*Start engine* / *Demarrer le moteur*); **preview.php** keeps *Start engine* for the setup link. Post **engine_on** / **engine_off** redirect keeps `?admin=1`.

### Documentation

- **`docs/testing.md`** (§ E2E Playwright — smoke setup santé) et **`docs/operations.md`** § 2 : KnotHealthWorker dans **`llx_cronjob`**, **`CRON_DISABLE_JOBS`**, snapshot **`KNOT_HEALTH_*`** vs horodats cron, redirections **`?admin=1`**, gates E2E setup santé.

- **Debt (i18n phase 2 — hors bloc santé moteur):** titres/long textes de la section introspection Setup et **`setEventMessages`** des autres actions (cron test, backups, seed, introspection automatique GET, marketplace preview, …) à traiter dans une passe suivante.

- **Journal vérification démo :** [`docs/testing/demo-verification-last-run.md`](docs/testing/demo-verification-last-run.md) enregistre la passe **2026-05-02** (PHPUnit, Vitest, Playwright projets `chromium` / `mission-internal` / `mission-playbook-deep` / `pme-week`, REAL-KNOT SSH) — aucun secret dans le fichier ; pointeurs depuis [`docs/testing.md`](docs/testing.md) et [`coverage-audit-pre-beta.md`](docs/testing/coverage-audit-pre-beta.md).
- **Pré-bêta Phase 5:** [`docs/testing/pre-beta-readiness-phase5.md`](docs/testing/pre-beta-readiness-phase5.md) (GO/NO-GO, index phases 1–5), [`docs/testing/ci-test-matrix-phase5.md`](docs/testing/ci-test-matrix-phase5.md) (workflows GH vs équivalents locaux ; hors CI : démo, pme-week) ; croisés dans [`coverage-audit-pre-beta.md`](docs/testing/coverage-audit-pre-beta.md), [`coverage-gaps-pre-beta.md`](docs/testing/coverage-gaps-pre-beta.md), [`demo-validation-matrix-phase4.md`](docs/testing/demo-validation-matrix-phase4.md), [`e2e-flakiness-phase4-report.md`](docs/testing/e2e-flakiness-phase4-report.md), [`new-tests-implemented.md`](docs/testing/new-tests-implemented.md), [`testing.md`](docs/testing.md).
- **Pré-bêta Phase 4:** [`docs/testing/demo-validation-matrix-phase4.md`](docs/testing/demo-validation-matrix-phase4.md) (gates démo reproductibles, template transitions Dolibarr), [`docs/testing/e2e-flakiness-phase4-report.md`](docs/testing/e2e-flakiness-phase4-report.md) (méthode + gabarit flaky Playwright) ; npm `test:demo:chromium:repeat` dans [`tests/e2e/package.json`](tests/e2e/package.json) ; pointeurs dans [`docs/testing.md`](docs/testing.md), [`coverage-audit-pre-beta.md`](docs/testing/coverage-audit-pre-beta.md), [`coverage-gaps-pre-beta.md`](docs/testing/coverage-gaps-pre-beta.md), [`new-tests-implemented.md`](docs/testing/new-tests-implemented.md).
- **Pré-bêta Phase 3:** [`docs/testing/new-tests-implemented.md`](docs/testing/new-tests-implemented.md) (tests ajoutés, comptage Playwright), [`docs/testing/beta-testers-faq.md`](docs/testing/beta-testers-faq.md) (FAQ FR testeurs) ; [`docs/testing.md`](docs/testing.md) § E2E gate REST ; [`docs/testing/coverage-gaps-pre-beta.md`](docs/testing/coverage-gaps-pre-beta.md) pied de page Phase 3.
- **Pré-bêta — audit couverture tests :** [`docs/testing/coverage-audit-pre-beta.md`](docs/testing/coverage-audit-pre-beta.md) (suites PHPUnit/Playwright/Vitest + état SQL `demo.knot.tools` + grille personas mono-entité) ; **manques priorisés :** [`docs/testing/coverage-gaps-pre-beta.md`](docs/testing/coverage-gaps-pre-beta.md) ; liens depuis [`docs/testing.md`](docs/testing.md) § Audit.
- **[post-deploy-checklist.md](docs/runbooks/demo-dolibarr/post-deploy-checklist.md):** clarify realistic seed volumes (full `seed-realistic-data.php` vs `SEED_REALISTIC_QUICK` / golden dumps) vs mission-style row counts; PME-week Playwright operator alignment (`DOLIBARR_API_KEY`, MySQL probes, `KNOT_PME_FULL`, Knot deploy including `api/knot_cron_tick.php` vs executions drain). **[pme-week-e2e-prerequisites-audit.md](docs/testing/pme-week-e2e-prerequisites-audit.md)** §3 links `RealisticDolibarrSeeder::resolveCounts()`.
- Add operator runbooks for **Dolibarr 21.0.2 demo** instance (`demo.knot.tools`): [docs/runbooks/demo-dolibarr/](docs/runbooks/demo-dolibarr/README.md), entry point [docs/runbooks/demo-instance.md](docs/runbooks/demo-instance.md). Local secrets and dumps under **`core/demo/`** (gitignored).
- Document **demo.knot.tools** SSH `Host demo-knot-vm`, Plesk system user, and demo `remote-commands.sh` template in [docs/runbooks/ssh-access.md](docs/runbooks/ssh-access.md).
- Refresh **demo.knot.tools** SSH public key line in [docs/runbooks/ssh-access.md](docs/runbooks/ssh-access.md) after operator key regeneration.
- Extend Let's Encrypt runbook with **openssl issuer** check (detect Plesk default cert vs LE).
- Add **demo.knot.tools** operator shell helpers under `scripts/demo_knot_vm_*.sh` (SSH/TLS gates, remote inventory, Dolibarr 21.0.2 deploy, Knot rsync, golden dump template); documented in [docs/runbooks/demo-dolibarr/README.md](docs/runbooks/demo-dolibarr/README.md). Helpers may load `demo/seed.env` / `demo/ssh_operator.env` and optionally use `sshpass` when `DEMO_KNOT_SSH_PASSWORD` is set.
- Demo VM: **`remote_dolibarr_bootstrap.php`** (+ `demo_knot_vm_bootstrap_dolibarr.sh`) for module enablement, CRON_KEY, REST API key, Knot_Testers, sample third parties; **`demo_knot_vm_cron_dolibarr.sh`** / **`remote_dolibarr_cron_info.php`** for scheduled-job URL; **`demo_knot_vm_enable_modknot.sh`** / **`remote_enable_modknot.php`** after Knot rsync. Runbooks updated (multicompany tarball caveat, `validate-knot`, golden dump host needs `mysqldump`).
- Demo VM: **`demo_knot_vm_dump_golden_remote.sh`** when MySQL is only reachable from the Plesk host; **`demo_knot_vm_harden_install.sh`** renames Plesk **`index.html`** if it hides Dolibarr **`index.php`**, and **restores `install/mysql`** after renaming the installer so module DDL keeps working.
- Demo VM: **`demo_knot_vm_repair_dolibarr_tables.sh`** when a module is enabled but DDL is missing (e.g. **`llx_ticket`**).
- Demo VM: **`demo_knot_vm_purge_knot.sh`** + **`remote_dolibarr_purge_knot.php`** for a clean Knot uninstall (DB + files); **`demo_knot_vm_harden_install.sh`** now sets **`conf/conf.php`** to **`444`** so the web user cannot overwrite it (Dolibarr security check).
- Packaging: **`package_dolistore.py`** outputs **`build/knot-<version>.zip`** (e.g. **`knot-2.4.2.zip`**) so Dolibarr’s **Deploy external module** upload accepts the filename (numeric version segment required).
- First-run onboarding: completing **admin/setup.php** now redirects to the **dashboard** (`preview.php?mode=dashboard`) so the Vue onboarding wizard can mount; **`KNOT_USER_ADMIN` / onboarding API** also treat **Knot configure** admins as eligible alongside Dolibarr `admin > 0`. **Terminer** in the Vue wizard opens **admin/setup.php** (cron URL, engine). **modKnot** top menu entry points to the **dashboard**; onboarding explains post-setup actions (engine, scheduled jobs, hosting cron URL). **GET /api/onboarding.php** exposes **`cron.webUrl`** / **`cron.userLogin`** only for **admin** or **`knot/admin/configure`** (never leaks `securitykey` to plain workflow readers).

### Changed

- **Setup (`admin/setup.php`):** cron banner distinguishes **no recorded run yet** (info — URL copied ≠ first execution) vs **stale** (warning); cron row uses **MAX(datelastrun)** and requires **all** Knot jobs active; **one-shot auto introspection** (`KNOT_INTROSPECTION_AUTO_AT`) plus dedicated **#knot-introspection** card outside the progress grid; **PHP/ZIP backup** via `Knot\Maintenance\LocalKnotBackup` (no server Python); safer **Terminer** / extension discovery (try/catch); **engine** toggle syncs `$conf->global` for the current request; **#knot-engine-card** anchor for banners; bottom **CTA** shows **engine paused** copy (orange) when setup is done but `KNOT_ENGINE_ENABLED` is off, with a shortcut to **#knot-engine-card**.
- **Onboarding (Vue):** **eight steps** with step labels — *Mise en route* (parcours + rappel **PHP / extensions**) → **Prérequis** → **Tâches Knot** → **Cron** (bloc « passage du cron » sous URL / SSH) → *Permissions* → *Chiffrement* → *SMTP* → *Templates* ; fin du wizard : **top window** vers Réglages pour éviter les navigations depuis un **iframe** en erreur.
- **Onboarding API:** **GET** adds **`prerequisites`** (`dolibarrCronModule`, `phpExtensionsOk`, `phpExtensionsMissing`, `cronKeyConfigured`).
- **Demo deploy:** `demo_knot_vm_deploy_knot.sh` **exclut `vendor/`** ; le runtime Knot sur la VM démo ne repose pas sur une étape d’installation PHP additionnelle après rsync.
- **Module picto:** add **`img/knot.png`** for `picto = knot@knot` (module list + top menu). Previously the expected file was missing, so Dolibarr fell back to a generic icon.
- **Setup gear:** when **Vue first-run onboarding** is not finished (`KNOT_FIRSTRUN_COMPLETED`), opening **`admin/setup.php`** without **`?admin=1`** redirects to the **dashboard** (onboarding only mounts on `preview.php`) — **including before** the PHP wizard **Terminer** (`KNOT_SETUP_COMPLETED` may still be 0). The Knot sidebar **Setup** entry uses **`setup.php?admin=1`** to force the full admin screen.

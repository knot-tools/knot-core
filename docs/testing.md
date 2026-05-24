# Strategie De Tests Knot

## Audit Existant

- **Compatibilité Dolibarr 20+** : job CI **`dolibarr-matrix`** ([`.github/workflows/ci.yml`](../.github/workflows/ci.yml)), smoke **[`scripts/ci/docker_mission_smoke.sh`](../scripts/ci/docker_mission_smoke.sh)**, variables **`KNOT_DOLIBARR_DOCKER_IMAGE`** / **`KNOT_DOLIBARR_IMAGE`**, golden optionnel **`KNOT_COMPAT_DOL_ROOT`** ; artefacts Playwright → **[`test-playwright/README.md`](../../../test-playwright/README.md)** ; guide détaillé (local) → **`test-playwright/docs/dolibarr-compatibility-verification.md`**.
- **Audit couverture pré-bêta (Phase 1, 2026-05)** : inventaire des suites, état `demo.knot.tools`, cartographie personas — [`docs/testing/coverage-audit-pre-beta.md`](testing/coverage-audit-pre-beta.md).
- **Manques de couverture (Phase 2)** + **synthèse garantie QA** (révision périodique : matrice démo, couches PHPUnit / E2E / playbooks, pourcentages pilotage — **2026-05-02**) — [`docs/testing/coverage-gaps-pre-beta.md`](testing/coverage-gaps-pre-beta.md).
- **Phase 3 (impl. tests critiques + FAQ)** — [`docs/testing/new-tests-implemented.md`](testing/new-tests-implemented.md), [`docs/testing/beta-testers-faq.md`](testing/beta-testers-faq.md).
- **Phase 4 (matrice validation démo + flakiness E2E)** — [`docs/testing/demo-validation-matrix-phase4.md`](testing/demo-validation-matrix-phase4.md), [`docs/testing/e2e-flakiness-phase4-report.md`](testing/e2e-flakiness-phase4-report.md) ; sondes répétées : `cd tests/e2e && npm run test:demo:chromium:repeat`.
- **Phase 5 (clôture audit pré-bêta, GO/NO-GO, CI)** — [`docs/testing/pre-beta-readiness-phase5.md`](testing/pre-beta-readiness-phase5.md), [`docs/testing/ci-test-matrix-phase5.md`](testing/ci-test-matrix-phase5.md).
- **Journal vérification démo** — [`docs/testing/demo-verification-last-run.md`](docs/testing/demo-verification-last-run.md) (résultats agrégés **sans secrets** ; compléter après chaque campagne E2E/REAL-KNOT sur la démo).

Le repo historique contient :

- `tests/TestRunner.class.php` : runner custom utile comme reference comportementale.
- `admin/run_tests.php` : interface admin de lancement tests.
- `tests/integration_test.php` : integration avec vraie instance Dolibarr.
- `tests/api_test_runner.php` : runner API avec rapports.
- `tests/fixtures/test_data.json` : donnees de test.

Ces fichiers ne deviennent pas la strategie finale, mais inspirent les scenarios.

## Objectifs

- Couverture moteur minimum 80%
- Couverture connecteurs critiques minimum 70%
- Tests reproductibles localement et en CI
- Tests securite sur credentials, sandbox, webhooks, SSRF, CSRF
- E2E sur parcours client : setup wizard, editeur, execution, logs

## Pyramide De Tests

### Unitaires PHP

- `Knot\Engine`
- `Knot\Credentials`
- `Knot\Security`
- `Knot\Connectors`
- Expression engine
- Workflow JSON validator

### Integration PHP

- **Optional golden Dolibarr scan (`KNOT_COMPAT_DOL_ROOT`):** `tests/Dolibarr/ObjectIntrospectorGoldenTest.php` skips in CI unless env points at a local Dolibarr document root. **Interop model:** Knot uses **`dolibarr.object`** (`ObjectAction`) as the unified surface rather than generating one Connector PHP class per Dolibarr class — see [`docs/compatibility/architecture-generic-dolibarr-connector.md`](compatibility/architecture-generic-dolibarr-connector.md).

- **Bundled snapshot ground truth:** `php scripts/ground-truth-check.php --validate-snapshots` validates JSON under `data/compatibility/snapshots/` (excluding `sample-*`). Scheduled workflow `.github/workflows/ground-truth-scheduled.yml`. ADR-016.

- Repositories avec DB test
- Installation SQL
- Cron worker
- API interne
- Multi-entite
- Permissions

### E2E Playwright

- Installation fresh
- Setup wizard complet
- Creation workflow manuel
- Activation workflow
- Execution et consultation logs
- Erreurs credentials
- **V2.8 (`specs/v2-8-smoke.spec.ts`)** — `?mode=observability`, Marketplace onglet **Embarqués**, import **bundled-12-demo-invalid** puis panneau **Problems** (`data-knot-test="knot-problems-panel"`).
- **REAL-KNOT (CLI sur instance Dolibarr)** — Hors Playwright : après déploiement du module sur la VM démo ou un clone CI, [`scripts/demo_knot_vm_run_real_world_seed.sh`](../scripts/demo_knot_vm_run_real_world_seed.sh) + [`scripts/demo_knot_vm_smoke_real_knot.sh`](../scripts/demo_knot_vm_smoke_real_knot.sh) enfilent tous les workflows **manuel** `REAL-KNOT-*` et exigent **`success`** sur chaque exécution enfilée (`remote_dolibarr_smoke_real_knot.php`). Voir [`docs/runbooks/demo-dolibarr/validate-knot.md`](runbooks/demo-dolibarr/validate-knot.md) § REAL-KNOT.
- **Mission playbooks** — projet Playwright `mission-internal` : smoke API sur **MISSION-01** … **MISSION-15** (**MISSION-07** skipped par défaut sur démo mono-entité — définir **`KNOT_E2E_MULTI_ENTITY=1`** pour l’inclure). **MISSION-15** (`logic.stop_error`) attend **HTTP 400**, **`success: false`**, **`error.code` `KNOT_EXECUTION_FAILED`**. Matrice critères **[docs/testing/mission-internal-playbooks.md](testing/mission-internal-playbooks.md)**. Pré-chargement : `tests/e2e/load-env.ts` (+ `.env.example` pour les variables).
- **PME-week (parcours semaine industrielle Dolibarr + Knot)** — projet Playwright `pme-week` (`npm run test:demo:pme-week` dans `tests/e2e/`) ; fixtures `tests/fixtures/workflows/pme-week/` ; prérequis **[docs/testing/pme-week-e2e-prerequisites-audit.md](testing/pme-week-e2e-prerequisites-audit.md)** + décisions **[docs/testing/pme-week-e2e-decisions.md](testing/pme-week-e2e-decisions.md)** ; rapport généré `tests/e2e/test-results/pme-week-report.md`. Le drain de file synchrone passe par **`api/knot_cron_tick.php`** (POST CSRF). Long (`timeout` jusqu’à 30 min / job nightly recommandé) ; variables `DOLIBARR_API_KEY`, `KNOT_PME_FULL`, probes MySQL décrites dans `tests/e2e/.env.example`.
- **Playbooks profonds** — `tests/e2e/playbooks/*.spec.ts` + `tests/e2e/helpers/playbook-db.ts` — comptages via `KNOT_E2E_MYSQL_CLI` (mysql local) ou `KNOT_E2E_MYSQL_REMOTE=1` + `scripts/demo_knot_vm_e2e_mysql_select.sh` (SSH, `demo/seed.env`). Projet Playwright `mission-playbook-deep`.
- **Gates critiques pré-bêta (REST Dolibarr)** — projet Playwright `chromium`, spec `tests/e2e/specs/beta-critical-erp-rest.spec.ts` (tag `@beta-critical`) : création + relecture d’un tiers via API avec nom accentué / apostrophe ; ignoré sans session E2E ou sans **`DOLIBARR_API_KEY`** valide (`tests/e2e/.env.example`). Livrables Phase 3 : [`docs/testing/new-tests-implemented.md`](testing/new-tests-implemented.md), FAQ testeurs [`docs/testing/beta-testers-faq.md`](testing/beta-testers-faq.md).
- **Couverture Dolibarr stratégique (opt-in)** — `KNOT_COVERAGE_SCHEMAS_FETCH=1` (`tests/e2e/specs/dolibarr-fetch-fields-coverage.spec.ts`), `KNOT_SCALE_PROBE=1` (`tests/e2e/specs/scale-pme-realistic.spec.ts`), sonde REST expéditions `tests/e2e/specs/demo-erp-deliv-shipments-gate.spec.ts` ; matrice & limites : [`docs/testing/full-coverage-test-report.md`](testing/full-coverage-test-report.md).
- **Setup — santé moteur (smoke Dolibarr réel)** — `tests/e2e/specs/setup-health-worker.spec.ts` (tag `@demo-health`) : POST sur `setup.php?admin=1`. Cible **`form[data-knot-health-run]`** si présent, sinon formulaire avec **`input[name="action"][value="health_worker_run"]`** (déploiements sans attribut `data-*`). **`test.skip`** si pas de **`KNOT_E2E_LOGIN` / `_PASSWORD`** (aliases **`DEMO_DOLIBARR_ADMIN_*`** via `demo/seed.env`) ou mot de passe **placeholder** (`replace_me`, …). Mur **`#password`** : **`dolibarrLoginSession`** puis nouvelle navigation vers le setup ; prérequis : wizard Knot terminé (**`KNOT_E2E_BASE_URL`** ou **`DOLIBARR_BASE_URL`**).

  ```bash
  cd tests/e2e && npx playwright test specs/setup-health-worker.spec.ts --project=chromium
  ```

  Voir **`tests/e2e/.env.example`**.

### Playwright contre **demo.knot.tools**

Les specs chargent souvent **`demo/seed.env`** / **`KNOT_E2E_BASE_URL`** / **`DOLIBARR_BASE_URL`** ; sans déploiement aligné sur la VM, les résultats ne reflètent pas la branche locale.

**Artefacts Playwright** : rapports HTML, traces, dossier **`test-results/`** (ex. `pme-week-report.md`) sont écrits par défaut sous **`test-playwright/`**, répertoire **frère** de la racine du dépôt `core/` (ex. `knot-tools/test-playwright/`). Override : **`KNOT_PLAYWRIGHT_OUTPUT_ROOT`**. Voir **[`test-playwright/README.md`](../../../test-playwright/README.md)** (Git local optionnel, hors repo `core`).

1. **Sortie build** : Vite écrit sous **`dist/` à la racine du repo `core/`** (pas `frontend/dist/` — voir `frontend/vite.config.ts`, `outDir: '../dist'`). Si **`/dist/`** est listé dans **`.gitignore`**, le bundle n’est pas versionné : exécuter **`npm run build`** avant déploiement ou release (voir **`docs/deployment.md`**).
2. **Déploiement pilote** : depuis la racine `core/`, **`bash scripts/demo_knot_vm_build_deploy_knot.sh`** enchaîne `npm ci` + `npm run build` dans `frontend/` puis **`scripts/demo_knot_vm_deploy_knot.sh`**. Alternative manuelle : même build puis **`bash scripts/demo_knot_vm_deploy_knot.sh`** seul.
3. **Garde-fou** : **`demo_knot_vm_deploy_knot.sh`** échoue si **`dist/knot-app.js`** est absent (rsync **`--delete`** sinon peut effacer les assets sur la démo). Contournement intentionnel : **`KNOT_DEPLOY_ALLOW_NO_DIST=1`**.
4. **E2E éditeur** : privilégier les sélecteurs **`[data-knot-test="knot-inspector-aside"]`**, **`knot-editor-palette`**, **`knot-simulation-close`** (voir `tests/e2e/helpers/knot-editor-ready.ts`) plutôt que des classes Tailwind arbitraires.

### Securite

- Fuite secrets dans logs
- CSRF operations mutantes
- Webhook HMAC
- SSRF HttpAction
- Sandbox CodeNode
- Regex ReDoS

### Charge

- 1000 executions en queue
- 100 executions concurrentes
- Purge logs volumineux

### Charge légère & reproducibilité (fondations V2.6)

- `tests/Engine/ExecutionBackoffTest.php` — backoff sans E/S.
- `tests/Load/LightEnqueueLoopTest.php` — boucle déterministe (pas de timing flaky).
- Smoke manuel post-déploiement **demo.knot.tools** : exporter `capabilities.php` (JSON), ouvrir **Exécutions** → onglet **File** (`?mode=executions&execution_tab=queue` ou alias `?mode=queue`), valider import bulk avec prévol (`import_precheck`).

## Environnements Cibles

- PHP 8.1 et 8.2
- Dolibarr V20 et V21 minimum
- MySQL 5.7
- MariaDB 10.5+

## CI Progressive

### Etape 0

- Verification docs
- Syntaxe PHP

### Phase A

- PHPCS ou PHP-CS-Fixer
- PHPStan ou Psalm niveau modere
- PHPUnit unitaires credentials/repositories

### Phase B

- PHPUnit moteur
- Integration DB
- Tests cron/queue

### Phase C+

- Tests connecteurs avec mocks
- Playwright smoke
- Verification licences automatique
- Smoke **mission-internal** contre Dolibarr Docker : `.github/workflows/mission-docker-smoke.yml` ou `scripts/ci/docker_mission_smoke.sh` (voir `docker/README.md`)
- **Playwright `pme-week` (long-running)** — commande **`npm run test:demo:pme-week`** hors PR fréquentes ; préférer **job nightly/hebdo** contre une demo jetable avec MySQL probes + cron Knot actifs (voir `docs/testing.md` § E2E Playwright).

### Phase K

- Package zip
- Audit securite
- Tests charge
- Install fresh/upgrade/uninstall

## Seuils

- Engine : 80%
- Security/Credentials : 90%
- Connecteurs V1 : 70%
- Aucun test critique ignore sans justification dans `docs/llm/decisions.md`

## Etat couverture (V2.5.0e — Chantier 1 Audit)

Suite : **PHPUnit 567 tests / 1788 assertions** (mesure locale récente ; 1 skip sans `KNOT_COMPAT_DOL_ROOT`) — bilan consolidé usages bêta : **[`docs/testing/coverage-audit-pre-beta.md`](testing/coverage-audit-pre-beta.md)**.

_Archive note : cette section citait précédemment **476 tests / 1226 assertions** ; le décompte a évolué avec les suites/licensing/mission._

Couverture cible explicite par sous-systeme :

| Domaine | Fichier(s) test | Cas |
|---|---|---|
| Cron POSIX | `tests/Cron/CronEvaluatorTest.php` | 17 tests : `*/N`, ranges, listes, OR Vixie DOM/DOW, sun=0=7, timezone, fallback |
| Idempotence | `tests/Repository/IdempotencyRepositoryTest.php` | 9 tests : SQL boundary in-memory, cleanKey, TTL min/default, isolation entity |
| Retry/backoff moteur | `tests/Engine/WorkflowEngineRetryTest.php` | 6 tests : succès N-1, exhaustion, backoff exponentiel, dry-run no-sleep |
| Multi-entity guard | `tests/Repository/MultiEntityGuardTest.php` | 5 tests : WorkflowRepository fetch/list/delete + IdempotencyRepository scope |
| E2E examples | `tests/E2E/ExampleWorkflowsE2ETest.php` | 6 tests : replay `examples/test-greeting.knot.json` deux branches, inventaire pin |

**Lacunes documentees** :

- `Knot\Security\CodeSandbox` : classe non implementee (specifiee dans `docs/security.md`). Tests dedies attendent V2.6.
- `examples/test-introspection.knot.json` et `examples/test-quotes-report.knot.json` : forme JSON validee unitairement, **execution complete** via le harness Docker (`docker/README.md`) — non couverte par PHPUnit pur.
- `api/execute.php` mode synchrone : ne persiste pas la cle d'idempotence apres succes (uniquement async). Comportement actuel pinne, arbitrage V2.5.0c.

## Conventions operationnelles

### PHP — PHPUnit

- Dossier : `tests/` mirroirise `class/` (ex: `tests/Engine/WorkflowEngineTest.php` teste `class/Engine/WorkflowEngine.php`)
- Lancement : `vendor/bin/phpunit` (ou `composer test`)
- Bootstrap : `tests/bootstrap.php` charge l'autoload + les stubs
- Stubs Dolibarr : `tests/stubs/dolibarr.php` (classe abstraite `DoliDB`, mocks Translate, etc.)
- Mocks : extension `\DoliDB` via classe anonyme ou `tests/stubs/`. Jamais `PHPUnit\Mock` direct sur `DoliDB`.
- Naming : `test<UpperCamelCaseScenario>` (ex: `testIntegerFieldMapsToIntegerType`)
- Un fichier de test par classe testee
- Couverture cible : 80%+ sur les nouvelles classes
- State machine + compatibilité schéma : `tests/StateMachine/*`,
  `tests/Compatibility/Versioning/*`, scripts CLI sous `scripts/*.php`
  (voir `docs/state-machine/README.md` et `docs/compatibility/README.md`).
- Playwright : `tests/e2e/specs/compatibility.spec.ts` (écran `mode=compatibility`).

### Frontend — Vitest (V2.3+)

- Setup en V2.3, pas avant
- Dossier : `frontend/src/__tests__/` ou `*.spec.ts` a cote du composant
- Tests prioritaires : `DynamicForm.vue`, `NodeInspectorBody.vue`, `EditorView.vue`, `validator.ts`

### Smoke E2E sur Plesk

- Scripts en `scripts/_smoke_<feature>.py` -> prefixe `_` -> jamais commit
- Pattern : utiliser `scripts/_ssh.py` + `scripts/credentials.py`
- Toujours nettoyer apres validation
- Workflows demo dans `examples/<nom>.knot.json` (commit)

### Tests d'integration sur Plesk

Pour les features touchant Dolibarr :

1. Importer un workflow demo via `api/workflows.php`
2. Lancer en mode sync via `api/execute.php?mode=sync`
3. Verifier l'effet en DB (SELECT sur les tables Dolibarr)
4. Cleanup (rollback ou DELETE)

### Regression

- A chaque commit, **tous** les tests phpunit existants doivent rester verts
- Lancer la suite complete avant chaque tag : `vendor/bin/phpunit`
- Si un test casse a cause d'un changement legitime, mettre a jour le test dans le meme commit (jamais commit avec test casse)

### Stubs Dolibarr — extension

Pour les nouveaux tests touchant Dolibarr :

- Etendre `tests/stubs/dolibarr.php` avec les fausses classes necessaires (`FakeFacture`, `FakeFactureLigne`, `Translate`, etc.)
- Toujours declarer `$fields` representatif sur les fausses classes pour tester `SchemaBuilder`
- Translate stub retourne `'TR['.$key.']'` pour tracer l'appel a `$langs->trans()`

### Annex — extensions commerciales (Pro Pack, miroirs)

Les bundles **PolyForm Shield** (ex. **Knot Pro Pack** sous `knot-tools/pro-pack/`) reposent sur le meme **Knot Core** en autoload PHPUnit (voir `pro-pack/tests/bootstrap.php` et `KNOT_CORE_PATH`). Politique couverture :

- Les connecteurs migres depuis le noyau sous **`Imported/`** sont **couverts en parité et testés en profondeur dans le repo Core** ; le `phpunit.xml.dist` du Pro Pack **retire ce repertoire du calcul de couverture**.
- Dans le depot extension : objectif ligne **env. 50–60 %** sur le PHP **hors Imported** (`LicenseGate`, manifest + alignement **`manifestSignature`** / `knot-extension.json`, schemas `validate` / `simulate`, et executions au-dela du garde-licence avec **HTTP mocks** ou harness loopback comme dans Core).

Reference produit boundary : **`pro-pack/docs/extension-polyform-boundary.md`** (section Coverage policy).

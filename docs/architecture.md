# Architecture Knot

## Synthese

Knot est un module Dolibarr V20+ d'automatisation visuelle de workflows, 100% local, avec moteur PHP 8.1+, editeur Vue 3 + Vue Flow et catalogue extensible de connecteurs.

**Frontière Core / extensions (V2.8.1+)** : les connecteurs builtin du Core se limitent à Dolibarr, logique, email et audit interne ; HTTP générique, SFTP, Telegram, IA/SaaS et alertes multi-canal sont dans **Knot Pro Pack** — voir [**ADR-017**](architecture/decisions/ADR-017-external-api-boundary-core-vs-pro-pack.md) et [`docs/connectors-inventory.md`](connectors-inventory.md).

## Architecture Decision Records (ADR)

Decisions structurantes (introspection, file d'attente, licensing core/pro pack, machine d'etat hybride, validation DSL, **identifiants SKU premier partie**) sont tracees dans [`architecture/decisions/README.md`](architecture/decisions/README.md).

**Observabilité** : métriques Prometheus scalables via `api/metrics.php` (hors session) ; agrégats JSON pour l’UI sous session dans `api/observability.php` (`MetricsCollector` + `llx_knot_execution_log`). Voir ADR-015 et [`docs/observability/dashboard-guide.md`](observability/dashboard-guide.md).

## Decisions Cibles

- Dossier module : `htdocs/custom/knot/`
- Namespace : `Knot\`
- Tables : `llx_knot_*`
- Dolibarr : V20+ uniquement
- PHP : 8.1+
- Frontend : Vue 3, Vue Flow, CodeMirror 6
- Production : pas de Node.js obligatoire, assets compiles livres
- Deploiement : Git push puis pull manuel sur Plesk
- Remplacement Plesk : desinstallation propre de l'ancien module, pas de coexistence runtime

## Audit Prealable Du Module Historique

Les fichiers historiques ont ete audites puis supprimes au demarrage de la phase A. Les constats suivants restent documentes pour garder la trace des choix de refactor.

### Backend Analyse Pour Reference

- `core/modules/modAutomateworkflow.class.php` : reference Dolibarr pour comprendre menus, droits, cron, constantes, `_load_tables`.
- `core/triggers/interface_99_modAutomateworkflow_Trigger.class.php` : reference pour inventorier les triggers Dolibarr.
- `core/engine/WorkflowEngine.php` : reference pour comprendre les besoins d'orchestration.
- `core/engine/ConditionEvaluator.php` : reference pour les operateurs utiles.
- `core/engine/ActionExecutor.php` : reference pour les familles d'actions a couvrir.
- `class/Workflow.class.php` et `class/WorkflowExecution.class.php` : reference pour stockage JSON et historique.
- `class/WorkflowCron.class.php` : reference pour les intentions queue/retry.

Ces fichiers ne sont pas a porter tels quels. Ils peuvent etre supprimes quand la phase A demarre.

### A Jeter Ou Reecrire

- SQL inline dans les classes et l'API.
- Schema `llx_automateworkflow_*` avec unicites redondantes, `entity = 1`, extrafields metier et table `step` peu utilisee.
- API `workflow_api.php` avec CORS `*`, droits incomplets, CSRF absent et route `?action=`.
- Editeur inline `workflow_edit.php` et `workflow-builder.js` non alignes.
- Convention edges incoherente : UI `from/to`, moteur et tests `source/target`.
- Pages debug/diagnose/repair a retirer du package production ou cacher derriere superadmin.
- Tout code applicatif historique peut etre supprime : Knot ne depend pas d'une compatibilite legacy.

## Architecture Backend

```text
htdocs/custom/knot/
├── admin/                  # pages Dolibarr, setup wizard, pas de logique metier lourde
├── api/                    # entrypoints REST internes Dolibarr
├── class/
│   ├── Api/                # controllers, serializers, request validators
│   ├── Connectors/         # triggers, logic, Dolibarr, email, notification.alert (AI/SaaS/HTTP → extensions)
│   ├── Credentials/        # chiffrement, masquage, rotation
│   ├── Engine/             # orchestration, queue, expressions
│   ├── Hooks/              # triggers/hooks Dolibarr
│   ├── Reporting/          # metriques agregees (execution, execution_log)
│   ├── Repository/         # tout acces SQL
│   └── Security/           # sandbox, SSRF, rate limits, CSRF policies
├── css/
├── js/
├── langs/
├── sql/
└── scripts/                # outils dev internes, exclus package Dolistore
```

## Audit compatibilite Dolibarr

Voir [`docs/compatibility/README.md`](compatibility/README.md) : inventaire MAP / scan V2.4, catalogue `data/compatibility/dolibarr-catalog.json`, scripts `scripts/compatibility/`.

## Contrats PHP

### Marketplace catalogue (V2.8.2)

- **`MAIN_KNOT_LICENSE_BASE_URL`** — racine HTTPS du backend licence (`/api/catalog.json`, etc.). Lue via **`CatalogClientFactory::resolveBaseUrl()`** pour **`CatalogClient`** dans `api/marketplace.php`, `api/templates.php` et **`TemplateRepository::seed()`**, alignée sur le licensing (**`Bootstrap`** / **`DolistoreClient`**).
- Valeur vide ou cle absente ⇒ **`https://license.knot.tools`** (`CatalogClient::DEFAULT_BASE_URL`).

### `WorkflowEngine`

- Valide le workflow JSON.
- Cree un contexte d'execution immuable.
- Execute les nodes dans l'ordre topologique.
- Gere branches, retries, timeouts, erreurs et logs.
- Ne connait ni HTML, ni formulaire, ni SQL direct.

### `ConnectorInterface`

- `getMetadata()`
- `getConfigSchema()`
- `getCredentialType()`
- `getInputs()`
- `getOutputs()`
- `validate()`
- `execute()`
- `test()`

### Repositories

- `WorkflowRepository`
- `CredentialRepository`
- `ExecutionRepository`
- `ExecutionLogRepository`
- `WebhookRepository`
- `ScheduleRepository`
- `TemplateRepository`
- `VariableRepository`
- `AuditLogRepository`
- `IdempotencyRepository`

Tout SQL passe par ces classes.

## Regles strictes backend

- Toute classe applicative dans `class/` utilise le namespace `Knot\`.
- Pas de SQL inline dans `class/Engine/` ni `class/Connectors/` : tout passe par `class/Repository/`.
- Operations sensibles (creation ou suppression de workflow, execution, acces credentials, etc.) : journaliser via `AuditLogRepository` dans `llx_knot_audit_log`.
- Multi-entite : toute requete SQL filtre sur `entity = $conf->entity` (sauf cas exceptionnel documente).
- Fonctions publiques : PHPDoc complet, types stricts PHP 8.1+.
- Pas d'`echo` dans `class/` : retours via structures (`array`, etc.) ou exceptions.
- Migrations SQL idempotentes : `class/Migration/Migrator.php`.

## Schema SQL Cible

### `llx_knot_workflow`

- `rowid` bigint PK
- `ref` varchar(64), unique par entity
- `label` varchar(255)
- `description` text nullable
- `status` enum conceptuel : `draft`, `active`, `disabled`, `error`, `archived`
- `json_definition` longtext
- `tags` text nullable
- `version_schema` varchar(16)
- `single_instance` tinyint default 0
- `entity` int not null
- `fk_user_creat`, `fk_user_modif`
- `date_creation`, `tms`
- Index : `(entity,status)`, `(entity,ref)`, `(tms)`

### `llx_knot_credential`

- `rowid` bigint PK
- `ref` varchar(64), unique par entity
- `label` varchar(255)
- `type` varchar(64)
- `connector_type` varchar(128)
- `encrypted_data` text
- `encryption_version` varchar(16)
- `expires_at` datetime nullable
- `entity`, `fk_user_creat`, `fk_user_modif`, `date_creation`, `tms`
- Index : `(entity,type)`, `(connector_type)`

### `llx_knot_execution`

- `rowid` bigint PK
- `fk_workflow` bigint
- `status` varchar(32) : queued, running, success, error, timeout, cancelled
- `trigger_type` varchar(64)
- `trigger_data` longtext nullable
- `started_at`, `ended_at`
- `duration_ms` int nullable
- `error_message` text nullable
- `error_payload` text nullable — JSON encoding of `KnotError::toArray()` for rich UI (V2.6.1+)
- `error_node_id` varchar(128) nullable
- `retry_count` int default 0
- `parent_execution_id` bigint nullable
- `entity`
- Index : `(fk_workflow,status)`, `(entity,status)`, `(started_at)`, `(parent_execution_id)`

### `llx_knot_execution_log`

- `rowid` bigint PK
- `fk_execution` bigint
- `node_id` varchar(128)
- `node_type` varchar(128)
- `status` varchar(32)
- `input_data` longtext nullable
- `output_data` longtext nullable
- `duration_ms` int nullable
- `error_message` text nullable
- `executed_at` datetime
- `sequence_order` int
- `entity`
- Index : `(fk_execution,sequence_order)`, `(entity,executed_at)`, `(node_id)`

### `llx_knot_webhook`

- `rowid` bigint PK
- `fk_workflow` bigint
- `webhook_token` varchar(64) unique
- `method` varchar(16)
- `secret_hmac` text nullable
- `ip_allowlist` text nullable
- `is_active` tinyint
- `hit_count` int
- `last_hit_at` datetime nullable
- `rate_limit_per_minute` int
- `entity`
- Index : `(webhook_token)`, `(fk_workflow,is_active)`, `(entity,is_active)`

### `llx_knot_schedule`

- `rowid` bigint PK
- `fk_workflow` bigint
- `cron_expression` varchar(128)
- `timezone` varchar(64)
- `is_active` tinyint
- `last_run_at`, `next_run_at`
- `entity`
- Index : `(next_run_at,is_active)`, `(fk_workflow,is_active)`, `(entity,is_active)`

### `llx_knot_template`

- `rowid` bigint PK
- `ref` varchar(64)
- `label` varchar(255)
- `description` text
- `category` varchar(64)
- `json_definition` longtext
- `icon` varchar(128)
- `is_system` tinyint
- `entity`
- Index : `(entity,category)`, `(ref,entity)`

### `llx_knot_variable`

- `rowid` bigint PK
- `ref` varchar(128)
- `label` varchar(255)
- `value` text
- `is_secret` tinyint
- `scope` varchar(32) : global, workflow
- `fk_workflow` bigint nullable
- `entity`, `fk_user_creat`
- Index : `(entity,scope)`, `(fk_workflow)`, `(ref,entity)`

### `llx_knot_audit_log`

- `rowid` bigint PK
- `action_type` varchar(128)
- `entity_type` varchar(128)
- `entity_id` bigint nullable
- `fk_user` bigint nullable
- `ip_address` varchar(64)
- `user_agent` varchar(255)
- `payload` longtext nullable
- `created_at` datetime
- `entity`
- Index : `(entity,created_at)`, `(action_type)`, `(entity_type,entity_id)`

## Frontend

L'editeur historique est remplace. Vue Flow devient la seule source UI :

- `js/components/editor/`
- `js/components/setup/`
- `js/components/executions/`
- `js/components/templates/`
- `js/components/dashboard/`
- `js/components/shared/`

Le format canonique des edges est `source` / `target` / `type`. Toute autre convention est refusee ou normalisee a l'import.

## Setup Wizard

`admin/setup.php` devient un assistant guide :

1. Bienvenue et prerequis
2. Identite instance
3. Securite et chiffrement
4. Permissions
5. Cron et execution
6. Connecteurs
7. Pack metier optionnel
8. Templates
9. Licence produit
10. Workflow demo
11. Resume et validation

## APIs Dolibarr A Verifier En Phase A

- `htdocs/core/triggers/` pour triggers natifs V20
- `GETPOST()` et sanitizers
- `$user->hasRight(...)`
- `$conf->entity` et multicompany
- Cron natif Dolibarr
- `dol_syslog`
- `getDolGlobalString`
- Helpers UI `Form`, `FormSetup`, `FormFile`

## State machine (Dolibarr lifecycles)

- ADR — [`docs/architecture/decisions/ADR-005-state-machine-hybrid-approach.md`](architecture/decisions/ADR-005-state-machine-hybrid-approach.md).
- Design — [`docs/architecture/state-machine-design.md`](architecture/state-machine-design.md).
- ADR runtime implementation — [`docs/architecture/decisions/ADR-012-state-machine-runtime.md`](architecture/decisions/ADR-012-state-machine-runtime.md).
- Snapshot storage — [`docs/architecture/decisions/ADR-011-schema-snapshot-storage.md`](architecture/decisions/ADR-011-schema-snapshot-storage.md).
- Operator playbook — [`docs/state-machine/README.md`](state-machine/README.md) and compatibility tooling — [`docs/compatibility/README.md`](compatibility/README.md).
- Limits (what Knot does not infer) — [`docs/state-machine/limitations.md`](state-machine/limitations.md).

## Risques Architecturaux

- Scope connecteurs trop large : mitigation par V1 must/should/could.
- Frontend Vue Flow complexe : spike obligatoire en phase A.
- Multi-entite : tests dedies requis.
- Credentials : chiffrement et masquage des la phase A.
- Plesk : remplacement uniquement apres backup et staging.

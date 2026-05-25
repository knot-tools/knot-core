# Extensibilite Knot — Core vs add-ons

## Principe directeur

Knot suit le modele **« core libre + add-ons monétisables »** :

- **Knot Core** (module Dolibarr `modKnot`, GPL-3.0) : moteur, engine et **connecteurs enregistrés dans** `Knot\Connectors\ConnectorRegistry::all()` — depuis **V2.8.1** **31** connecteurs builtin (triggers génériques, logique, famille Dolibarr, email, **`notification.alert`** audit-only). Les intégrations HTTP/SFTP/Telegram/IA/SaaS et alertes multi-canal sont dans **Pro Pack** ; voir [`docs/connectors-inventory.md`](connectors-inventory.md). Le registry reste la **source de vérité** du décompte ; voir aussi [`docs/connectors.md`](connectors.md).
- **Knot Add-ons** (modules Dolibarr séparés `modKnotXxx`, GPL tiers ou **PolyForm Shield source-available** pour les packs Knot officiels) : connecteurs supplémentaires chargés via `knot-extension.json` et `ExtensionRegistry`. Le **Knot Pro Pack** (PolyForm, achat **license.knot.tools**) fournit notamment les intégrations SaaS / IA cloud facturées à l’usage (voir manifest dans le dépôt `pro-pack`).

Architecture plugin introduite en **V2.3.5**. La validation licence commerciale (`validation: dolistore` ou `license` — alias technique du client portail, voir **ADR-021**) est décrite dans [`docs/licensing.md`](licensing.md) ; checkout extensions payantes = **`license.knot.tools` uniquement** ([`docs/license-portal.md`](license-portal.md)).

Voir aussi : `docs/connector-authoring-guide.md`, `docs/ecosystem.md`, [`docs/compatibility/README.md`](compatibility/README.md), [`docs/compatibility/architecture-generic-dolibarr-connector.md`](compatibility/architecture-generic-dolibarr-connector.md), [`docs/compatibility/extending-knot.md`](compatibility/extending-knot.md), `docs/roadmap.md`.

## Connecteurs Pro Pack (même `id` persistant)

Les connecteurs SaaS / IA premium listés ci-dessous sont livrés avec **Knot Pro Pack** tout en conservant les **mêmes ids** de nœuds que les workflows Core historiques. Sans extension installée, Core affiche une recommandation d’activation Pro Pack plutôt qu’un message de « migration ».

**20** identifiants ont été **retirés du registry Core** et sont fournis par l’extension **knot-pro-pack** via des sous-classes qui réutilisent le code Core sous `class/Connectors/Saas/` et `class/Connectors/AI/`. Les workflows existants **gardent le même** `connectorId` dans le JSON : installer et activer le Pro Pack restaure l’exécution sans réécriture.

Liste canonique (à garder alignée avec le code) : **`\Knot\Migration\ConnectorMigration::MIGRATED_TO_PRO_PACK`** dans [`class/Migration/ConnectorMigration.php`](../class/Migration/ConnectorMigration.php).

Le manifest `pro-pack/knot-extension.json` déclare en outre des actions **Pro-only** supplémentaires (PayPal avancé, abonnement Stripe, Slack/Discord « full », etc.) qui n’étaient pas des entrées séparées du registry Core : elles exigent le Pro Pack dès leur utilisation.

### Détection « besoin Pro » dans l’UI

Aujourd’hui, le scan des workflows impactés (`ConnectorMigration::scanImpactedWorkflows`) et les bannières migration portent sur les **20** ids ci-dessus. **Étendre** cette liste aux seuls ids Pro-only du manifest (ex. `action.slack`) est **reporté** : à trancher si l’on veut un message uniforme avant la première exécution sur ces nœuds.

## Conventions à respecter

### 1. ConnectorRegistry doit rester découvrable

- Ne jamais ajouter de logique métier dans `ConnectorRegistry::all()` qui empêcherait l’enregistrement dynamique d’extensions
- Chaque connecteur expose ses métadonnées via `ConnectorInterface::getMetadata()` + attribut `#[Connector(id, category)]`
- L’`id` du connecteur doit être stable (utilisé dans les workflows persistés)

### 2. Pas de couplage direct au registry

- Un connecteur ne doit **jamais** instancier `ConnectorRegistry` ou référencer un autre connecteur par classe
- Les dépendances passent par le moteur (arêtes du workflow)

### 3. Catégories réservées

Réservées au Core (un add-on ne doit pas les réutiliser comme catégorie primaire de ses propres connecteurs « core-like ») :

- `trigger`, `logic`, `dolibarr`, `core` (convention projet)

Les add-ons utilisent des catégories préfixées (ex. `saas`, `communication`, namespace produit).

### 4. Manifest (`knot-extension.json`)

#### Hook `postApply` (migrations BDD après Apply in-product)

Depuis Core **2.14+**, un add-on peut déclarer un bloc optionnel **`postApply`** pour exécuter
des migrations SQL **après** le swap de fichiers réussi (`api/updates_apply.php`). Core ne
hardcode jamais un slug d'extension : tout passe par le manifest + autoload de l'extension.

```json
{
  "postApply": {
    "contractVersion": 1,
    "autoload": "autoload.php",
    "migrationRunner": "Knot\\Extension\\Migration\\Migration\\Migrator"
  }
}
```

| Champ | Règle |
|-------|--------|
| `contractVersion` | Entier ≥ 1. Core **2.14** supporte uniquement `1` ; une version plus récente est rejetée à la validation manifest. |
| `autoload` | Chemin relatif à la racine de l'extension (pas de `..`, pas de chemin absolu). Chargé une fois avant instanciation du runner. |
| `migrationRunner` | FQCN PHP sous `manifest.namespace`. Constructeur **`($db, $extensionRoot)`**, méthode **`run(): array`** retournant des entrées `{ version, file, status, durationMs }`. |

Comportement Core :

- Si `postApply` est absent → Apply retourne `migrations: []` après swap.
- Si le runner lève une exception → **rollback fichiers automatique** + HTTP **422** (rollback OK) ou **500** (rollback échoué).
- Le **`Migrator` Core** et les Migrators officiels sont **fail-fast** : la première erreur SQL interrompt la chaîne (pas de `status: "error: …"` + continuation).

**Convention fichiers SQL :** `<extensionRoot>/sql/migrations/v{semver}/NN_descriptive_name.sql`.

**Convention table d'historique :** `llx_knot_<slug_sans_prefixe>_ext_history` (ex.
`llx_knot_migration_ext_history`, `llx_knot_propack_ext_history`). La table est créée par le
Migrator au premier run (`CREATE TABLE IF NOT EXISTS`).

**DDL only :** les migrations `.sql` ne contiennent que du schéma (`CREATE` / `ALTER`). Le DML
(seed, données multi-entité) reste dans `modKnotXxx::init()` ou `Repository::seed()` à
l'activation module.

**Politique BDD Pro Pack :** le Pro Pack consomme les primitives Core (`llx_const`,
`llx_knot_credentials`, `llx_knot_idempotency`, `llx_knot_extension_state`, `llx_knot_audit_log`).
Une table dédiée Pro Pack n'est justifiée que pour une donnée métier intrinsèquement liée à un
connecteur Pro (ex. curseur de pagination Shopify). L'infra `Migrator` + table d'historique vide
ready-to-use couvre les futures migrations sans table fourre-tout.

Validation : [`ManifestSchema::validatePostApply()`](../class/Extension/ManifestSchema.php).
Exécution : [`ExtensionPostApplyRunner`](../class/Updates/ExtensionPostApplyRunner.php).

---

Un add-on dépose un manifest à sa racine (exemple dans `pro-pack/knot-extension.json`) :

```json
{
  "id": "knot-stripe-pro",
  "label": "Knot Stripe Pro",
  "version": "1.0.0",
  "author": "Knot Team",
  "category": "premium",
  "license": {
    "type": "commercial",
    "validation": "dolistore",
    "productId": "12345"
  },
  "requires": {
    "knot": ">=2.3.5",
    "dolibarr": ">=17.0"
  },
  "connectors": [
    "Knot\\Extension\\StripePro\\SubscriptionAction"
  ],
  "namespace": "Knot\\Extension\\StripePro\\"
}
```

### 5. Namespaces

- Connecteurs Core : `Knot\Connectors\<Category>\<Name>`
- Connecteurs add-ons : `Knot\Extension\<AddonName>\<Name>`

### 6. Connecteurs soumis à licence

Un connecteur commercial peut vérifier la licence avant exécution (voir `LicenseValidator` / gate côté Pro Pack).

### 7. UI palette

Le frontend distingue `core`, extensions `pro` / `enterprise` / `third-party`, et les connecteurs d’extensions non installées.

## UI extension (ADR-20)

Depuis V2.10.x un add-on peut **étendre le shell Knot Core** : item de sidebar dynamique, bundle Vue injecté dans `workflows/preview.php`, API runtime `window.KnotCore`, primitives de design system mutualisées. La référence canonique est [ADR-20 — Mécanisme d'extension UI](https://github.com/knot-tools/migration/blob/main/docs/decisions/20-ui-extension-mechanism.md). Cette section résume le contrat producteur côté Core.

### Manifest

L'add-on déclare une section `ui` optionnelle dans `knot-extension.json` (validée par [`ManifestSchema::validateUi()`](../class/Extension/ManifestSchema.php)) :

```json
{
  "ui": {
    "menu": {
      "label": "Migration",
      "labelLang": "knotmigration@knotmigration",
      "mode": "migration",
      "section": "marketplace",
      "placement": "end",
      "icon": "git-fork",
      "position": 10
    },
    "bundle": {
      "js": "dist/knot-extension.js",
      "css": "dist/knot-extension.css",
      "globalEntry": "KnotMigrationExtension"
    },
    "requiredPermission": "knotmigration.use",
    "ctaIfMissing": {
      "label": "Activer Knot Migration",
      "url": "https://knot.tools/buy/knot-migration"
    },
    "onboarding": {
      "adminSetupRequired": false,
      "adminSetupUrl": null,
      "ctaIfPermissionMissingForAdmin": null
    }
  }
}
```

- `menu.mode` doit être en kebab-case et **différent** de tous les modes natifs Core (`editor`, `workflows`, `executions`, `dashboard`, `marketplace`, `observability`, etc.). Core émet un `error_log` et masque l'extension en cas de collision. Le mode legacy `pro-pack-migration` a été retiré en 2.13.3 — utiliser `pro-pack` côté extension Pro Pack.
- `menu.section` doit appartenir à `ManifestSchema::ALLOWED_UI_MENU_SECTIONS` (`dashboard`, `marketplace`, `operations`, `catalog`, `admin`). Les extensions commerciales Knot (Migration, Pro Pack) se placent en `marketplace` avec `placement: end` pour apparaître sous l'entrée native Marketplace (chrome doré).
- `menu.placement` (optionnel, défaut `end`) : `start` insère l'entrée **avant** le premier item natif de la section ; `end` **après** le dernier (comportement historique).
- `bundle.globalEntry` est un identifiant JS valide (le bundle IIFE s'expose sur `window` sous ce nom).
- `requiredPermission` suit la forme Dolibarr `module.perm` ou `module.perm.subperm`.
- `ctaIfMissing` est utilisé quand la licence est manquante : le shell rend un `KEmptyState` avec le label/URL fournis.
- `onboarding` permet à l'extension de signaler qu'un setup admin est requis et de rediriger les non-admins vers une CTA dédiée.

### Sidebar dynamique

[`core/tpl/knot-leftnav.tpl.php`](../tpl/knot-leftnav.tpl.php) appelle `ExtensionRegistry::active()` après les items natifs et délègue à [`SidebarPresentation::buildExtensionItems()`](../class/Extension/SidebarPresentation.php) qui filtre selon `requiredPermission` et bascule sur la CTA admin si l'opérateur est admin sans setup terminé. Toute exception est attrapée et journalisée — la sidebar native ne casse jamais à cause d'une extension défaillante.

### preview.php et `window.KNOT_EXTENSIONS`

[`core/workflows/preview.php`](../workflows/preview.php) :

1. construit `$knotExtensionsPayload` (id, label, version, mode, bundle URLs, statut licence, permission utilisateur, onboarding) ;
2. étend `$allowedModes` avec les modes des extensions ;
3. émet `window.KNOT_EXTENSIONS = [...]` dans la page ;
4. injecte les `<script src=".../dist/knot-extension.js" defer>` et `<link>` CSS de chaque extension active à la fin du document.

Les bundles d'extension chargent **après** le bundle Core (`knot-app.js`) — `window.KnotCore` est donc toujours disponible quand l'IIFE de l'extension s'exécute.

### Runtime `window.KnotCore`

Core installe le singleton via `installKnotCore()` dans [`frontend/src/main.ts`](../frontend/src/main.ts) (idempotent — un double include ne crée pas deux registries). L'interface canonique est dans [`frontend/src/lib/knotCore.ts`](../frontend/src/lib/knotCore.ts) :

- `coreVersion`, `baseUrl`, `locale` — métadonnées Core (sourcées depuis `window.KNOT_*`).
- `extensions: KnotExtensionMeta[]` + `extension(id)` — lookup typé.
- `registerExtension(id, { mount, unmount? })` — appelé par l'extension dès que son bundle a parsé ; déclenche `knot:extension-registered`.
- `mountExtension(id, el)` / `unmountExtension(id, el)` — utilisés par le composant [`KnotExtensionMount.vue`](../frontend/src/components/KnotExtensionMount.vue) que Core route quand l'opérateur ouvre `?mode=<extensionMode>`.
- `apiFetch(path, init?)` — joint `window.KNOT_API_BASE` et ajoute `X-CSRF-Token`.
- `persistedState(extensionId)` — store synchrone localStorage + mirror HTTP best-effort vers `api/extension_state.php` (slice 4, table `llx_knot_extension_state`). Permet à une extension de mémoriser un onboarding entre sessions / appareils.
- `openLicenseActivationModal(extensionId, label?)` — l'extension délègue à Core l'affichage de la modale d'activation licence ; Core émet `knot:extension-license-activated` sur succès.
- `ui: { KHero, KGlassCard, KEmptyState, KSkeleton, KAnimatedCounter }` — primitives Vue du design system réexposées comme objets composant. L'extension les enregistre sur son propre `app.component(...)` au lieu de bundler une copie locale (réduit le bundle).

### Cycle de vie côté extension

1. Le bundle IIFE de l'extension parse → expose `window.KnotMigrationExtension`.
2. Une fois Vue chargé, l'extension appelle `window.KnotCore.registerExtension('knot-migration', { mount, unmount })`.
3. Quand l'opérateur navigue vers `?mode=migration`, Core localise l'élément `<KnotExtensionMount>` dans `App.vue` et appelle `mountExtension`, lequel invoque le `mount(el, ctx)` du registrant avec le `KnotExtensionContext` (meta, apiBase, csrfToken, locale, persistedState, apiFetch).
4. L'extension monte son arbre Vue isolé dans `el`. Aucune fuite vers le Pinia / I18n / Router de Core.
5. Au démontage (changement de mode), Core appelle `unmount(el)` puis vide l'élément.

### Tests

- PHPUnit : `tests/Extension/ManifestSchemaTest.php`, `tests/Extension/SidebarPresentationTest.php`, `tests/Extension/ExtensionRegistryTest.php`, `tests/Extension/LicenseValidatorTest.php`.
- Vitest : `frontend/src/lib/__tests__/knotCore.test.ts` (registration, mount, persistedState, license modal), `frontend/src/components/__tests__/KnotExtensionMount.test.ts`.

## Synchronisation liste migrée / Pro Pack

Toute modification de **`ConnectorMigration::MIGRATED_TO_PRO_PACK`** doit rester **strictement alignée** avec :

1. Les **`id`** retournés par `getMetadata()` des classes Core sous `Saas/` et `AI/` (sous-classes Pro inchangées).
2. Le tableau `connectors[]` de **`pro-pack/knot-extension.json`** pour les entrées concernées.
3. Les tests **`ConnectorMigrationParityTest`** du dépôt `pro-pack`.

Sinon les bannières de migration et l’audit ne reflètent plus la réalité des workflows.

## Roadmap liée

- **V2.3.5** : `ExtensionRegistry` + manifest + licence + UI palette.
- **V2.4** : introspection / scanner Dolibarr.
- **V2.5.0a** : chaîne licence signée, cache, grace offline (voir `docs/licensing.md`, `docs/roadmap.md`).
- **V2.5.0b** : Pro Pack + observabilité / OpenAPI / i18n (roadmap).
- **V2.6+** : Enterprise, templates verticaux, marketplace élargie.

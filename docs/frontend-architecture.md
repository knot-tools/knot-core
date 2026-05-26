# Frontend Architecture — Knot

> Conventions de structure et d'organisation du code de l'editeur visuel Vue 3.
> Pour les tokens visuels, palette et composants UI, voir `docs/design-system.md`.
> S'applique aux fichiers `frontend/src/**/*.vue`, `frontend/src/**/*.ts`.

## Stack

Stack confirme : **Vue 3 + Vite + TypeScript + Tailwind CSS + Vue Flow + Lucide icons**.

Aucun React. Aucun jQuery ajoute. Pas de Rete.js (ancien plan, abandonne).

## Structure des dossiers

- `frontend/src/views/` : pages (`EditorView.vue`, `ObservabilityView.vue`, `BookView.vue`, `WorkflowsView.vue`, `ExecutionsView.vue`, etc.)
- `frontend/src/components/` : composants reutilisables, organises par domaine
  - `inspector/` : panneau de config noeud (`NodeInspectorBody.vue`, `DynamicForm.vue`, `panels/*Panel.vue`)
  - `canvas/` : composants lies au canvas Vue Flow
  - `palette/` : palette de connecteurs (drag-drop)
  - `shared/` : composants generiques
- `frontend/src/lib/` : logique non-UI (`api.ts`, `validator.ts`, `useHistory.ts`, `expressions.ts`)
- `frontend/src/stores/` : Pinia stores (si presents)
- `frontend/dist/` : **n’existe pas** ; sortie Vite : **`dist/`** à la racine **`core/`** (`vite.config` → `outDir: '../dist'`), livrée avec le module

## Conventions

- **Tailwind avec prefixe `k-`** (configure dans `tailwind.config.js`) pour eviter les collisions avec Dolibarr
- Communication backend uniquement via API REST interne (`api/*.php`) typee dans `frontend/src/lib/api.ts`
- Navigation interne : **`workflows/preview.php?mode=…`** (`dashboard`, **`observability`**, `editor`, …) ; props injectées via `data-*` sur `#knot-app` (`frontend/src/main.ts`).
- Assets compiles livres avec le module, **aucun Node.js requis en production**
- Strings utilisateur i18n FR + EN minimum (a formaliser en V2.5 avec Vue i18n)
- TypeScript strict : pas de `any` sans justification, types explicites pour les props/events
- Preferer la composition API + `<script setup lang="ts">`
- Etats transitoires (drag, hover) dans `ref()`, etats partages dans Pinia ou injection
- localStorage pour la preference theme (`knot.theme = 'light' | 'dark'`)

## i18n (strategie Knot)

Référence produit (`p1a-vw-7`) — alignement vue-i18n + runtime navigateur :

- **Pluriels / ICU** : `vue-i18n` gère les choix pluriels au travers des messages (`{count} fichier | fichiers`). Les formulations complexes restent préférées côté clés dédiées (FR/EN/ES/IT/DE/PT) pour maîtriser la qualité plutôt qu’un pipeline automatique incomplet sur toutes les langues.
- **Dates et nombres** : hors messages statiques, le formatage passe par **`Intl`** (`Intl.DateTimeFormat`, `Intl.NumberFormat`, `Intl.RelativeTimeFormat`) avec locale dérivée de la Dolibarr / `navigator.language` où pertinent, sans dépendances additionnelles. Les littéraux mélangés restent rares dans l’éditeur (timestamps métier affichés en ISO ou locaux cohérents avec Dolibarr).
- **Traduction Markdown / HTML utilisateur** : les segments exposés depuis l’éditeur ou l’éditorial Marketplace passent par des chemins compilés CSP-safe (`cspSafeMessageCompiler.ts`) où applicable — pas de `@html` générique non filtré.
- **RTL (gauche–droite inverse)** : **dette connue**. L’interface Dolibarr de référence et le préfixe utilitaire Knot (`k-`) sont optimisés LTR ; une passe dédiée (mirroring layout, préfixes RTL Tailwind/`dir="auto"` ciblé) n’est pas engagée en bêta. Toute évolution doit couvrir `docs/design-system.md` + QA sur locale `ar` ou équivalent quand prévu roadmap.

Pour la parité des clés (FR/ES/…) : voir scripts `frontend/scripts/*.mjs`, `npm run i18n-check` en CI, et conventions dans `docs/coding-standards.md`.

## DynamicForm + JSON-schema

Le composant `DynamicForm.vue` consomme un JSON-schema standard avec extensions Knot :

- `x-dolibarr-fk` -> picker de reference
- `x-dolibarr-permission` -> badge de permission
- `x-position` -> tri des champs
- `format: 'date-time'` -> input datetime-local
- `format: 'html'` -> textarea avec helper
- `enumLabels` -> libelles pour selects derives de `enum`
- **`x-knot-array-editor`** (`'json' | 'repeater'`, optionnel) : pour `type: array` dont `items` est un objet avec `properties`.
  - Par defaut, un tableau d'objets declenchables affiche un **repéteur** (lignes ajout/suppr, sous-champs scalaires).
  - `'json'` force le **textarea JSON** (meme si `items` est un objet), pour les configs tres libres.
  - Les tableaux de scalaires ou les objets sans `items.properties` restent en textarea JSON.

Les schemas **`configSchema`** exposes par `api/connectors.php` sont la source de verite pour l'inspecteur ; le frontend resout les alias de types de noeuds legacy via `resolveConnectorSchema.ts`.

## Marketplace shell et routes étendues

Point d’entrée : **`MarketplaceView.vue`** monté via `workflows/preview.php?mode=marketplace`.
Architecture block-driven (schema **`version: 2`**) — voir **`docs/marketplace-editorial-schema.md`**.

### Couches

```
MarketplaceView
  └─ MarketplaceShell (skeleton | unavailable | main slot + drawer)
       ├─ ProductDetailLayout      (#/product/{slug})
       ├─ TemplateDetailLayout     (#/template/{slug})
       └─ BlockRenderer            (home, news, storefront blocks)
            └─ blocks/registry.ts → *.vue par BlockSpec.type
```

**`MarketplaceShell`** expose `openDrawer` / `closeDrawer` pour previews produit-template.
État kill-switch propagé depuis `editorial.meta.killSwitch`.

### Hash router — `useMarketplaceRoute`

Fichier : **`frontend/src/composables/useMarketplaceRoute.ts`**.

- Parse `#/segment/...?query` → **`MarketplaceRouteSnapshot`** (`kind`, `slug`, `query`).
- **`kind`** : `home` | `product` | `template` | `news` | `packs` | `templates` | `category` | `collection` | `search`.
- Redirects : map statique + **`editorial.redirects`** (array `{ from, to, view? }` ou object).
- **`navigate('/product/foo')`** met à jour `location.hash` ; écoute `hashchange`.
- Slugs : regex **`MARKETPLACE_SLUG_PATTERN`** (`^[a-z0-9-]+$`).

Routes **étendues** (v2 home discovery) :

- **`#/category/{slug}`** / **`#/collection/{slug}`** — filtres catalogue + chips (`useMarketplaceFilters`).
- **`#/search?q=`** — barre recherche (`useMarketplaceSearch` + `MarketplaceSearchBar`).
- Home sans `layout` legacy : bloc synthétique **`home_discovery`** si `home.spotlight` ou `home.collections` présents.

### Contexte bloc — `BlockContext`

Injecté via **`knotMarketplaceBlockContextKey`** :

| Champ | Usage |
|-------|--------|
| `route`, `marketplace` | Snapshot router + enveloppe API |
| `navigate`, `reloadMarketplace` | Navigation + refresh admin (`?action=refresh`) |
| `prefetch*` | **`useMarketplacePrefetch`** — warm routes au survol |
| `trackEvent` | **`marketplaceTrack.ts`** → POST telemetry |

Composables satellites : **`useMarketplaceCollections`**, **`useMarketplaceFilters`**, **`useLazyMount`**, **`useSanitizedMarketplaceHref`**.

### API et merge

- **`GET /api/marketplace.php`** — catalogue + **`editorial`** + **`sidebarBadge`**.
- Merge **`EditorialMerger`** + fallback **`data/marketplace/editorial-fallback.json`**.
- Unread badge : **`marketplaceEditorialUnread.ts`** compare `meta.updatedAt` vs localStorage.

### Tests

- Vitest : `frontend/src/views/marketplace/**/__tests__`, composables `useMarketplaceRoute.test.ts`.
- Playwright : **`test-playwright/tests/marketplace.spec.ts`** (hash scroll-restore, CSP).
- Manuel : **`docs/marketplace-manual-qa.md`**.

Runbooks : **`docs/runbooks/marketplace-monitoring.md`**, **`marketplace-incident.md`**, **`marketplace-rollback.md`**.

## Build

`cd frontend && npm run build` → génère **`core/dist/`** (repo racine), à committer et déployer.

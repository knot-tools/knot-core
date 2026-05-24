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

## Build

`cd frontend && npm run build` → génère **`core/dist/`** (repo racine), à committer et déployer.

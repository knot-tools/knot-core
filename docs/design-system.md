# Knot Design System

Le design system Knot vise un standard esthetique de niveau n8n / Make / Linear avec un seul langage visuel partage entre les pages PHP server-rendered (admin, setup) et le bundle Vue 3 (editeur, dashboard, executions).

## Principes

1. **Source de verite unique** : les tokens vivent dans `css/knot-tokens.css` (couleurs, radius, shadows, spacing, typography). Le CSS PHP et la config Tailwind les consomment tous les deux.
2. **Pas de dette UI lib** : shadcn-vue copie les composants dans `frontend/src/components/ui/` ; on les **possede** et on peut les modifier.
3. **Dark-mode aware** : tous les tokens sont definis pour `light` et `prefers-color-scheme: dark`.
4. **A11y** : focus visible, `prefers-reduced-motion`, contrastes WCAG AA minimum sur surfaces standards.
5. **Pas de framework CSS sur le PHP** : seul le CSS Knot et les tokens sont charges sur les pages admin. Tailwind reste *exclusivement* dans le bundle Vue.

## Palette

| Token | Light | Dark | Usage |
|---|---|---|---|
| `--knot-color-bg` | `#f6f7fb` | `#0b1020` | Background page |
| `--knot-color-surface` | `#ffffff` | `#11172b` | Cards, panels |
| `--knot-color-surface-soft` | `#f9fafc` | `#161d33` | Sub-surface |
| `--knot-color-border` | `#e6e9f2` | `#232a44` | Bordures discretes |
| `--knot-color-primary` | `#6366f1` | (idem) | Action primaire |
| `--knot-color-accent` | `#8b5cf6` | (idem) | Accent |
| `--knot-color-success` | `#10b981` | (idem) | Success |
| `--knot-color-warning` | `#f59e0b` | (idem) | Warning |
| `--knot-color-danger` | `#ef4444` | (idem) | Erreur |

## Gradients signature

- **Hero** : `linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%)`
- **Success** : `linear-gradient(135deg, #10b981 0%, #059669 100%)`
- **Soft** : superposition `rgba` du hero a 8% opacite pour zones CTA

## Typographie

- **Famille** : Inter (OFL), fallback systeme.
- **Echelle** : 12, 13, 14, 16, 19, 22, 32+ (clamp pour titres heros).
- **Poids** : 400, 500, 600, 700, 800.
- **Tabular numerals** sur stats / progress.

## Radius

- `--knot-radius-sm` 8px (boutons, inputs)
- `--knot-radius-md` 14px (cards)
- `--knot-radius-lg` 22px (hero)
- `--knot-radius-pill` 999px (chips, badges, progress)

## Shadows

- `--knot-shadow-xs` : carte non-interactive
- `--knot-shadow-sm` : carte au repos
- `--knot-shadow-md` : carte hover
- `--knot-shadow-lg` : hero / modale

## Animations

- Duree standard : 240ms.
- Easing : `cubic-bezier(0.22, 1, 0.36, 1)` (ease-out doux).
- Reduit avec `prefers-reduced-motion`.
- Animations signatures : `pulse`, `pulse-soft`, `shimmer`, `float`.

## Refresh 2026 (V2.5.0b-design-refresh)

Le design system a recu une vague de tokens 2026 sans casser la palette
existante. Les ajouts ciblent un look "calm design" plus moderne tout
en gardant la lisibilite et l'accessibilite.

### Nouveaux tokens

| Token | Usage |
|---|---|
| `--knot-glass-bg` / `--knot-glass-bg-strong` | Fond translucide pour overlays / modales (glassmorphism v2 subtil) |
| `--knot-glass-blur` | Blur backdrop (18px) |
| `--knot-glass-border` | Bordure 1px tres legere pour cards glass |
| `--knot-mesh-bg` | Mesh gradient signature pour hero Dashboard / wizard |
| `--knot-noise` | Texture noise SVG inline (~1.6 KB) a 4-6% opacite sur hero |
| `--knot-skeleton-base` / `--knot-skeleton-highlight` | Skeleton loaders deux tons |
| `--knot-pro-badge-bg` / `--knot-pro-badge-fg` | Badge Pro Pack (gradient brand) |
| `--knot-pro-lock-bg` / `--knot-pro-lock-border` | Etat "lock" pour features Pro non licenciees |
| `--knot-toast-{level}-bg` / `--knot-toast-{level}-border` | Toasts par niveau (success/warning/danger/info) |
| `--knot-font-size-2xl` (28px) / `--knot-font-size-3xl` (34px) | Hierarchie typo etoffee |

### Tailwind utilities additionnees

- `bg-knot-mesh` / `bg-knot-noise` / `bg-knot-pro`
- `backdrop-blur-knot`
- `animate-knot-skeleton` (pulse opacite 1.6s)
- `animate-knot-ring` (ring-pulse 1.8s pour CTA primaires)

### Composants reutilisables (`frontend/src/components/ui/`)

| Composant | Role |
|---|---|
| `KSkeleton.vue` | Bloc / texte / cercle de chargement (variants block/text/circle) |
| `KEmptyState.vue` | Empty state humain (titre + body + CTAs primaire/secondaire) |
| `KAnimatedCounter.vue` | Compteur animation requestAnimationFrame, easeOutCubic, reduced-motion safe |
| `KGlassCard.vue` | Card glass subtle (default) ou strong (modale) |
| `KProBadge.vue` | Badge "Pro" (variant pill ou lock overlay) |
| `KToast.vue` | Toast level-aware (left rail couleur + bg tinted + auto-dismiss) |

Chaque composant est :
- Type-strict (PHP 8.1+ -> TypeScript strict, Vue 3 Composition API).
- A11y conforme : `role`, `aria-label`, `aria-live`, focus visible.
- Reduced-motion safe : check `matchMedia('(prefers-reduced-motion: reduce)')` ou pulse opacite (pas de translate animation).
- Couvert par Vitest (~5 tests par composant).

### Reduced motion

Les durees de motion sont neutralisees automatiquement via `@media (prefers-reduced-motion: reduce)` dans `knot-tokens.css` :

```css
@media (prefers-reduced-motion: reduce) {
  :root {
    --knot-duration: 0ms;
    --knot-duration-fast: 0ms;
    --knot-duration-slow: 0ms;
  }
}
```

Les animations CSS qui consomment `var(--knot-duration)` (la majorite) tombent a 0ms automatiquement. Les composants Vue utilisent en plus `matchMedia` pour court-circuiter `requestAnimationFrame` (cf. `KAnimatedCounter`).

## Iconographie

- **Pages PHP server-rendered** : FontAwesome (deja embarque par Dolibarr).
- **Bundle Vue (editeur, dashboard)** : Lucide via `lucide-vue-next`.
- Style : `stroke-width: 2`, taille de base 16px, accent 20px.

## Marketplace (Vue bundle) — emojis pictogrammes (p1a-ds-3)

Dans **`frontend/src/views/marketplace/**`** on limite les pictogrammes **non Lucide** aux **emplacements texte** (listes, tableaux, messages) :

- **CHECK / CROIX** : utiliser les **caractères Unicode** `✓` (succès inclus) et `—` ou `–` pour l’absence (plans comparés, badges visuels), **pas** d’emoji couleur décoratifs Unicode dans les blocs editorial statiques livrés en JSON ; les alertes critiques restent sur **Lucide** (`AlertTriangle`, `Ban`).
- **Soulignements marketing** courts : éviter les emojis dans les titres **`title` / `kicker`** des payloads éditoriaux ; ils compliquent l’audit **`EditorialValidator`** et les traductions.
- **Icônes structurées** : préférer le champ **`icon`** (whitelist Lucide kebab-case côté PHP) mappé vers **`lucide-vue-next`** dans les blocs (`StorefrontTabsBlock`, etc.).

`prefers-reduced-motion` : les animations d’entrée Marketplace (`.marketplace-host`) sont coupées en mode réduit — ne pas compter sur le mouvement pour transmettre l’état.

## Marketplace UX patterns (schema v2)

Patterns partagés entre le bundle Vue (`frontend/src/views/marketplace/**`) et les payloads
**`version: 2`** décrits dans **`docs/marketplace-editorial-schema.md`**.

### Shell et états transitoires

| État | Composant | Rôle |
|------|-----------|------|
| Chargement initial | `MarketplaceSkeleton` | Placeholders skeleton (`--knot-skeleton-*`) avant premier `api/marketplace.php` |
| Erreur / kill-switch | `MarketplaceUnavailable` | `role="alert"`, bouton **retry**, lien pricing externe (`knot.tools`) |
| Contenu | `MarketplaceShell` + slot | Conteneur max-width 1280px, classe `.marketplace-host` pour cascade d’entrée |
| Aperçu rapide | `MarketplaceDetailDrawer` | Drawer latéral produit / template sans quitter la route courante |

Kill-switch actif : pas de hero distant — copy depuis i18n `marketplace.killSwitchTitle`.

### Navigation hash (routes étendues)

Router : **`useMarketplaceRoute`** — chemins normalisés en minuscules.

| Route hash | Layout |
|------------|--------|
| `#/` | Home v2 : `home_discovery` (spotlight + collections) ou `home.layout` legacy |
| `#/product/{slug}` | `ProductDetailLayout` (hero, pricing, tabs, galerie) |
| `#/template/{slug}` | `TemplateDetailLayout` (+ prérequis, use cases) |
| `#/news/{slug}` | `BlockRenderer` → `newsPages[slug].layout` |
| `#/packs`, `#/templates`, `#/search` | Blocs editorial ou `home_discovery` fallback |
| `#/category/{slug}`, `#/collection/{slug}` | Filtres via query `?category=` / collection editorial |

Query synchronisée : `q`, `tab`, `sort`, `category`, `tier`, `integration`.
Focus management : annonce `aria-live="polite"` screen-reader uniquement (pas de `.focus()` sur les titres — évite la sélection visible Chrome).

### Contraste tier badge (WCAG 2.2 AA — mesure outillée mai 2026)

Les badges tier rendus par `SignalBadge.vue` utilisent la combinaison
`<tone>-soft` (background) + `<tone>-strong` (foreground). Le mapping
`tier → tone` est défini dans `MarketplaceSpotlight.vue` (`tierTone`
computed) et propagé aux cartes curated et detail headers :

| Tier éditorial | Tone SignalBadge | Tokens (light) | Tokens (dark) |
|----------------|------------------|----------------|---------------|
| `pro`, `enterprise` | `premium` | `--knot-color-premium-gold-soft` / `--knot-color-premium-gold-strong` | identique (gold partagé entre modes) |
| `migration` | `success` | `--knot-color-success-soft` / `--knot-color-success-strong` | `--knot-color-success-soft` / `--knot-color-success-strong` |
| `core`, `free` | `info` | `--knot-color-info-soft` / `--knot-color-info-strong` | idem |
| `beta`, `new` | `warning` | `--knot-color-warning-soft` / `--knot-color-warning-strong` | idem |
| _(fallback)_ | `neutral` | `--knot-color-surface-soft` / `--knot-color-text-muted` | idem |

Ratios calculés à la formule WCAG 2.2 (relative luminance — voir
`scripts/_check_wcag.py` ad-hoc) sur le couple `-soft` × `-strong` posé sur
le fond carte (`--knot-color-surface` light / `#161d33` dark) :

| Tone | Light fg/bg | Light ratio | AA Normal (≥4.5) | Dark ratio | AA Normal (≥4.5) |
|------|-------------|-------------|-------------------|------------|-------------------|
| premium | `#8a6a1d` sur `#fdf6e3` | 4.68:1 | ✓ | 7.19:1 | ✓ |
| success | `#047857` sur `#ecfdf5` | 5.21:1 | ✓ | 10.95:1 | ✓ |
| warning | `#b45309` sur `#fff7ed` | 4.73:1 | ✓ | 11.58:1 | ✓ |
| info | `#0369a1` sur `#f0f9ff` | 5.57:1 | ✓ | 12.58:1 | ✓ |
| neutral | `#5b6478` sur `#f9fafc` | 5.68:1 | ✓ | 10.46:1 | ✓ |

**Régression évitée (mai 2026)** : avant le bump des tokens `-strong`, les
tones `info`/`success`/`warning` utilisaient leur variante de base
(`#0ea5e9`/`#10b981`/`#f59e0b`) en foreground et restaient sous 3:1 en
light mode (FAIL AA Normal, FAIL AA Large à 18px aussi pour le 11px
uppercase semibold utilisé dans `SignalBadge`).

Re-vérifier après toute modification d'un token `--knot-color-{info,success,warning}-strong` ou changement de fond carte.

### Blocs et tokens Marketplace

- **Premium / Pro** : `--k-shadow-knot-premium`, badge `--knot-pro-badge-*`, accent tier `pro|enterprise|migration|core`.
- **Typo display** : `.k-display-1` / `.k-display-2` pour hero et titres storefront.
- **Illustrations** : `MarketplacePromoArt` (SVG bundled) quand CDN indisponible — pas de dépendance réseau pour empty art.
- **Grilles responsives** : `ComparePlansBlock` → tableau desktop, accordéon `<details>` sous `md`.
- **Sticky CTA** : safe-area padding bas sur mobile (`frontend/src/styles/index.css`).

### Liens et tracking

- Tous les href publics passent par **`sanitizeMarketplaceHref()`** → hosts **`knot.tools`** uniquement (jamais `license.knot.tools` cliquable).
- Telemetry légère : **`marketplace_track.php`** (CTA, visites produit/news) — pas de PII dans le payload.

### QA visuelle

Voir **`docs/marketplace-manual-qa.md`** et runbooks ops :
**`docs/runbooks/marketplace-monitoring.md`**, **`marketplace-incident.md`**, **`marketplace-rollback.md`**.

## Composants

### Cote PHP (CSS pur)

- `.knot-shell` : conteneur de page admin.
- `.knot-hero` : header degrade.
- `.knot-card` : carte de base.
- `.knot-progress` : barre de progression animee.
- `.knot-steps` : liste d'etapes verticales.
- `.knot-checks` : liste de controles avec status.
- `.knot-btn` (`--primary`, `--ghost`) : bouton.
- `.knot-chip` : chip transparent sur fond degrade.
- `.knot-feature` : carte de fonctionnalite.

### Cote Vue (shadcn-vue + Tailwind)

Composants shadcn-vue copies dans `frontend/src/components/ui/` (button, card, dialog, dropdown, input, label, separator, sheet, switch, tabs, toast, tooltip). Personnalises pour matcher les tokens Knot.

Composants Knot specifiques :
- `<KnotNode>` : noeud de workflow (canvas).
- `<KnotEdge>` : edge custom (success, error, conditional).
- `<KnotInspector>` : panneau lateral de configuration de noeud.
- `<KnotExecutionTimeline>` : timeline d'execution avec breadcrumbs.
- `<KnotExpressionInput>` : input avec suggestion `{{$json.field}}` via CodeMirror 6.

## Build et integration Dolibarr

- `frontend/` est l'app Vue isolee.
- `npm run build` produit **`core/dist/knot-app.js`** + **`core/dist/knot-app.css`** (depuis `frontend/`) au format **iife/umd** monté sur `<div id="knot-app">`.
- Les pages PHP (`workflows/edit.php`, etc.) chargent le bundle via `<script src="/custom/knot/dist/knot-app.js">`.
- **`dist/`** (racine **`core/`**) est commitée au repo, masquée dans GitHub via `.gitattributes linguist-generated=true`.
- Plesk fait `git pull` ; aucun build serveur.

## Anti-vol et licence

- En-tete GPL-3.0 + copyright dans tous les fichiers (PHP/JS/CSS).
- Bundle JS minifie + obfusque legerement (Vite default minify : esbuild / terser).
- Cle de licence (Phase D) verifiee contre serveur Knot pour ralentir le piratage massif.
- Marque deposee Knot (action commerciale, hors code).

## Inspirations

- **n8n** : node canvas (Vue Flow), inspector lateral, executions log timeline.
- **Make** (Integromat) : palette pastel, iconographie ronde.
- **Linear** : typo Inter, micro-interactions, dark mode.
- **Vercel / shadcn-vue** : neutralite des composants, focus visible, contrast.

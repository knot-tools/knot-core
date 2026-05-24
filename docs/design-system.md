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

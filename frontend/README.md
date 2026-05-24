# Knot Frontend

Vue 3 + Vite + Tailwind 3 + shadcn-vue + Vue Flow + Lucide bundle that powers the Knot
visual editor, dashboard and executions view.

## Stack

- **Vue 3** + TypeScript (`<script setup lang="ts">`)
- **Vite** (build to single IIFE bundle)
- **Tailwind 3** with `k-` prefix and Knot tokens (`css/knot-tokens.css`)
- **shadcn-vue** (components copied into `src/components/ui/` — owned, not a dep)
- **Vue Flow** (canvas, used by n8n itself in v1+)
- **Pinia** (state)
- **Lucide Vue Next** (icons)
- **CodeMirror 6** (expression / code editor)

## Scripts

```bash
npm install        # one-time
npm run dev        # vite dev server
npm run build      # produces ../dist/knot-app.js + ../dist/knot-app.css
npm run type-check # vue-tsc strict
```

## Build output

The build outputs to `../dist/` (i.e. `htdocs/custom/knot/dist/`):

- `dist/knot-app.js`   — IIFE bundle, mounts on `<div id="knot-app">`
- `dist/knot-app.css`  — bundle styles (Tailwind utilities + Vue Flow theme)

## Mounting in Dolibarr

```php
<link rel="stylesheet" href="/custom/knot/css/knot-tokens.css">
<link rel="stylesheet" href="/custom/knot/dist/knot-app.css">
<div id="knot-app" data-mode="editor" data-workflow-id="42"></div>
<script src="/custom/knot/dist/knot-app.js"></script>
```

`data-mode` accepts `editor` (default), `dashboard` or `executions`.

## License

GPL-3.0-or-later © 2026 Knot.

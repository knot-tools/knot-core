# Knot — Brand Pack (P3 Core / pack-v1)

Isometric **K** mark — **pack-v1 Core** (indigo / lavender). Dark SVG masters under `img/brand/` must stay byte-identical:

- SHA256 `50df46b39e5c86bea0f2bcb0bacf8fb4242d4ee6373618c33f34818705669714`
- Files: `favicon.svg` = `knot-logo.svg` = `knot-mark.svg` (navy plate `#011029`)

Light masters (`knot-mark-light.svg`, `knot-symbol.svg`, `knot-mono-*`) keep transparent slate-stem geometry — do not restyle them when swapping the dark Core mark.

**Do not invent geometry.** Rasterize dark PNG/ICO from the dark SVG (or official 256 kit PNG) via Pillow `LANCZOS` only.

## Colours

| Token | Hex | Role |
|-------|-----|------|
| Plate / bg | `#011029` | Dark app icon plate |
| Stem (dark) | `#FEFEFE` / `#F2F2F1` / `#E3E3E3` | White stem on plate |
| Stem (light) | `#3A4558` / `#2C3545` / `#1F2736` | Slate stem, no plate |
| Upper arm | `#A78BFA` / `#7C5CE0` / `#6B4AD0` | Lavender / purple |
| Lower arm | `#6366F1` / `#4F46E5` / `#4338CA` / `#3730A3` | Indigo |
| Knuckle | `#312E81` | Deep indigo joint |

**Retired (do not use):** out-v2 cyan/royal (`#27ECFD`, `#0BC5DD`, `#06B8D1`, `#1391FC`, `#087FF2`, `#0058C7`, `#0043A6`, `#015ECB`), rose `#EC4899`, hexagon hub mark.

## Trademark

- **No ®** until INPI certificate.
- **™** only on non-FR / legal surfaces already requiring it (see `docs/branding/trademark-usage.md`).
- Wordmark lockups: use short name « Knot » in UI; do not invent a new ® lockup here.

## Which file where (Dolibarr admin view)

| Surface after install | File |
|----------------------|------|
| Home → Modules list (picto) | `img/knot.png` (`picto = knot@knot`) |
| Object / small picto | `img/object_knot.png` |
| Knot left nav mark | `img/brand/knot-logo-256.png` (+ `@2x` 512) |
| Admin About / setup hero | `img/brand/knot-symbol-512.png` |
| Browser tab (Dolibarr global) | **Do not set from the module.** Respect Dolibarr / société favicon (`MAIN_FAVICON_URL`). Assets under `img/brand/favicon*` exist for demos / marketing only — never inject `<link rel="icon">` from Core pages. |

## Pack map

| File | Meaning |
|------|---------|
| `knot-mark.svg` / `knot-logo.svg` / `favicon.svg` | Dark master (navy plate + white stem, indigo/lavender arms) |
| `knot-mark-light.svg` / `knot-symbol.svg` | Light master (slate stem, transparent) |
| `knot-logo-*.png` | Rasterized dark mark |
| `knot-symbol-*.png` | Rasterized light mark |
| `knot-mono-*.svg` | Same light geometry, solid black/white fills |
| `knot-horizontal-*.png` | **Legacy hexagon wordmark** — pending official wordmark; not used by Dolibarr module list / About |

## Rules

- Keep proportions; leave ~25% padding around the mark when placing in UI chrome (existing layouts already do).
- Prefer SVG when the host accepts it; use PNG pictos for Dolibarr.
- Do not recolour arms/stem outside the pack-v1 Core palette.
- Do not redesign editor chrome / canvas / theme for brand swaps.
- **Never** inject favicon / apple-touch-icon / shortcut-icon from Knot Core PHP/JS. Only the sidebar logo may use the Core mark on module pages; the browser tab follows Dolibarr’s global favicon.

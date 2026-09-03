# Knot — Brand Pack (out-v2 freeze)

Isometric **K** mark — freeze salon **2026-09-02**. Masters live in `logo-freeze-v2/` and must stay byte-identical to:

- dark SVG: `https://docs.knot.tools/favicon.svg` (3841 bytes, title « Knot mark »)
- light SVG: `https://www.knot.tools/brand/knot-mark-light.svg` (3748 bytes)
- PNG masters: `https://knot.tools/brand/knot-mark*.png`

**Do not reconstruct geometry.** Re-drop masters + run `python3 scripts/brand/rasterize_freeze_masters.py`.

## Colours

| Token | Hex | Role |
|-------|-----|------|
| Plate / bg | `#011029` | Dark app icon plate |
| Stem (dark) | `#FEFEFE` / `#F2F2F1` / `#E3E3E3` | White stem on plate |
| Stem (light) | `#3A4558` / `#2C3545` / `#1F2736` | Slate stem, no plate |
| Upper arm | `#27ECFD` / `#0BC5DD` / `#06B8D1` | Cyan |
| Lower arm | `#1391FC` / `#087FF2` / `#0058C7` / `#0043A6` | Royal blue |
| Knuckle | `#015ECB` | Navy joint |

**Retired (do not use):** violet `#8B5CF6`, rose `#EC4899`, hexagon hub mark.

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
| Browser tab (Knot pages) | `img/brand/favicon.svg` (+ 32/64/ico) |

## Pack map

| File | Meaning |
|------|---------|
| `knot-mark.svg` / `knot-logo.svg` / `favicon.svg` | Dark master (navy plate + white stem) |
| `knot-mark-light.svg` / `knot-symbol.svg` | Light master (slate stem, transparent) |
| `knot-logo-*.png` | Rasterized dark mark |
| `knot-symbol-*.png` | Rasterized light mark |
| `knot-mono-*.svg` | Same light geometry, solid black/white fills |
| `knot-horizontal-*.png` | **Legacy hexagon wordmark** — pending official out-v2 wordmark; not used by Dolibarr module list / About |

## Rules

- Keep proportions; leave ~25% padding around the mark when placing in UI chrome (existing layouts already do).
- Prefer SVG when the host accepts it; use PNG pictos for Dolibarr.
- Do not recolour arms/stem outside the freeze palette.
- Do not redesign editor chrome / canvas / theme for brand swaps (salon freeze).

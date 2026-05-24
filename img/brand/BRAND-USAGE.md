# Knot — Brand Pack

Pack visuel complet de Knot — Workflow Automation pour Dolibarr.

## Couleurs officielles

- **Violet primaire** : `#8B5CF6`
- **Rose secondaire** : `#EC4899`
- **Dégradé** : `linear-gradient(135deg, #8B5CF6 0%, #EC4899 100%)`
- **Texte sombre** : `#1A1A1A`
- **Fond clair** : `#FFFFFF`
- **Fond sombre** : `#0F0F14`

## Structure du pack

```
knot-brand-pack/
├── svg/                              # Sources vectorielles éditables
│   ├── 01-knot-logo.svg
│   ├── 02-knot-symbol-transparent.svg
│   ├── 03-knot-favicon-simplified.svg
│   ├── 04-knot-mono-white.svg
│   └── 05-knot-mono-black.svg
│
├── png/
│   ├── full-logo/         (16, 32, 48, 64, 128, 256, 512, 1024 px)
│   ├── favicon/           (versions simplifiées + favicon.ico)
│   ├── symbol-transparent/ (256, 512, 1024 px sans fond)
│   ├── horizontal/        (light, dark, transparent — pour README/site)
│   └── monochrome/        (blanc et noir purs, 512px)
│
└── docs/
    └── README.md
```

## Quel fichier utiliser ?

| Usage                             | Fichier recommandé                              |
|-----------------------------------|-------------------------------------------------|
| Favicon site web                  | `png/favicon/favicon.ico`                       |
| App icon (dans Dolibarr sidebar)  | `png/full-logo/knot-logo-64x64.png`             |
| Page Dolistore (icône module)     | `png/full-logo/knot-logo-256x256.png`           |
| Page Dolistore (header marketing) | `png/horizontal/knot-horizontal-light.png`      |
| README GitHub                     | `png/horizontal/knot-horizontal-light.png`      |
| Mode dark dans le module          | `png/horizontal/knot-horizontal-dark.png`       |
| Print noir et blanc               | `png/monochrome/knot-mono-black-512x512.png`    |
| Sur fond coloré custom            | `png/symbol-transparent/knot-symbol-512x512.png`|
| Carte de visite, flyer print      | SVG dans `svg/` (vectoriel)                     |

## Différence logo complet vs favicon simplifié

Le **logo complet** (avec hexagone + spokes + 6 nodes + hub) fonctionne parfaitement à partir de 48px.

En dessous, les détails se confondent et le logo devient illisible. Pour ces usages (favicon 16/32px, badges très petits), utilise la **version simplifiée** dans `png/favicon/` qui ne garde que l'hexagone et le hub central — bien plus lisible en petit format.

## Règles d'usage

**À FAIRE :**
- Toujours laisser un espace de respiration autour du logo (au moins 25% de sa hauteur)
- Utiliser le SVG quand c'est possible (qualité parfaite à toute taille)
- Utiliser la version simplifiée en très petit format (< 48px)
- Conserver les proportions originales

**À NE PAS FAIRE :**
- Ne pas étirer le logo (ne pas changer le ratio)
- Ne pas modifier les couleurs du dégradé sans raison forte
- Ne pas appliquer d'effets (ombre, flou, contour) sans charte
- Ne pas placer le logo coloré sur un fond rose ou violet (illisible) — utiliser la version monochrome blanche

## Re-générer les PNG depuis les SVG

Tous les SVG sont éditables avec Inkscape, Figma ou Illustrator.

Pour exporter en PNG en ligne de commande :
```bash
# Avec cairosvg (Python)
pip install cairosvg
cairosvg svg/01-knot-logo.svg -o knot-logo.png -W 512 -H 512

# Avec rsvg-convert (système)
rsvg-convert -w 512 -h 512 svg/01-knot-logo.svg > knot-logo.png

# Avec ImageMagick (si installé avec librsvg)
convert -background none -density 400 -resize 512x512 svg/01-knot-logo.svg knot-logo.png
```

---

Brand pack généré pour Knot v2.0.0 — workflow automation native pour Dolibarr.

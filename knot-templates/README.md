# knot-templates — Galerie de workflows Knot

> Statut : **squelette V2.5.0b**, prêt à être splitté en repo public MIT.
> Catégories initiales : `crm`, `compta`, `ecommerce`.

Ce dossier contient des workflows Knot prêts à l'emploi. Au moment du
split, il sera promu en repo public GitHub MIT (`knot-templates`)
référencé directement depuis l'éditeur Knot via le bouton **"Importer
depuis la galerie"** (Sprint 5).

## Format

Chaque workflow vit dans `<categorie>/<slug>.knot.json` et est accompagné
d'un `<slug>.meta.yaml` qui contient :

```yaml
title: "Relance des devis non signés sous 7 jours"
description: |
  Boucle quotidienne sur les propales en statut "validé" mais non
  signées, envoie un mail de relance au commercial assigné et trace
  un événement dans l'agenda Dolibarr.
category: crm
tags: [crm, email, propal, dolibarr]
requiredConnectors:
  - dolibarr.object
  - email.smtp
  - logic.if
requiredKnotVersion: ">=2.5.0"
author: knot
license: MIT
preview:
  - screenshots/relance-devis-1.png
  - screenshots/relance-devis-2.png
```

## Pipeline import

Côté frontend Vue (`frontend/src/views/WorkflowsView.vue`), un bouton
**"Importer depuis la galerie"** appelle :

```
GET https://raw.githubusercontent.com/knot/knot-templates/main/index.json
```

L'index est généré par CI à chaque merge sur `main` :

```json
{
  "schemaVersion": "1.0",
  "generatedAt": "2026-04-29T10:00:00Z",
  "templates": [
    {
      "slug": "crm/relance-devis",
      "title": "...",
      "description": "...",
      "category": "crm",
      "tags": ["..."],
      "requiredConnectors": ["..."],
      "requiredKnotVersion": ">=2.5.0",
      "author": "knot",
      "license": "MIT",
      "downloadUrl": "https://raw.githubusercontent.com/knot/knot-templates/main/crm/relance-devis.knot.json"
    }
  ]
}
```

L'éditeur Knot filtre les templates compatibles avec sa version, propose
une preview (lecture seule du graphe) puis un import dans l'entité courante.

## Contribuer

1. Fork du repo public `knot-templates`.
2. Créer un dossier dans la bonne catégorie.
3. Ajouter `<slug>.knot.json` (export depuis l'éditeur Knot) + `<slug>.meta.yaml`.
4. Ajouter screenshots si pertinent.
5. PR contre `main`.
6. CI lint :
   - JSON valide selon le schema workflow Knot V1.0.
   - YAML meta valide.
   - Tags ⊂ vocabulaire contrôlé (à définir).
   - Description en FR + EN minimum.
7. Revue manuelle par l'équipe Knot.
8. Merge → CI régénère `index.json` et publie.

Discussions GitHub activées par template pour les ratings et retours.

## Licence

Tous les templates : MIT (sauf mention contraire dans le `meta.yaml`).

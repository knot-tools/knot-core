# Marketplace Knot (admin instance)

Ce guide utilisateur résume ce que voit **l’administrateur Dolibarr** dans l’interface **Marketplace** intégrée (`Knot → preview.php` en `mode=marketplace`) et comment la surface se synchronise avec `license.knot.tools`.

Pour le schéma éditorial côté Knot Core : **`docs/marketplace-editorial-schema.md`** (dépôt `core`).

## Activation shell

La constante **`KNOT_MARKETPLACE_UI_ENABLED`** contrôle si les modes **marketplace / templates** apparaissent dans la navigation Knot. Hors activation, les utilisateurs sont redirigés vers le tableau de bord.

## Données affichées

| Source | Description |
|--------|-------------|
| Catalogue extensions | Produits **`kind=extension`** publiés, mis en cache 6 h localement (`llx_knot_config`). |
| Modèles | Synchronisation miroir `kind=template` par entité (`llx_knot_template`). Les workflows verrouillés par palier peuvent être masqués ou montrés en vitrine (**`KNOT_MARKETPLACE_PREVIEW_LOCKED`**). |
| Chrome éditorial | Document JSON (**`editorial`**) : layouts par blocs (hero, FAQ, grille de modèles, etc.). |
| Badge sidebar | Synthèse `sidebarBadge` pour le menu gauche Knot. |

L’agrégateur unique est **`GET /custom/knot/api/marketplace.php`** (droits **`knot.workflow.read`**).

### Indicateurs Doctor / healthcheck

Les métadonnées de cache Marketplace (présence d’un snapshot `marketplace.catalog_cache.<lang>`,
TTL expiré, horodatage du dernier fetch) sont exposées dans **`GET /custom/knot/api/health.php`**
→ **`doctor.marketplace`**. Utile pour un diagnostic rapide sans ouvrir la vue Marketplace.

### Rafraîchissement forcé admin

Ajouter **`?action=refresh`** tant que l’utilisateur possède **`knot.admin.configure`** invalide caches catalogue + présentation connecteurs + force un pull modèles.

## Kill-switch éditorial

Si l’éditeur publie un flag d’urgence **`meta.killSwitch: true`** sur `license.knot.tools`, les instances ne reçoivent plus **`editorial`** distant et retombent uniquement sur le **fallback embarqué** (`data/marketplace/editorial-fallback.json`). Un garde équivalent existe côté Core si une copie résiduelle du JSON contenait encore `meta.killSwitch`.

## Suivi comportement Marketplace

**`POST /api/marketplace_track.php`** permet à l’UI d’envoyer des évènements légers :

Évènements acceptés : **`cta_click`**, **`template_instantiated`**, **`product_page_visit`**, **`news_visit`**, **`banner_dismissed`**.

Contrôles : utilisateur Knot authentifié, CSRF (**`X-Csrf-Token`**), **`knot.workflow.read`**, quota **60 requêtes / minute / utilisateur**, écritures dans **`llx_knot_audit_log`** (`marketplace.track`).

## Confidentialité du preview licence (équipes Knot Tools)

Un jeton JWT (**5 minutes**) protège **`GET /api/catalog-preview.json`** sur `license.knot.tools` (même forme JSON que **`/api/catalog.json`**, **`Cache-Control: no-store`**). Émission : **`bin/marketplace_preview_link.php`** sur la VM licence (JWT signé **`JWT_SHARED_SECRET`**, audience dédiée). Ne pas partager ces URLs publiquement : elles contournent le cache navigateur CDN.

## Checklist release Marketplace

Avant tag Core touchant la vitrine Marketplace :

1. Automatisé : `npm run test`, `npm run i18n-check`, `vendor/bin/phpunit tests/Marketplace/`, build frontend.
2. Opérateur : [`docs/marketplace-release-checklist.md`](../marketplace-release-checklist.md) + QA manuelle [`docs/marketplace-manual-qa.md`](../marketplace-manual-qa.md).
3. Éditorial licence (si JSON/assets changent) : runbook [`license/docs/runbooks/marketplace-editorial.md`](../../license/docs/runbooks/marketplace-editorial.md) — **`validate_editorial.php`**, audit assets, rsync.
4. Monitoring / incident / rollback : [`docs/runbooks/marketplace-monitoring.md`](../runbooks/marketplace-monitoring.md), [`marketplace-incident.md`](../runbooks/marketplace-incident.md), [`marketplace-rollback.md`](../runbooks/marketplace-rollback.md).


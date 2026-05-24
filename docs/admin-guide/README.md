# Guide Administrateur Knot

## Objectif

Ce guide couvre l'installation, la configuration, la securite, la performance et la maintenance du module Knot.

## Runbooks operations

- **Mises à jour in-module (Core / extensions payantes)** : voir
  [`docs/runbooks/updates-apply.md`](../runbooks/updates-apply.md) (flux
  `updates_apply.php`, rollback, prérequis TLS / checksums).

## Marqueurs UI (bêta publique)

Deux constantes Dolibarr globales contrôlent l'affichage shell sans redéploiement
de code :

| Constante | Valeurs | Effet |
|-----------|---------|-------|
| `KNOT_RELEASE_CHANNEL` | `beta` (défaut) · `stable` | Badge « Bêta · Knot Core X.Y.Z » dans le footer shell |
| `KNOT_DEMO_MODE` | `1` · absent/`0` | Bandeau « environnement de démonstration » en haut du shell |

**Règles :**

- `KNOT_RELEASE_CHANNEL=stable` masque le badge bêta sur toutes les instances.
- `KNOT_DEMO_MODE=1` **uniquement** sur `demo.knot.tools` (jamais en production client).
- Les deux valeurs sont exposées dans `api/health.php` (`releaseChannel`, `demoMode`).

Configuration via **Configuration → Modules → Knot** ou CLI Dolibarr :

```bash
php scripts/admin/cli/set_const.php KNOT_RELEASE_CHANNEL beta
php scripts/admin/cli/set_const.php KNOT_DEMO_MODE 0
```

## Sections A Rediger

- Installation Dolibarr
- Wizard setup
- Permissions
- Cron et execution async
- Credentials et chiffrement
- Webhooks et rate limiting
- Purge des logs
- Backup et restauration
- Migration depuis un module historique
- Deploiement Plesk par Git pull manuel

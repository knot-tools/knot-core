# Starter templates

Cinq workflows pre-cables pour decouvrir Knot en moins de 30 minutes.
Importe-les depuis l'interface (mode `Workflows` -> bouton "Import")
ou via l'API `POST /api/workflows.php` avec le contenu d'un fichier
`.knot.json` au format export Knot 1.0.

| # | Fichier | Cas d'usage | Trigger | Connecteurs cles |
|---|---------|-------------|---------|------------------|
| 01 | `01-hello-world.knot.json` | Premier workflow | manuel | `logic.set`, `notification.alert` |
| 02 | `02-relance-facture-impayee.knot.json` | Relance J+30 | cron quotidien | `dolibarr.sql_query`, `logic.loop`, `action.email` |
| 03 | `03-webhook-to-task.knot.json` | Webhook -> tache | webhook HTTP | `logic.set`, `dolibarr.object` |
| 04 | `04-devis-to-facture.knot.json` | Sync devis -> facture | event Dolibarr | `logic.if`, `dolibarr.specialized` |
| 05 | `05-backup-quotidien.knot.json` | Self-backup | cron nocturne | `dolibarr.sql_query`, `logic.set`, `action.email` |

## Conventions

- Les schedules sont importes en `isActive: false` pour eviter
  qu'un import declenche un email a 9 h du matin sans validation.
  Active-les dans l'editeur quand le workflow est pret.
- Les `credentialId` portent un placeholder
  `REPLACE_WITH_SMTP_CREDENTIAL_ID` qui doit etre remplace par
  l'identifiant reel du credential SMTP de l'instance.
- Les `to` portent un placeholder `REPLACE_WITH_ADMIN_EMAIL` —
  meme principe.

## Tester rapidement

```bash
# Sur Plesk / OVH / IONOS, depuis le shell utilisateur :
cd /var/www/vhosts/<domaine>/httpdocs/htdocs/custom/knot
ls examples/starter/
```

L'admin Dolibarr peut importer chaque fichier individuellement
depuis `?mode=workflows -> Import` puis lancer un test depuis
l'editeur (bouton "Run").

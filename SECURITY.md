# Politique de sécurité — Knot Core

## Versions supportées

| Branche | Support |
|---------|---------|
| **2.13.x** (bêta publique) | Correctifs sécurité et bugs |
| **&lt; 2.13** | Fin de support sauf hotfix critique négocié |

## Signaler une vulnérabilité

**Canal préféré :** [security@knot.tools](mailto:security@knot.tools)

Engagements :

- Accusé de réception sous **7 jours ouvrés**
- Correctif ou plan de mitigation cible sous **30 jours** pour les issues confirmées à impact élevé

Inclure dans votre rapport :

- Version Knot Core (`api/health.php` ou `modKnot.class.php`)
- Description et impact
- Étapes de reproduction
- Logs **sans** secrets ni données client

Ne pas ouvrir d’issue publique GitHub pour une vulnérabilité non corrigée.

## Intégrité des releases

- ZIP Knot Core : checksum SHA256 publié sur [knot.tools/downloads/data/releases.json](https://knot.tools/downloads/data/releases.json)
- Guide de vérification : [`website/downloads/VERIFY.md`](https://knot.tools/downloads/VERIFY.md) (dans le repo website)
- Signature Ed25519 : clé publique épinglée dans ce dépôt lorsque `signature_hex` est renseigné dans le manifest

## Engagements produit

- Credentials chiffres AES-256-GCM
- Aucun secret dans logs, exports ou UI
- API interne protégée par session Dolibarr, CSRF et droits Knot
- Audit log pour actions sensibles
- Aucune télémétrie sortante par défaut

## Secrets

Ne jamais committer `.env`, clés privées, tokens API, ni exports DB contenant des données client.

## Incident

Procédure détaillée : [`docs/security.md`](docs/security.md).

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

- ZIP Knot Core : checksum SHA256 publié dans [releases.json](https://knot.tools/downloads/data/releases.json)
- Guide de vérification : [knot.tools/downloads/verify/](https://knot.tools/downloads/verify/)
- Signature Ed25519 (releases **≥ 2.13.4**) : `signature_hex` et `signature_payload` dans le manifest ; clé publique épinglée **`rel-2026-04`** dans [`class/Licensing/PinnedPublicKeys.php`](class/Licensing/PinnedPublicKeys.php)

## Engagements produit

- Credentials chiffrés AES-256-GCM
- Aucun secret dans logs, exports ou UI
- API interne protégée par session Dolibarr, CSRF et droits Knot
- Audit log pour actions sensibles
- Aucune télémétrie sortante par défaut

## Secrets

Ne jamais committer `.env`, clés privées, tokens API, ni exports DB contenant des données client.

## Incident

Procédure détaillée : [`docs/security.md`](docs/security.md).

# Guide utilisateur Knot

Knot permet aux équipes Dolibarr de construire des **automatisations visuelles** directement dans Dolibarr, **sans service cloud obligatoire**. Ce guide résume comment tirer parti de Knot en limitant les risques.

## Bienvenue

Knot est un module Dolibarr (marque **Knot Tools**). Vous dessinez des workflows en nœuds (déclencheurs, actions, conditions) qui orchestrent les données Dolibarr et, avec les extensions compatibles, des API HTTP / SaaS. L’exécution reste Chez vous ; les secrets sont chiffrés au repos.

## Parcours recommandé

1. **Activer le module** — l’administrateur Dolibarr active Knot sous *Configuration → Modules* (onglet Custom).
2. **Terminer le wizard** — cron, droits, test SMTP, marqueur de première installation.
3. **Créer les identifiants** — *Credentials* conserve clés API, jetons OAuth, etc. (AES-256-GCM).
4. **Créer ou importer un workflow** — *Éditeur* pour le graphe, ou import JSON via *Assistant* / `.knot.json` / ZIP bulk.
5. **Simuler et inspecter** — *Simulate* exécute un parcours sec là où c’est supporté ; *Exécutions* affiche traces, journaux et rejeu.
6. **N’activer qu’après validation** — les workflows actifs tournent sur leurs déclencheurs (manuel, cron, webhooks, hooks Dolibarr). Vérifier les **niveaux de risque** avant activation (écriture, suppression, paiement).

## Fonctions principales

- **Éditeur visuel** — nœuds, liens, JSON de configuration, épinglage de données, mapping glisser-déposer.
- **Exécutions** — entrée/sortie par nœud (secrets masqués), durée, retries, rejeu depuis un nœud, contexte exportable.
- **Credentials** — création, mise à jour, test, stockage chiffré ; jamais journalisés en clair.
- **Assistant** — génère un prompt portable pour un chatbot, puis importe le JSON dans l’éditeur.
- **Approbations** — pause humaine via le nœud Approval et la boîte de réception associée.
- **Conflits** — signalement lorsque des workflows Knot chevauchent des automatisations natives ou des déclencheurs dupliqués.

## Bonnes pratiques

- **Source de vérité unique** — pour une règle métier, préférer l’automatisation native Dolibarr **ou** Knot, pas les deux sur le même événement sans garde-fous.
- **Garde-fous** — utiliser *Filtre* ou *Si / Sinon* pour éviter que plusieurs workflows écrivent la même fiche.
- **Épinglage** — utile en conception ; éviter les données de production épinglées en prod.
- **Erreurs** — en cas d’échec, ouvrir le détail du nœud ; Knot relie souvent les erreurs moteur à des indications documentées (`docs/troubleshooting.md` dans le module).

## Pour aller plus loin

- **Architecture & sécurité** — `docs/architecture.md`, `docs/security.md`
- **Connecteurs & extensibilité** — `docs/connectors.md`, `docs/extensibility.md`
- **Admins & bêta** — `docs/admin-guide.md`, pack bêta `docs/beta-testers/`
- **Référence courte EN** — `docs/user-guide.en.md`

## Support

Anomalies et suggestions : dépôt du module (voir `README.md` à la racine). Signalement sécurité : suivre `docs/security.md`.

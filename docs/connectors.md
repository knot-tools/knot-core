# Connecteurs Knot

## Source de vérité

- **Core** : classes enregistrées dans [`class/Connectors/ConnectorRegistry.php`](../class/Connectors/ConnectorRegistry.php) (`all()`). **31** connecteurs builtin depuis **V2.8.1** (palette « slim » : Dolibarr + logique + email + `notification.alert` audit-only + triggers génériques). Détail : [`docs/connectors-inventory.md`](connectors-inventory.md).
- **Extensions** : déclarés dans `knot-extension.json` de chaque module add-on (ex. **knot-pro-pack**). Les **29** ids listés dans [`ConnectorMigration::MIGRATED_TO_PRO_PACK`](../class/Migration/ConnectorMigration.php) nécessitent le Pro Pack : mêmes ids dans les workflows une fois le pack installé et licencié.

Voir aussi : [`docs/extensibility.md`](extensibility.md), [`docs/connector-authoring-guide.md`](connector-authoring-guide.md), `docs/introspection.md` pour `dolibarr.object`.

L'écran **Connecteurs** du module inclut un onglet *Nouveau connecteur* : aide MVP (non catalogue officiel) pour copier gabarits JSON et schémas `field_view=full`, en complément des connecteurs enregistrés.

## Contrat technique

Chaque connecteur implémente `Knot\Connectors\ConnectorInterface` :

- `getMetadata()`, `getConfigSchema()`, `getCredentialType()`, `getInputs()`, `getOutputs()`, `validate()`, `execute()`, `test()`

Découverte via l’attribut PHP 8 :

```php
#[Connector(id: 'dolibarr.object', category: 'dolibarr', version: '1.0')]
```

## Règles projet

- Pas de SQL direct dans les connecteurs (repositories / Dolibarr API)
- Aucun secret dans le JSON workflow
- Config validée par schéma
- Erreurs explicites, logs masqués (SecretMasker)
- Tests PHPUnit pour toute évolution significative
- Chaînes utilisateur via `$langs->trans()` (PHP) et alignement Vue (`langs/`)

## Inventaire Core (31) — par catégorie

Les `id` ci-dessous sont ceux retournés par `getMetadata()['id']`. Table détaillée : [`docs/connectors-inventory.md`](connectors-inventory.md).

### Triggers (4)

| id | Rôle court |
|----|------------|
| `trigger.manual` | Démarrage manuel |
| `trigger.cron` | Planification cron |
| `trigger.webhook` | Webhook générique **entrant** |
| `trigger.dolibarr_event` | Événement Dolibarr |

### Logique (21)

`logic.set`, `logic.filter`, `logic.if`, `logic.switch`, `logic.merge`, `logic.wait`, `logic.execute_workflow`, `logic.stop_error`, `logic.respond_webhook`, `logic.approval_wait`, `logic.loop`, `logic.while`, `logic.split`, `logic.array`, `logic.html`, `logic.xml`, `logic.crypto`, `logic.json`, `logic.string`, `logic.number`, `logic.date`

### Dolibarr (4)

| id | Rôle |
|----|------|
| `dolibarr.object` | CRUD / verbes via introspection |
| `dolibarr.specialized` | Actions Dolibarr ciblées legacy |
| `dolibarr.sql_query` | Requête SQL paramétrée (garde-fous) |
| `dolibarr.read_object` | Lecture typée |

### Communication & notification (2)

| id | Rôle |
|----|------|
| `action.email` | Envoi email (SMTP / conf Dolibarr) |
| `notification.alert` | **Audit uniquement** (`llx_knot_audit_log`) — pas de fan-out réseau dans le Core |

## Connecteurs Pro Pack (référence)

HTTP sortant générique (`action.http`), SFTP, Telegram, Slack, Discord, IA (dont Ollama), SaaS premium, webhooks nommés Stripe/Shopify, fan-out **`notification.alert_fanout`**, etc. : extension **knot-pro-pack**. Les **29** ids couverts par la migration depuis le Core sont exactement `ConnectorMigration::MIGRATED_TO_PRO_PACK`. D’autres connecteurs peuvent être **Pro-only** dès le manifest sans avoir été « migrés » depuis le Core.

Sans Pro Pack installé : la palette et l’exécution signalent l’extension manquante pour ces ids.

Guide d’utilisation WhatsApp (credentials, E.164, templates) :
[`docs/connectors/whatsapp.md`](connectors/whatsapp.md).

## DolibarrTrigger (objets)

Les déclencheurs `trigger.dolibarr_event` s’appuient sur les classes triggers Dolibarr et la configuration événement/objet dans l’éditeur. La liste exacte des codes et slugs évolue avec la version Dolibarr — voir [`docs/introspection.md`](introspection.md) et le cache d’introspection (Doctor).

## Anti-patterns rejetés

- Licence restrictive incompatible GPL projet
- Dépendance ou code dérivé n8n
- Daemon externe obligatoire non documenté
- « Cloud obligatoire » côté éditeur (Knot reste 100 % self-hosted)

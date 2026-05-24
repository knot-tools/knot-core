# Format Workflow Knot

## Objectif

Le format JSON Knot est proprietaire, versionne, documente et migrable. Il ne copie ni le code ni le format interne de n8n. Il remplace aussi les conventions incoherentes de l'ancien module (`from/to` cote UI, `source/target` cote moteur) par un contrat unique.

## Version Courante

- Version initiale : `1.0`
- Champ obligatoire : `schemaVersion`
- Toute evolution incompatible cree une migration documentee.

## Structure Canonique

```json
{
  "schemaVersion": "1.0",
  "workflow": {
    "ref": "WF_DEMO",
    "label": "Demo workflow",
    "description": "Manual demo workflow",
    "status": "draft",
    "tags": ["demo"],
    "settings": {
      "timeoutSeconds": 30,
      "retry": {
        "maxAttempts": 3,
        "strategy": "exponential",
        "initialDelaySeconds": 5
      },
      "singleInstance": false,
      "errorPolicy": "stop",
      "logPayloads": true
    }
  },
  "nodes": [
    {
      "id": "manual_1",
      "type": "trigger.manual",
      "label": "Manual Trigger",
      "position": { "x": 80, "y": 120 },
      "config": {},
      "credentials": null,
      "notes": ""
    },
    {
      "id": "set_1",
      "type": "logic.set",
      "label": "Prepare Data",
      "position": { "x": 360, "y": 120 },
      "config": {
        "values": {
          "message": "Hello {{$workflow.ref}}"
        }
      },
      "credentials": null,
      "notes": ""
    }
  ],
  "edges": [
    {
      "id": "edge_1",
      "source": "manual_1",
      "target": "set_1",
      "sourcePort": "main",
      "targetPort": "main",
      "type": "success"
    }
  ],
  "metadata": {
    "createdWith": "Knot",
    "createdAt": "2026-04-26T00:00:00Z",
    "updatedAt": "2026-04-26T00:00:00Z"
  }
}
```

## Nodes

Champs obligatoires :

- `id` : identifiant stable dans le workflow
- `type` : identifiant connecteur/node, par exemple `action.http`
- `label` : libelle utilisateur
- `position` : coordonnees editeur
- `config` : parametres valides par schema
- `credentials` : reference, jamais secret en clair

## Edges

Champs obligatoires :

- `id`
- `source`
- `target`
- `sourcePort`
- `targetPort`
- `type`

Valeurs `type` cibles :

- `success`
- `error`
- `true`
- `false`
- `branch`

Les anciennes conventions `from/to` sont refusees dans le format natif Knot. Elles pourront etre acceptees uniquement par un importeur legacy local et converties.

## Credentials

Le workflow JSON ne contient jamais :

- token API
- mot de passe
- secret webhook
- cle privee
- bearer token

Il contient uniquement une reference :

```json
{
  "credentials": {
    "credentialRef": "SLACK_MAIN",
    "required": true
  }
}
```

## Expressions

Expressions delimitees par `{{ ... }}`.

Variables cibles :

- `{{$json.field}}`
- `{{$node["Prepare Data"].json.message}}`
- `{{$workflow.ref}}`
- `{{$execution.id}}`
- `{{$now}}`
- `{{$env.VAR}}`
- `{{$vars.globalRef}}`

## Validation

- JSON valide
- `schemaVersion` connu
- IDs nodes uniques
- Edges source/target existants
- Un trigger minimum
- Pas de cycle sauf nodes de boucle explicites
- Config de chaque node validee par schema connecteur
- Credentials references existantes avant activation

## Migration

Chaque migration doit definir :

- version source
- version cible
- transformations
- pertes eventuelles
- test fixture

## Import n8n

L'import n8n sera ecrit from scratch. Il convertit uniquement les nodes mappables, produit un rapport d'avertissements et ne conserve aucun credential.

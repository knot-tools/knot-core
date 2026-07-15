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
      "sourceHandle": "main",
      "targetHandle": "main",
      "type": "knot"
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
- `sourceHandle` (ex. `main`, `true`, `false`, `iteration`, `done`, `error`)
- `targetHandle` (souvent `main`)
- `type` (canonique : `knot`)

Les handles de sortie depend du connecteur (`logic.if` → `true`/`false`, `logic.loop` → `iteration`/`done`, etc.).

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

- `{{$json.field}}` — sortie JSON du **dernier** nœud exécuté uniquement (pas une accumulation).
- `{{$nodes.<nodeId>.json.<field>}}` — sortie d’un nœud antérieur (chaînage après plusieurs lectures / branches).
- `{{$node["Prepare Data"].json.message}}` (alias historique)
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

> **Status: out of scope — not shipped.** Knot does **not** import n8n
> workflows. The product JSON format is proprietary. Mentions of a future
> from-scratch converter (no n8n code or dependency) remain backlog only
> with **no committed timeline**. Do not advertise n8n import in user-facing
> docs or marketing.

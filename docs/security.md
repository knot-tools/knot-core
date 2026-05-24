# Securite Knot

> Mise à jour V2.2 — defence-in-depth pour SQL, SSRF, secrets, CSRF, rate
> limiting, lock concurrent. Voir la section [V2.2 — Hardening](#v22--hardening)
> en bas de ce document.

## Principes

- Surface **sortante vers services tiers** arbitraires : voir [`network-egress.md`](network-egress.md) et [**ADR-017**](architecture/decisions/ADR-017-external-api-boundary-core-vs-pro-pack.md).
- 100% local par defaut
- Aucune telemetrie sortante
- Aucun credential en clair dans logs, exports ou UI
- API interne protegee par session Dolibarr, CSRF et permissions Knot
- Audit log pour toute action sensible
- Donnees sensibles masquees avant persistance si possible

## Threat Model

### Acteurs

- Administrateur Dolibarr legitime
- Utilisateur Dolibarr avec droits limites
- Editeur de workflows mal configure
- Systeme externe appelant un webhook
- Attaquant reseau tentant SSRF via connecteurs HTTP workflow (**Pro Pack**, via `Knot\Security\HttpClient`) ou webhooks mal filtres
- Fournisseur API externe compromis

### Actifs

- Credentials connecteurs
- Donnees tiers/contacts/factures
- Workflows actifs
- Webhook tokens
- Logs d'execution
- Cle de chiffrement locale

### Menaces Prioritaires

- Fuite de credential via logs ou exports
- Execution non autorisee de workflow
- Modification arbitraire d'objet Dolibarr
- SSRF via HttpAction/WebhookSend
- ReDoS via regex utilisateur
- CSRF sur API interne
- Webhook public abuse
- CodeNode PHP dangereux

## Credentials

- Chiffrement AES-256-GCM
- Cle derivee de `DOLIBARR_MAIN_INSTANCE_UNIQUE_ID` plus sel global Knot
- Sel cree par setup wizard
- Sel sauvegarde par l'admin client
- Dechiffrement uniquement a l'execution
- Rotation documentee et journalisee
- Si la cle est perdue, credentials irrécuperables, pas de backdoor

## Masquage

Champs sensibles par defaut :

- `password`
- `secret`
- `token`
- `api_key`
- `apikey`
- `authorization`
- `bearer`
- `private_key`
- `client_secret`

PII configurable :

- email
- telephone
- IBAN
- RIB
- numero fiscal ou identifiants locaux selon regex admin

## API Interne

- Pas de CORS permissif
- Pas de `Access-Control-Allow-Origin: *`
- Session Dolibarr obligatoire
- CSRF token obligatoire pour POST/PUT/PATCH/DELETE
- `GETPOST()` ou validateur centralise
- Permissions par endpoint
- Reponse erreur sans stack trace

### Detail execution (`error_payload`)

- Les reponses **`GET .../custom/knot/api/executions.php`** qui incluent une ligne execution (liste paginee, detail **`id=`**, reponses POST **`run_now`** qui renvoient la ligne) exposent **`errorMessage`** et, si present en base, **`errorPayload`** (JSON decode cote **`ExecutionRepository`** depuis **`llx_knot_execution.error_payload`**).
- Meme frontiere de confiance que les autres lectures execution : session Dolibarr obligatoire, droit **`knot` -> `workflow` -> `read`** (voir **`api/executions.php`**), et filtre **`$conf->entity`** sur toutes les requetes repository (pas de lecture cross-tenant).
- **`errorPayload`** est la forme structuree liee au catalogue d erreurs Knot (ADR-007, **`ExecutionErrorPayloadCodec`**) ; il ne doit pas, par design, reveler un secret hors bande par rapport au texte **`error_message`** / **`errorMessage`** deja visible par un operateur ayant ce droit. Un masquage supplementaire par role pour **`technical_message`** dans l UI n est pas prevu dans cette vague (voir **`docs/ux/error-display.md`**).

## Webhooks Entrants

- Token aleatoire unique
- HMAC SHA-256 optionnel mais recommande
- IP allowlist optionnelle
- Rate limit par webhook
- Timeout strict
- Journalisation payload masquee
- Workflow importe inactive par defaut si webhook present

## HttpAction Et SSRF

- Autoriser uniquement `http` et `https`
- Bloquer IP privees par defaut
- Bloquer metadata endpoints
- Bloquer localhost par defaut
- Timeout strict
- Taille reponse limitee
- Redirections limitees

## Sandbox CodeNode

- Desactivee par defaut tant que la policy n'est pas validee
- Activation reservee admin
- Whitelist fonctions PHP autorisees
- Blacklist : `eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_*`, `popen`, `pcntl_*`, `file_*`, `fopen`, `curl_*`, `mail`, `header`, `ini_set`, `putenv`, `dl`
- Limite memoire 32 MB
- Timeout 10 s
- Taille code max 10000 caracteres
- Hash SHA256 du code journalise

## Permissions Cibles

- `workflow.read`
- `workflow.create`
- `workflow.update`
- `workflow.delete`
- `workflow.execute`
- `workflow.manage_all`
- `credential.read`
- `credential.create`
- `credential.update`
- `credential.delete`
- `credential.use`
- `credential.manage_all`
- `execution.read`
- `execution.replay`
- `execution.cancel`
- `execution.delete_logs`
- `admin.configure_module`
- `admin.manage_security`
- `admin.view_audit`
- `admin.use_code_node`
- `admin.use_sql_node`

## Dolibarr `dolibarr.object` (mode expert)

`field_view=full` et `objectRegistryMode = discovery_unverified` ne rajoutent **aucune** origine reseau supplementaire : execution toujours cote serveur via le connecteur Dolibarr existant, sous les memes permissions workflow. Le risque est l'**integrite des donnees** (payload elargi aux metadonnees brutes `fields`, methodes de statut personnalisees) : a reserver aux utilisateurs avertis.

## RGPD Et Retention

- Logs execution : 30 jours par defaut
- Audit log : 90 jours minimum
- Payloads sensibles : persistance desactivable
- Export donnees workflow et execution documente
- Suppression ou anonymisation documentee

## Incidents

Tout incident critique implique :

1. Gel des releases non critiques.
2. Reproduction et qualification.
3. Patch avec test regression.
4. Changelog `security:`.
5. Notification clients concernes si donnees impactees.

## V2.2 — Hardening

V2.2 introduit une couche de defense-en-profondeur pour les vecteurs
d'attaque les plus realistes contre un connecteur d'automation. Tous les
controles sont actifs par defaut, sans configuration.

### SQL injection (defense-en-profondeur)

`Knot\Security\SqlSafetyAnalyzer` est appele deux fois par `dolibarr.sql_query` :

1. A la validation cote UI (avant resolution des `{{$json.x}}`).
2. A l'execution apres resolution des expressions, pour empecher
   l'injection via templating dynamique.

L'analyseur rejette :

- les requetes empilees (`;` hors string literal) ;
- les keywords mutants (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`,
  `TRUNCATE`, `CREATE`, `REPLACE`, `GRANT`, `REVOKE`, `LOCK`, `UNLOCK`,
  `RENAME`, `SET @`, `SET GLOBAL`) ;
- les fonctions d'exfiltration (`LOAD_FILE`, `INTO OUTFILE`,
  `INTO DUMPFILE`, `BENCHMARK`, `SLEEP`, `EXTRACTVALUE`, `UPDATEXML`) ;
- les schemas systeme (`information_schema.*`, `mysql.*`, `sys.*`,
  `performance_schema.*`) ;
- les tables internes Knot ultra-sensibles (`llx_knot_credential`,
  `llx_user`).

Le `stripStringLiterals()` ignore le contenu des chaines `'...'` et
`"..."`, des commentaires `--`, `#` et `/* ... */` pour eviter les
faux-positifs.

### SSRF (UrlPolicy)

`Knot\Security\UrlPolicy` est utilise par `Knot\Security\HttpClient`
(donc tout connecteur `action.http`, `webhook.send`, etc.). Defense
multi-couches :

1. **Schema allowlist** : seuls `http` et `https` sont acceptes.
2. **Hostname denylist hard-codee** : `localhost`, `127.0.0.1`,
   `0.0.0.0`, `::1`, `metadata.google.internal`, `metadata.azure.com`.
3. **Resolution DNS + filtre IP** : rejette `10.0.0.0/8`, `172.16/12`,
   `192.168/16`, `169.254/16`, loopback, IPv6 ULA. Echec DNS = refus.
4. **IP blocklist hard-codee** : `169.254.169.254` (AWS/Azure/DO/OVH),
   `169.254.170.2` (ECS), `fd00:ec2::254` (AWS IPv6).
5. **Allowlist/denylist admin** :
   - `MAIN_KNOT_HTTP_ALLOWLIST` : hosts qui contournent le filtre IP
     (legitime pour un gateway interne pin par hostname).
   - `MAIN_KNOT_HTTP_DENYLIST` : prevaut sur l'allowlist.
   - Wildcards supportes : `*.evil.com`.
6. **Re-validation des redirects** : `CURLOPT_FOLLOWLOCATION=false`,
   chaque hop est validate par `UrlPolicy`. Bloque l'attaque
   `public.com → 169.254.169.254`.

Configuration :

```
MAIN_KNOT_HTTP_ALLOWLIST = "internal-gateway.local, *.our-saas.com"
MAIN_KNOT_HTTP_DENYLIST  = "*.evil.com, malicious-host.example"
```

### SecretMasker (logs et audit)

`Knot\Security\SecretMasker` masque tout secret avant log/audit/UI.
Deux passes :

**Pass 1 — clé** : substring match (apres normalisation `_-` strip
et lowercase) sur ~30 tokens : `password`, `passwd`, `pwd`, `secret`,
`token`, `apikey`, `apitoken`, `authkey`, `authsecret`,
`authorization`, `bearer`, `privatekey`, `clientsecret`, `sessionkey`,
`sessionid`, `cookie`, `csrf`, `xoxb`, `xoxp`, `webhooksecret`,
`signingkey`, `signingsecret`, `accesstoken`, `refreshtoken`,
`idtoken`, `pat`, `serviceaccount`, `credential`, `credentials`.

**Pass 2 — valeur** : 11 patterns regex sur les valeurs string :

- `Bearer xxx`, `Basic xxx`
- JWT (`eyJ…ey…sig`)
- AWS access keys (`AKIA…`, `ASIA…`)
- Slack tokens (`xox[abprs]-…`)
- GitHub PAT (`ghp_…`, `gho_…`, `github_pat_…`)
- Stripe (`sk_live_…`, `pk_live_…`, `sk_test_…`)
- Google API (`AIza…`)
- `user:password@host`

Les deux passes sont recursives sur arrays / objets imbriques.

### Lock concurrent par workflow

Si un workflow a `single_instance: true` dans sa definition, le
`CronWorker` verifie `ExecutionRepository::countRunningForWorkflow`
avant de demarrer une execution. Si une instance est deja `running`
ou `queued`, la nouvelle est differee (re-essayee au prochain tick).
Empêche les race conditions sur les workflows non-idempotents
(facturation, envoi email, etc.).

### CSRF

Tous les endpoints API mutants (`POST`, `PUT`, `PATCH`, `DELETE`)
verifient `Knot\Api\CsrfGuard::verify()`, qui accepte le token
Dolibarr standard (`?token=…`) ou le header `X-Csrf-Token` injecte par
le frontend Vue. Endpoints couverts : `workflows`, `credentials`,
`folders`, `variables`, `schedules`, `executions`, `execute`,
`templates`, `approvals`, `webhooks`. Endpoints publics
(`webhook` HMAC + IP allowlist, `oauth` callback session-state) ont
leur propre verification.

### Rate limiting

`api/execute.php` est rate-limite par utilisateur via
`Knot\Security\RateLimiter` (60 req/min par defaut). Configurable :

```
MAIN_KNOT_EXECUTE_RATE_LIMIT_PER_MIN = 120
```

Empeche un compte compromis de :

- DoS Dolibarr en saturant le worker ;
- Brûler du budget SaaS (Stripe / Twilio / OpenAI) en quelques secondes ;
- Carpet-bomber les tiers d'un workflow `notification.alert`.

Reponse `429 Too Many Requests` avec header `Retry-After` standard.
Les webhooks publics ont deja leur propre rate limit (configurable
par webhook, defaut 60 req/min).

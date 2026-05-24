# Standards De Code Knot

## PHP

- PHP 8.1+
- PSR-12 strict
- `declare(strict_types=1)` dans tout code applicatif
- Namespace `Knot\`
- Type hints et return types partout ou possible
- Docblock complet sur toute methode publique
- Commentaires code en anglais
- Strings utilisateur via fichiers de langue Dolibarr

## Dolibarr

- `GETPOST()` plutot que `$_GET` / `$_POST`
- APIs Dolibarr natives pour DB, droits, logs, cron, setup
- Multi-entite systematique avec `entity`
- Permissions cote UI et cote API
- Pas de logique metier lourde dans les pages admin

## SQL

- Acces DB via `class/Repository/`
- Pas de `SELECT *`
- Index sur colonnes de filtre principales
- Scripts install/upgrade/uninstall documentes
- Utiliser `MAIN_DB_PREFIX` dans les scripts SQL
- Ne jamais hardcoder `entity = 1`
- Scoper cron et purge par entite, sauf decision documentee

## Version module (semver)

La version visible dans l’UI suit `Knot\Version::current()` → `modKnot::$version`. À chaque bump, synchroniser aussi `class/Version::FALLBACK` et `frontend/package.json` ; voir `AGENTS.md` § « Version affichée » et `tests/Version/VersionConsistencyTest.php`.

## Frontend

- Vue 3 + Vue Flow + Pinia + CodeMirror 6 + TypeScript
- Pas de React
- Pas de jQuery ajoute
- Pas d'inline JS dans PHP
- Assets compiles livres avec le module
- Le format edge canonique est `source` / `target`, jamais `from` / `to`
- Pas de deuxieme modele node parallele non synchronise
- **Immutabilité (V2.8)** : ne pas muter en place les refs Vue Flow (`nodes`/`edges`) ni le state Pinia ; préférer spreads et `setNodes`/`setEdges`. Script : `node scripts/audit-vue-immutability.mjs`. Lint ciblé : `npm run lint` dans `frontend/` (règle `vue/no-mutating-props`).

## Documentation

- `CHANGELOG.md` a chaque changement livrable
- `LICENSES.md` a chaque dependance
- `docs/llm/decisions.md` a chaque decision structurante

## Securite

- Pas de CORS `*` sur API interne
- CSRF sur toute mutation
- SSRF guards pour toute URL utilisateur
- Masquage centralise avant log
- Exports sans credentials

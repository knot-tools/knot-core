# Licences Et Dependances Knot

## Politique

Dependances autorisees :

- MIT
- Apache 2.0
- BSD
- ISC
- LGPL
- CC0

Dependances refusees :

- SUL
- fair-code
- Commons Clause
- BSL
- Elastic License
- AGPL sauf accord explicite
- Toute licence a restriction commerciale incompatible Dolistore

## Interdiction n8n

Aucun code copie depuis n8n. Aucune dependance `n8n-core`, `@n8n/*` ou derivee.

## Procedure d'ajout d'une dependance

Avant d'ajouter toute dependance npm ou composer :

1. Verifier la licence : MIT, Apache 2.0, BSD, ISC, LGPL ou CC0 obligatoire (cf. politique ci-dessus).
2. Refuser : SUL, fair-code, Commons Clause, BSL, AGPL sauf accord explicite, Elastic License.
3. Tracer l'ajout dans le Registre ci-dessous : nom, version, licence, lien repo, raison de l'ajout.
4. Si la licence est ambigue, ne pas ajouter et demander une validation explicite.

## Registre

| Nom | Version | Licence | Type | Lien | Raison |
| --- | --- | --- | --- | --- | --- |
| Paramiko | Non versionne localement | LGPL | Outil dev optionnel | https://www.paramiko.org/ | Scripts locaux SSH/SFTP Plesk, non inclus comme dependance produit |
| Vue | Version lockee dans `frontend/package-lock.json` | MIT | Frontend produit | https://vuejs.org/ | Interface Knot |
| Vite | Version lockee dans `frontend/package-lock.json` | MIT | Build frontend | https://vitejs.dev/ | Bundling assets Dolibarr |
| Tailwind CSS | Version lockee dans `frontend/package-lock.json` | MIT | Frontend produit | https://tailwindcss.com/ | Design system utilitaire prefixe `k-` |
| Vue Flow | Version lockee dans `frontend/package-lock.json` | MIT | Frontend produit | https://vueflow.dev/ | Editeur visuel de workflows |
| @dagrejs/dagre | Version lockee dans `frontend/package-lock.json` | MIT | Frontend produit | https://github.com/dagrejs/dagre | Layout automatique graphes orientes (auto-layout editeur Vue Flow). ESM natif requis pour Vite 8 / Rollup 5 (le package legacy `dagre` CJS casse l'IIFE bundle). |
| Lucide Vue Next | Version lockee dans `frontend/package-lock.json` | ISC | Frontend produit | https://lucide.dev/ | Iconographie |
| ESLint | Version lockee dans `frontend/package-lock.json` | MIT | Dev frontend | https://eslint.org/ | Lint immutabilité Vue (`vue/no-mutating-props`) |
| eslint-plugin-vue | Version lockee dans `frontend/package-lock.json` | MIT | Dev frontend | https://eslint.vuejs.org/ | Règles Vue pour ESLint |
| typescript-eslint | Version lockee dans `frontend/package-lock.json` | MIT | Dev frontend | https://typescript-eslint.io/ | Parser TypeScript pour ESLint |
| vue-eslint-parser | Version lockee dans `frontend/package-lock.json` | MIT | Dev frontend | https://github.com/vuejs/vue-eslint-parser | Parse SFC `.vue` dans ESLint |
| globals (npm) | Version lockee dans `frontend/package-lock.json` | MIT | Dev frontend | https://github.com/sindresorhus/globals | Globals navigateur pour ESLint |
| phpstan/phpstan | ^2.1 (composer.lock) | MIT | Dev PHP | https://github.com/phpstan/phpstan | Analyse statique L6 (CI non bloquante tant que dette > 0) |
| @axe-core/playwright | Version lockee dans `tests/e2e/package-lock.json` | MPL-2.0 | Dev E2E | https://github.com/dequelabs/axe-core-npm | Audits a11y Playwright (specs `a11y-critical-screens`) |

## Notes

Les outils globaux Cursor/MCP personnels ne sont pas des dependances produit. Toute dependance npm ou composer versionnee dans le repo devra etre ajoutee ici avant merge.

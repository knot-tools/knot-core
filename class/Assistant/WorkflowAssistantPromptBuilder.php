<?php

declare(strict_types=1);

namespace Knot\Assistant;

use Knot\Dolibarr\DolibarrTableCatalog;

/**
 * Builds the LLM system prompt for {@see /knot/api/assistant.php}.
 */
final class WorkflowAssistantPromptBuilder
{
    /**
     * @param list<array<string, mixed>> $connectorCatalog
     */
    public function build(string $userRequest, array $connectorCatalog, ?\DoliDB $db = null, ?object $langs = null): string
    {
        $jsonFence = '```';
        $jsonExample = json_encode([
            'knotExport' => '1.0',
            'workflow' => [
                'label' => 'Nom du workflow',
                'description' => 'Description',
                'definition' => [
                    'schemaVersion' => '1.0',
                    'workflow' => ['label' => 'Nom du workflow'],
                    'nodes' => [],
                    'edges' => [],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tableCatalog = new DolibarrTableCatalog();
        $objects = $tableCatalog->objectsForPrompt($db, $langs);
        $tableLines = [];
        foreach ($objects as $row) {
            $tableLines[] = sprintf(
                '- %s (slug `%s`): %s',
                $row['label'],
                $row['slug'],
                implode(', ', $row['tables'])
            );
        }

        return "Tu es un expert Knot, module Dolibarr d'automatisation visuelle.\n"
            . "Objectif: aider l'utilisateur a construire un workflow JSON Knot valide, importable tel quel.\n\n"
            . "==== DEMANDE UTILISATEUR ====\n"
            . $userRequest . "\n"
            . "==== FIN DEMANDE ====\n\n"
            . "Regles strictes:\n"
            . "- Quand le workflow est pret, reponds par UN SEUL bloc de code Markdown delimite par "
            . $jsonFence . "json en ouverture et " . $jsonFence . " en fermeture, contenant exclusivement le JSON valide.\n"
            . "- Avant ce bloc final, tu peux poser des questions a l'utilisateur si une information manque, en texte libre.\n"
            . "- schemaVersion doit valoir \"1.0\".\n"
            . "- Chaque node a id (string unique), type (id de connecteur), label, subtitle, position {x,y}, config (objet).\n"
            . "- Chaque edge a id, source (id node), target (id node), sourceHandle, targetHandle.\n"
            . "- Utilise uniquement les types de connecteurs listes dans le catalogue ci-dessous.\n"
            . "- Toute reference a une donnee provenant d'un node precedent doit utiliser la syntaxe d'expression "
            . "{{\$json.<chemin>}} (ex: {{\$json.invoice.id}}).\n"
            . "- Pour parcourir une collection avec logic.loop, mets config.realIteration=true et config.itemsPath=\"{{\$json.items}}\". "
            . "Le sous-graphe d'iteration part du sourceHandle \"iteration\" ; la suite du workflow part du sourceHandle \"done\". "
            . "Dans le corps de la boucle, l'item courant est accessible via {{\$json}} et l'index via {{\$json._loopIndex}}.\n"
            . "- Pour gerer les erreurs d'un node, ajoute un edge avec sourceHandle=\"error\" vers un node de fallback (ex: communication.email pour alerter, logic.stop_and_error pour stopper).\n"
            . "- Pour appeler un autre workflow Knot, utilise subworkflow.run avec config.workflowRef et config.payload.\n"
            . "- Pour idempotence (eviter les doublons sur retries), pose config.idempotencyKey sur les nodes critiques (ex: action.stripe).\n"
            . "- Triggers Dolibarr disponibles : trigger.manual (manuel), trigger.cron (cron), trigger.webhook (HTTP), trigger.dolibarr_event (evenements natifs Dolibarr), trigger.stripe_webhook + trigger.shopify_webhook (HMAC verifie cote serveur).\n"
            . "- Connecteurs Dolibarr : dolibarr.object (CRUD generique sur tiers/facture/commande/etc.), dolibarr.read_object (lecture), dolibarr.specialized (operations metier comme valider une facture, generer un PDF, calculer un total).\n"
            . "- Pour gerer un consentement humain : human.approval pose une demande dans la file et bloque tant que personne n'a approuve.\n\n"
            . "==== REGLES SQL (dolibarr.sql_query) ====\n"
            . "- SELECT uniquement ; tables reelles Dolibarr prefixees llx_ (jamais inventer de noms).\n"
            . "- Propales / devis : slug dolibarr.object `propal` → tables **llx_propal** et lignes **llx_propaldet** — **jamais** llx_propale (faute frequente).\n"
            . "- Tiers : slug `thirdparty` → table **llx_societe** (pas llx_thirdparty).\n"
            . "- Statut propale en SQL : colonne **fk_statut** (pas `statut` seul sur llx_propal).\n"
            . "- Multi-entite Dolibarr : filtrer avec `entity IN (0, <entity_courante>)` ou `entity = <entity_courante>` sur les tables qui ont une colonne entity.\n"
            . "- Catalogue tables aligne sur ObjectFactory / dolibarr_schemas (extrait):\n"
            . implode("\n", $tableLines) . "\n\n"
            . "Format minimal du JSON a renvoyer (a adapter):\n"
            . $jsonExample
            . "\n\nCatalogue connecteurs disponibles (JSON):\n"
            . json_encode($connectorCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

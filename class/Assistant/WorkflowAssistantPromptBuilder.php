<?php

declare(strict_types=1);

namespace Knot\Assistant;

use Knot\Dolibarr\DolibarrTableCatalog;

/**
 * Builds the external chatbot prompt for {@see /knot/api/assistant.php}.
 *
 * Tier 1: rules + anti-patterns + hello-world + exhaustive available connector specs
 * + contextual recipe + Dolibarr events. No full JSON catalog dump.
 */
final class WorkflowAssistantPromptBuilder
{
    private const HELLO_WORLD_PATH = '/examples/starter/01-hello-world.knot.json';

    /**
     * @param list<array<string, mixed>> $compactConnectors from AssistantConnectorPromptCatalog
     */
    public function build(
        string $userRequest,
        array $compactConnectors,
        ?\DoliDB $db = null,
        ?object $langs = null,
        string $locale = 'fr_FR'
    ): string {
        $jsonFence = '```';
        $helloWorld = $this->loadHelloWorldExample();
        $connectorBlock = AssistantConnectorPromptCatalog::formatForPrompt($compactConnectors);
        $recipe = $this->recipeForRequest($userRequest);
        $events = $this->dolibarrEventsBlock();
        $tables = $this->sqlTablesBlock($db, $langs);
        $localeInstruction = $this->localeInstruction($locale, $userRequest);
        $antiPatterns = $this->antiPatternsBlock();

        $availableIds = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            $compactConnectors
        );
        $idList = implode(', ', $availableIds);

        return "Tu es un expert Knot — module Dolibarr d'automatisation visuelle.\n"
            . "OBJECTIF: produire UN workflow JSON Knot Dolibarr **exclusif** — importable tel quel.\n"
            . "INTERDIT: Zapier, Make, n8n, formats `trigger`/`steps`, ids inventés.\n\n"
            . $localeInstruction . "\n\n"
            . "==== DEMANDE UTILISATEUR ====\n"
            . $userRequest . "\n"
            . "==== FIN DEMANDE ====\n\n"
            . "==== REGLES STRICTES ====\n"
            . "- Reponse finale = UN SEUL bloc Markdown {$jsonFence}json ... {$jsonFence} avec JSON valide uniquement.\n"
            . "- Avant ce bloc, tu peux poser des questions si une info manque.\n"
            . "- schemaVersion = \"1.0\".\n"
            . "- Chaque node: id, type, label, subtitle, position {x,y}, config.\n"
            . "- Chaque edge: id, source, target, sourceHandle, targetHandle (pas from/to).\n"
            . "- Utilise UNIQUEMENT ces ids connecteurs: {$idList}\n"
            . "- Expressions Knot: {{\$json.chemin}} = sortie du **dernier** noeud uniquement ; "
            . "pour reutiliser un objet lu plus tot: {{\$nodes.<nodeId>.json.<champ>}} ; date: {{\$now}}.\n"
            . "- Pour tout champ avec une liste de valeurs entre [ ] dans CONNECTEURS DISPONIBLES, "
            . "utiliser **exactement** une de ces valeurs — ne jamais traduire ni inventer "
            . "(ex: objectType=facture, pas invoice ; operator=greater_equal, pas >=).\n"
            . "- logic.loop: realIteration=true, itemsPath=\"{{\$json.items}}\" ; handles iteration/done.\n"
            . "- logic.if: config.conditions[] (pas condition string) ; operator dans l'enum "
            . "(equals|not_equals|contains|not_contains|greater|greater_equal|less|less_equal|"
            . "is_empty|is_not_empty|regex) ; handles true/false.\n"
            . "- dolibarr.sql_query: champ **query** (pas sql).\n"
            . "- Email gratuit Dolibarr: **action.email** (pas communication.email, pas gmail.send).\n"
            . "- trigger.dolibarr_event: events[] obligatoire ; objectTypes[] optionnel (filtre inerte cote moteur).\n\n"
            . $this->dataFlowBlock() . "\n\n"
            . $antiPatterns . "\n\n"
            . "==== EXEMPLE COMPLET (hello-world) ====\n"
            . $helloWorld . "\n\n"
            . "==== CONNECTEURS DISPONIBLES (specs) ====\n"
            . $connectorBlock . "\n\n"
            . "==== RECETTE SUGGEREE POUR CETTE DEMANDE ====\n"
            . $recipe . "\n\n"
            . "==== EVENEMENTS DOLIBARR FREQUENTS ====\n"
            . $events . "\n\n"
            . "==== VOCABULAIRE OBJETS CANONIQUE (objectType) ====\n"
            . "- objectType pour dolibarr.read_object et dolibarr.object = **slug** entre backticks ci-dessous.\n"
            . "- N'utilise **jamais** le nom de classe PHP (Facture, Societe) ni un equivalent anglais (invoice, order).\n"
            . "- Apres trigger.dolibarr_event: objectId={{\$json.objectId}} ; objectType litteral du slug (ex: facture).\n"
            . "- **Ne jamais** mettre {{\$json.objectType}} dans un champ objectType (c'est une classe PHP, pas un slug).\n"
            . $tables . "\n\n"
            . "==== REGLES SQL (dolibarr.sql_query) ====\n"
            . "- SELECT uniquement ; tables llx_* reelles.\n"
            . "- Propales: slug `propal` → llx_propal / llx_propaldet (jamais llx_propale).\n"
            . "- Tiers: slug `thirdparty` → llx_societe.\n"
            . "- Statut propale: fk_statut.\n"
            . "- Multi-entite: entity IN (0, <entity>) sur tables concernees.";
    }

    private function loadHelloWorldExample(): string
    {
        $root = dirname(__DIR__, 2);
        $path = $root . self::HELLO_WORLD_PATH;
        if (!is_readable($path)) {
            return '{"knotExport":"1.0","workflow":{"label":"Hello","definition":{"schemaVersion":"1.0","nodes":[],"edges":[]}}}';
        }

        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $raw;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $raw;
    }

    private function antiPatternsBlock(): string
    {
        return "==== ANTI-PATTERNS (INTERDIT) ====\n"
            . "- Format Zapier/Make: { trigger, steps } — utiliser nodes[] + edges[].\n"
            . "- Types inventes: sql.query, gmail.send, communication.email, scheduler.wait_until, action.http_request.\n"
            . "- logic.if avec config.condition (string) — utiliser conditions[] avec left/operator/right.\n"
            . "- dolibarr.sql_query avec config.sql — utiliser config.query.\n"
            . "- Ids Pro Pack non listes dans CONNECTEURS DISPONIBLES.\n"
            . "- Newlines non echappees dans les strings JSON.";
    }

    private function dolibarrEventsBlock(): string
    {
        $events = [
            'BILL_CREATE', 'BILL_VALIDATE', 'BILL_PAYED', 'BILL_CANCEL', 'BILL_MODIFY',
            'ORDER_CREATE', 'ORDER_VALIDATE', 'ORDER_CLOSE', 'ORDER_MODIFY',
            'PROPAL_CREATE', 'PROPAL_VALIDATE', 'PROPAL_CLOSE_SIGNED', 'PROPAL_MODIFY',
            'COMPANY_CREATE', 'COMPANY_MODIFY', 'CONTACT_CREATE', 'CONTACT_MODIFY',
            'PRODUCT_CREATE', 'PRODUCT_MODIFY', 'PROJECT_CREATE', 'TASK_CREATE',
            'PAYMENT_CUSTOMER_CREATE', 'TICKET_CREATE', 'EXPEDITION_VALIDATE',
        ];

        return implode(', ', $events);
    }

    private function sqlTablesBlock(?\DoliDB $db, ?object $langs): string
    {
        $catalog = new DolibarrTableCatalog();
        $objects = $catalog->objectsForPrompt($db, $langs);
        $lines = [];
        foreach ($objects as $row) {
            $lines[] = sprintf(
                '- %s (`%s`): %s',
                $row['label'],
                $row['slug'],
                implode(', ', $row['tables'])
            );
        }

        return implode("\n", $lines);
    }

    private function recipeForRequest(string $userRequest): string
    {
        $q = mb_strtolower($userRequest);

        if ($this->matchesAny($q, ['facture', 'invoice', 'bill validate', 'bill_validate', 'iban', 'bancaire'])) {
            return $this->recipeBillValidateEmail();
        }
        if ($this->matchesAny($q, ['cron', 'quotidien', 'daily', 'relance', 'impaye', 'impayé'])) {
            return $this->recipeCronSqlEmail();
        }
        if ($this->matchesAny($q, ['webhook', 'http entrant', 'callback'])) {
            return $this->recipeWebhook();
        }
        if ($this->matchesAny($q, ['boucle', 'loop', 'foreach', 'pour chaque'])) {
            return $this->recipeLoop();
        }
        if ($this->matchesAny($q, ['condition', ' si ', 'if ', 'sinon'])) {
            return $this->recipeIf();
        }
        if ($this->matchesAny($q, ['email', 'mail', 'courriel', 'smtp'])) {
            return $this->recipeEmail();
        }
        if ($this->matchesAny($q, ['sql', 'requete', 'requête', 'select ', 'base de donnees'])) {
            return $this->recipeSql();
        }

        return "Graphe minimal: trigger.manual → logic.set (optionnel) → action cible.\n"
            . "Verifier handles sourceHandle/targetHandle = main sauf branches if/loop/error.";
    }

    /**
     * @param list<string> $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function recipeBillValidateEmail(): string
    {
        return "1. trigger.dolibarr_event: events=[\"BILL_VALIDATE\"] (objectTypes optionnel, filtre inerte)\n"
            . "2. dolibarr.read_object: objectType=\"facture\", objectId=\"{{\$json.objectId}}\" "
            . "(expose total_ttc, fk_soc, fk_account). Pour logic.if ou un 2e read_object: "
            . "{{\$nodes.<read_facture_id>.json.total_ttc}} — pas {{\$json.total_ttc}} apres un autre noeud.\n"
            . "3. dolibarr.sql_query (optionnel IBAN): SELECT iban_prefix AS iban FROM llx_bank_account WHERE rowid = "
            . "{{\$nodes.<read_facture_id>.json.fk_account}} — lire IBAN via {{\$nodes.<sql_id>.json.rows.0.iban}} "
            . "(ou {{\$json.rows.0.iban}} juste apres le noeud SQL), jamais rows[0].\n"
            . "4. action.email: to={{email client}}, subject/body avec montant + IBAN (sauts de ligne reels ou <br>)\n"
            . "5. Relance J+30 = workflow SEPARE trigger.cron (voir starter 02), pas wait 30j dans le meme run.";
    }

    private function dataFlowBlock(): string
    {
        return "==== FLUX DE DONNEES (INVARIANT) ====\n"
            . "- {{\$json}} = sortie JSON du **dernier** noeud execute (pas une accumulation).\n"
            . "- {{\$nodes.<nodeId>.json.<champ>}} = sortie d'un noeud anterieur (chainage apres plusieurs lectures).\n"
            . "- trigger.dolibarr_event payload: objectId (int), objectType (nom classe PHP ex Facture), objectRef.\n"
            . "- dolibarr.read_object / dolibarr.object fetch: objectType = slug canonique litteral ; "
            . "objectId/id = {{\$json.objectId}} depuis le trigger.\n"
            . "- logic.if sur un champ d'objet lu: left={{\$nodes.<readId>.json.<champ>}}, "
            . "operator dans l'enum (ex greater_equal), right=litteral.";
    }

    private function recipeCronSqlEmail(): string
    {
        return "1. trigger.cron: expression cron (ex: 0 8 * * *)\n"
            . "2. dolibarr.sql_query: config.query SELECT ... impayes\n"
            . "3. logic.loop: itemsPath=\"{{\$json.rows}}\" realIteration=true\n"
            . "4. action.email dans branche iteration\n"
            . "5. Edge loop: sourceHandle iteration → corps ; sourceHandle done → fin.";
    }

    private function recipeWebhook(): string
    {
        return "1. trigger.webhook\n"
            . "2. logic.set pour normaliser payload\n"
            . "3. dolibarr.object create/update si besoin\n"
            . "4. logic.respond_to_webhook en fin si reponse HTTP requise.";
    }

    private function recipeLoop(): string
    {
        return "logic.loop + edges sourceHandle iteration/done ; item courant = {{\$json}} dans iteration.";
    }

    private function recipeIf(): string
    {
        return "logic.if: mode all|any, conditions[{left, operator, right}] ; edges true/false.";
    }

    private function recipeEmail(): string
    {
        return "action.email: to, subject, body requis ; SMTP Dolibarr deja configure cote instance.";
    }

    private function recipeSql(): string
    {
        return "dolibarr.sql_query: config.query (SELECT), resultat dans sortie node pour expressions suivantes.";
    }

    private function localeInstruction(string $locale, string $userRequest): string
    {
        $lang = match (true) {
            str_starts_with($locale, 'en') => 'English',
            str_starts_with($locale, 'es') => 'Spanish',
            str_starts_with($locale, 'de') => 'German',
            str_starts_with($locale, 'it') => 'Italian',
            str_starts_with($locale, 'pt') => 'Portuguese',
            default => 'French',
        };

        return "LANGUE: reponds en {$lang} (labels workflow + textes utilisateur). "
            . "Le JSON reste en structure Knot standard.";
    }
}

<?php

declare(strict_types=1);

namespace Knot\Assistant;

use Knot\Dolibarr\DolibarrTableCatalog;

/**
 * Optional Tier 2 copy-paste block (schemas overflow + SQL tables).
 */
final class AssistantTechnicalAnnex
{
    private const TOKEN_CHAR_BUDGET = 48_000;

    /**
     * @param list<array<string, mixed>> $overflowConnectors compact rows not inlined in Tier 1
     */
    public function build(
        array $overflowConnectors,
        ?\DoliDB $db = null,
        ?object $langs = null
    ): string {
        if ($overflowConnectors === []) {
            return '';
        }

        $catalog = new DolibarrTableCatalog();
        $objects = $catalog->objectsForPrompt($db, $langs);
        $tableLines = [];
        foreach ($objects as $row) {
            $tableLines[] = sprintf(
                '- %s (`%s`): %s',
                $row['label'],
                $row['slug'],
                implode(', ', $row['tables'])
            );
        }

        $connectorBlock = AssistantConnectorPromptCatalog::formatForPrompt($overflowConnectors);

        return "==== ANNEXE TECHNIQUE KNOT (Tier 2 — optionnel) ====\n"
            . "Coller APRES le prompt principal si le chatbot demande plus de détails.\n\n"
            . "==== CONNECTEURS (specs détaillées) ====\n"
            . $connectorBlock
            . "\n\n==== TABLES SQL DOLIBARR ====\n"
            . implode("\n", $tableLines);
    }

    /**
     * Rough char budget guard (1 token ~ 4 chars).
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    public static function exceedsBudget(string $text, int $maxTokens = 16_000): bool
    {
        return self::estimateTokens($text) > $maxTokens;
    }

    public static function maxTier1Chars(): int
    {
        return self::TOKEN_CHAR_BUDGET;
    }
}

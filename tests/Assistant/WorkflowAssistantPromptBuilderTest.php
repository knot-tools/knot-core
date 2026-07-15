<?php

declare(strict_types=1);

namespace Knot\Tests\Assistant;

use Knot\Assistant\AssistantConnectorPromptCatalog;
use Knot\Assistant\WorkflowAssistantPromptBuilder;
use Knot\Connectors\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

final class WorkflowAssistantPromptBuilderTest extends TestCase
{
    public function testPromptIncludesSqlRulesAndAntiPatterns(): void
    {
        $prompt = $this->buildPrompt('Lister les devis ouverts', []);

        self::assertStringContainsString('REGLES SQL (dolibarr.sql_query)', $prompt);
        self::assertStringContainsString('ANTI-PATTERNS', $prompt);
        self::assertStringContainsString('llx_propal', $prompt);
        self::assertStringContainsString('steps', $prompt);
        self::assertStringContainsString('ANTI-PATTERNS', $prompt);
        self::assertStringContainsString('conditions[]', $prompt);
    }

    public function testPromptPrefersNodesExpressionsOverJsonChain(): void
    {
        $prompt = $this->buildPrompt('panier loop email total', []);

        self::assertStringContainsString('expression_json_chain', $prompt);
        self::assertStringContainsString('$nodes.<setOrSqlId>.json.items', $prompt);
        self::assertStringContainsString('$loop.item', $prompt);
        self::assertStringContainsString('preferer toujours', $prompt);
        // Cron recipe (when selected) also teaches nodes-based rows path.
        $cronPrompt = $this->buildPrompt('cron quotidien relance impaye', []);
        self::assertStringContainsString('$nodes.sql_impayes.json.rows', $cronPrompt);
    }

    public function testPromptEmbedsHelloWorldNotEmptyNodes(): void
    {
        $prompt = $this->buildPrompt('Test', []);

        self::assertStringContainsString('trigger_manual', $prompt);
        self::assertStringContainsString('logic.set', $prompt);
        self::assertStringNotContainsString('"nodes": []', $prompt);
    }

    public function testPromptIncludesBillValidateRecipeForInvoiceRequest(): void
    {
        $prompt = $this->buildPrompt(
            'Quand une facture est validee envoyer un email avec IBAN',
            []
        );

        self::assertStringContainsString('BILL_VALIDATE', $prompt);
        self::assertStringContainsString('action.email', $prompt);
    }

    public function testPromptContainsAllCoreConnectorIds(): void
    {
        $registry = new ConnectorRegistry();
        $compact = AssistantConnectorPromptCatalog::fromLoadedConnectors($registry->all());
        $prompt = $this->buildPrompt('workflow test', $compact);

        foreach (array_keys($registry->all()) as $id) {
            self::assertStringContainsString($id, $prompt, "Missing core connector id: {$id}");
        }
    }

    public function testPromptDoesNotEmbedFullJsonCatalogDump(): void
    {
        $registry = new ConnectorRegistry();
        $compact = AssistantConnectorPromptCatalog::fromLoadedConnectors($registry->all());
        $prompt = $this->buildPrompt('Build workflow', $compact);

        self::assertStringNotContainsString('Catalogue connecteurs disponibles (JSON):', $prompt);
    }

    public function testInvoiceEmailPromptStaysUnderTokenBudget(): void
    {
        $registry = new ConnectorRegistry();
        $compact = AssistantConnectorPromptCatalog::fromLoadedConnectors($registry->all());
        $prompt = $this->buildPrompt(
            'facture validee email IBAN bancaire',
            $compact
        );

        // ~4 chars/token → 8k tokens ≈ 32k chars; full Core catalog stays pasteable.
        self::assertLessThan(32000, mb_strlen($prompt));
        self::assertStringContainsString('BILL_VALIDATE', $prompt);
        self::assertStringContainsString('action.email', $prompt);
    }

    /**
     * @param list<array<string, mixed>> $compactConnectors
     */
    private function buildPrompt(string $userRequest, array $compactConnectors): string
    {
        if ($compactConnectors === []) {
            $registry = new ConnectorRegistry();
            $compactConnectors = AssistantConnectorPromptCatalog::fromLoadedConnectors($registry->all());
        }

        return (new WorkflowAssistantPromptBuilder())->build($userRequest, $compactConnectors, null, null, 'fr_FR');
    }
}

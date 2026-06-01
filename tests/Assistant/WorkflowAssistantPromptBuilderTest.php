<?php

declare(strict_types=1);

namespace Knot\Tests\Assistant;

use Knot\Assistant\WorkflowAssistantPromptBuilder;
use PHPUnit\Framework\TestCase;

final class WorkflowAssistantPromptBuilderTest extends TestCase
{
    public function testPromptIncludesSqlRulesAndPropalTableNames(): void
    {
        $prompt = (new WorkflowAssistantPromptBuilder())->build(
            'Lister les devis ouverts',
            [],
            null,
            null
        );

        self::assertStringContainsString('REGLES SQL (dolibarr.sql_query)', $prompt);
        self::assertStringContainsString('llx_propal', $prompt);
        self::assertStringContainsString('jamais** llx_propale', $prompt);
        self::assertStringContainsString('fk_statut', $prompt);
        self::assertStringContainsString('entity IN (0, <entity_courante>)', $prompt);
    }

    public function testPromptIncludesTableCatalogFromMap(): void
    {
        $prompt = (new WorkflowAssistantPromptBuilder())->build('Test', [], null, null);

        self::assertStringContainsString('slug `propal`', $prompt);
        self::assertStringContainsString('llx_propaldet', $prompt);
        self::assertStringContainsString('slug `thirdparty`', $prompt);
        self::assertStringContainsString('llx_societe', $prompt);
    }

    public function testPromptEmbedsConnectorCatalogJson(): void
    {
        $catalog = [
            [
                'metadata' => ['id' => 'trigger.manual', 'labelKey' => 'connectors.trigger.manual.label'],
                'configSchema' => ['type' => 'object'],
                'credentialType' => null,
                'inputs' => [],
                'outputs' => [],
            ],
        ];

        $prompt = (new WorkflowAssistantPromptBuilder())->build('Build workflow', $catalog, null, null);

        self::assertStringContainsString('Catalogue connecteurs disponibles (JSON):', $prompt);
        self::assertStringContainsString('trigger.manual', $prompt);
    }
}

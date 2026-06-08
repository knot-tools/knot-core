<?php

declare(strict_types=1);

namespace Knot\Tests\Assistant;

use Knot\Assistant\AssistantConnectorPromptCatalog;
use Knot\Connectors\ConnectorRegistry;
use PHPUnit\Framework\TestCase;

final class AssistantConnectorPromptCatalogTest extends TestCase
{
    public function testFormatForPromptPrintsInlineEnumValues(): void
    {
        $registry = new ConnectorRegistry();
        $compact = AssistantConnectorPromptCatalog::fromLoadedConnectors($registry->all());
        $text = AssistantConnectorPromptCatalog::formatForPrompt($compact);

        self::assertStringContainsString('objectType[facture|commande|propal|thirdparty|contact|product|project]', $text);
        self::assertStringContainsString('logic.if', $text);
        self::assertStringContainsString('mode[all|any]', $text);
    }
}

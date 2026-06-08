<?php

declare(strict_types=1);

namespace Knot\Tests\Assistant;

use Knot\Assistant\AssistantConnectorPromptCatalog;
use Knot\Assistant\WorkflowAssistantPromptBuilder;
use Knot\Connectors\ConnectorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantCoreScenariosTest extends TestCase
{
    /**
     * @return \Generator<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function coreScenarioProvider(): \Generator
    {
        yield 'invoice validated email' => [
            'Quand une facture est validee envoyer un email avec IBAN',
            ['BILL_VALIDATE', 'action.email', 'trigger.dolibarr_event'],
            ['gmail.send', 'communication.email'],
        ];
        yield 'cron overdue reminder' => [
            'Relance quotidienne des factures impayees par cron',
            ['trigger.cron', 'dolibarr.sql_query', 'logic.loop', 'action.email'],
            ['scheduler.wait_until'],
        ];
        yield 'incoming webhook' => [
            'Declencher un workflow sur webhook entrant',
            ['trigger.webhook'],
            ['gmail.send'],
        ];
        yield 'foreach loop' => [
            'Pour chaque ligne SQL faire une boucle',
            ['logic.loop', 'itemsPath'],
            ['sql.query'],
        ];
        yield 'conditional branch' => [
            'Si le montant depasse 1000 alors envoyer un mail',
            ['logic.if', 'conditions[]', 'action.email'],
            ['config.condition'],
        ];
        yield 'smtp email only' => [
            'Envoyer un courriel SMTP au client',
            ['action.email'],
            ['gmail.send'],
        ];
        yield 'sql select' => [
            'Requete SQL select sur les propales ouvertes',
            ['dolibarr.sql_query', 'config.query', 'llx_propal'],
            ['sql.query', 'config.sql'],
        ];
        yield 'manual hello' => [
            'Workflow manuel simple pour tester',
            ['trigger.manual'],
            ['sql.query'],
        ];
    }

    /**
     * @param list<string> $mustContain
     * @param list<string> $mustNotRecommend
     */
    #[DataProvider('coreScenarioProvider')]
    public function testCoreOnlyScenarioPrompt(string $userRequest, array $mustContain, array $mustNotRecommend): void
    {
        $registry = new ConnectorRegistry();
        $compact = AssistantConnectorPromptCatalog::fromLoadedConnectors($registry->all());
        $prompt = (new WorkflowAssistantPromptBuilder())->build($userRequest, $compact, null, null, 'fr_FR');

        self::assertStringContainsString('ANTI-PATTERNS', $prompt);

        foreach ($mustContain as $needle) {
            self::assertStringContainsString($needle, $prompt, "Scenario « {$userRequest} » missing: {$needle}");
        }

        foreach ($mustNotRecommend as $bad) {
            self::assertStringContainsString($bad, $prompt, "Anti-pattern {$bad} must be listed as forbidden");
            self::assertStringContainsString('INTERDIT', $prompt);
        }
    }
}

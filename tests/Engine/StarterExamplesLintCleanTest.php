<?php

declare(strict_types=1);

namespace Knot\Tests\Engine;

use Knot\Engine\WorkflowValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pre-Dolistore gate: every shipped starter must be free of
 * expression_json_chain warnings (and validation errors).
 */
final class StarterExamplesLintCleanTest extends TestCase
{
    public function testAllStarterExamplesHaveZeroExpressionJsonChainWarnings(): void
    {
        $root = dirname(__DIR__, 2) . '/examples/starter';
        self::assertDirectoryExists($root);

        $files = glob($root . '/*.knot.json');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        $validator = new WorkflowValidator();
        $failures = [];

        foreach ($files as $path) {
            $json = file_get_contents($path);
            self::assertNotFalse($json, 'Unreadable: ' . $path);
            /** @var array<string, mixed> $envelope */
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $definition = $envelope['workflow']['definition'] ?? null;
            self::assertIsArray($definition, 'Missing workflow.definition in ' . basename($path));

            $issues = $validator->validateAll($definition);
            $chain = [];
            $errors = [];
            foreach ($issues as $issue) {
                if (($issue['severity'] ?? '') === 'error') {
                    $errors[] = $issue;
                }
                if (
                    ($issue['messageKey'] ?? '') === 'expression_json_chain'
                    || ($issue['code'] ?? '') === 'KNOT_DSL_EXPRESSION_JSON_CHAIN'
                ) {
                    $chain[] = $issue;
                }
            }

            if ($chain !== [] || $errors !== []) {
                $failures[basename($path)] = [
                    'expression_json_chain' => count($chain),
                    'errors' => count($errors),
                    'sample' => array_slice(array_merge($errors, $chain), 0, 3),
                ];
            }
        }

        self::assertSame(
            [],
            $failures,
            'Starter examples must be lint-clean for Dolistore: ' . json_encode($failures, JSON_PRETTY_PRINT)
        );
    }
}

<?php

declare(strict_types=1);

namespace Knot\Compatibility\Versioning;

final class MigrationReportGenerator
{
    /**
     * @param list<array<string, mixed>>                           $diff
     * @param list<array<string, mixed>>                           $breaking
     * @param list<array<string, mixed>>                           $workflowHints
     * @param array{dolibarr_from?:string, dolibarr_to?:string} $meta
     */
    public function generateMarkdown(array $diff, array $breaking, array $workflowHints, array $meta = []): string
    {
        $from = (string) ($meta['dolibarr_from'] ?? 'baseline');
        $to = (string) ($meta['dolibarr_to'] ?? 'target');

        $lines = [];
        $lines[] = '# Knot schema compatibility report';
        $lines[] = '';
        $lines[] = sprintf('- Baseline: `%s`', $from);
        $lines[] = sprintf('- Target: `%s`', $to);
        $lines[] = '';

        $lines[] = '## Structural changes';
        $lines[] = '';
        if ($diff === []) {
            $lines[] = '_No structural differences detected in captured pilots._';
        } else {
            foreach ($diff as $row) {
                $lines[] = '- `' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
            }
        }
        $lines[] = '';

        $lines[] = '## Breaking-change highlights';
        $lines[] = '';
        $heavy = array_values(array_filter($breaking, static fn (array $b): bool => ($b['severity'] ?? '') === 'breaking'));
        if ($heavy === []) {
            $lines[] = '_No changes classified as definitely breaking._';
        } else {
            foreach ($heavy as $row) {
                $lines[] = '- `' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
            }
        }
        $lines[] = '';

        $lines[] = '## Workflow hints';
        $lines[] = '';
        if ($workflowHints === []) {
            $lines[] = '_No impacted dolibarr.object nodes detected from supplied workflows._';
        } else {
            foreach ($workflowHints as $row) {
                $lines[] = '- `' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
            }
        }
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }
}

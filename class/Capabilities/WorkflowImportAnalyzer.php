<?php

declare(strict_types=1);

namespace Knot\Capabilities;

/**
 * Workflow import compatibility hints against a capability manifest.
 */
final class WorkflowImportAnalyzer
{
    /**
     * @param array<int, mixed>           $workflowItems Same shape as import_bulk payloads
     * @param array<string, mixed>        $capabilities  Output of CapabilitiesBuilder::build()
     *
     * @return list<array<string, mixed>>
     */
    public static function analyze(array $workflowItems, array $capabilities): array
    {
        $objectIndex = [];
        foreach ($capabilities['objects']['list'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            if ($slug !== '') {
                $objectIndex[$slug] = $row;
            }
        }

        $warnings = [];

        foreach ($workflowItems as $idx => $item) {
            $wf = is_array($item['workflow'] ?? null) ? $item['workflow'] : (is_array($item) ? $item : []);
            $label = (string) ($wf['label'] ?? ('workflow_' . $idx));
            $definition = is_array($wf['definition'] ?? null) ? $wf['definition'] : [];
            $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = (string) ($node['type'] ?? '');
                if ($type !== 'dolibarr.object') {
                    continue;
                }
                $config = is_array($node['config'] ?? null) ? $node['config'] : [];
                $objectType = trim((string) ($config['objectType'] ?? ''));
                if ($objectType === '') {
                    continue;
                }
                $info = $objectIndex[$objectType] ?? null;
                if ($info === null) {
                    $warnings[] = [
                        'severity' => 'warning',
                        'workflow_label' => $label,
                        'code' => 'OBJECT_UNKNOWN',
                        'messageKey' => 'object_unknown',
                        'messageParams' => [
                            'workflowLabel' => $label,
                            'objectType' => $objectType,
                        ],
                        'object_type' => $objectType,
                    ];
                    continue;
                }
                if (($info['module_status'] ?? '') === 'inactive') {
                    $mod = $info['module_required'] ?? '?';
                    $warnings[] = [
                        'severity' => 'warning',
                        'workflow_label' => $label,
                        'code' => 'MODULE_INACTIVE',
                        'messageKey' => 'module_inactive',
                        'messageParams' => [
                            'workflowLabel' => $label,
                            'objectType' => $objectType,
                            'module' => (string) $mod,
                        ],
                        'object_type' => $objectType,
                        'module' => $mod,
                    ];
                }
            }

            if (($capabilities['features']['state_machine_formal'] ?? false) !== true) {
                foreach ($nodes as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    if (($node['type'] ?? '') === 'dolibarr.object') {
                        $cfg = is_array($node['config'] ?? null) ? $node['config'] : [];
                        if (($cfg['operation'] ?? '') === 'change_status') {
                            $warnings[] = [
                                'severity' => 'info',
                                'workflow_label' => $label,
                                'code' => 'STATE_MACHINE_ADVISORY',
                                'messageKey' => 'state_machine_advisory',
                                'messageParams' => [
                                    'workflowLabel' => $label,
                                ],
                            ];
                            break;
                        }
                    }
                }
            }
        }

        return $warnings;
    }
}

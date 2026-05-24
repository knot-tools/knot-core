<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * Loop over an array of items.
 *
 * Two execution modes:
 *  - batch (default, V1): the node simply emits {items, count} on the main
 *    handle; downstream nodes receive the full array and decide what to do.
 *  - real iteration (V1.5, opt-in via config.realIteration === true): the
 *    engine treats this node as an iterator. The "iteration" handle marks
 *    the body that will be re-executed once per item; the "done" handle
 *    fires after every item has been processed with the aggregated output.
 */
#[Connector(id: 'logic.loop', category: 'logic')]
final class LoopNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.loop',
            'labelKey' => 'connectors.logic.loop.label',
            'descriptionKey' => 'connectors.logic.loop.description',
            'category' => 'logic',
            'riskLevel' => 'safe',
            'reversible' => true,
            'sideEffects' => [],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'itemsPath' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.loop.fields.itemsPath.title',
                    'descriptionKey' => 'connectors.logic.loop.fields.itemsPath.description',
                    'x-position' => 0,
                ],
                'realIteration' => [
                    'type' => 'boolean',
                    'titleKey' => 'connectors.logic.loop.fields.realIteration.title',
                    'default' => false,
                    'descriptionKey' => 'connectors.logic.loop.fields.realIteration.description',
                    'x-position' => 1,
                ],
                'continueOnItemError' => [
                    'type' => 'boolean',
                    'titleKey' => 'connectors.logic.loop.fields.continueOnItemError.title',
                    'default' => true,
                    'descriptionKey' => 'connectors.logic.loop.fields.continueOnItemError.description',
                    'x-position' => 2,
                ],
            ],
        ];
    }

    public function getCredentialType(): ?string
    {
        return null;
    }

    public function getInputs(): array
    {
        return [['id' => 'main', 'label' => 'Main']];
    }

    public function getOutputs(): array
    {
        return [
            ['id' => 'iteration', 'label' => 'For each item'],
            ['id' => 'done', 'label' => 'Done (aggregated)'],
        ];
    }

    public function validate(array $config): array
    {
        return ['valid' => true, 'errors' => []];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();

        $items = $this->resolveItems($config, $context, $resolver);
        return [
            'items' => $items,
            'count' => count($items),
            'loopMode' => empty($config['realIteration']) ? 'batch' : 'iteration',
        ];
    }

    public function test(array $config): array
    {
        return ['valid' => true, 'errors' => [], 'success' => true];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array<int, mixed>
     */
    private function resolveItems(array $config, array $context, ExpressionResolver $resolver): array
    {
        $rawPath = (string) ($config['itemsPath'] ?? '');
        if ($rawPath !== '') {
            // When the value is exactly `{{ path }}`, bypass the string resolver
            // and pull the raw array from the context to preserve types.
            if (preg_match('/^\s*\{\{\s*([^}]+)\s*\}\}\s*$/', $rawPath, $m) === 1) {
                $direct = $this->lookupPath(trim($m[1]), $context);
                if (is_array($direct)) {
                    return array_values($direct);
                }
            }
            $resolved = $resolver->resolve($rawPath, $context);
            if (is_array($resolved)) {
                return array_values($resolved);
            }
            if (is_string($resolved) && $resolved !== '') {
                $decoded = json_decode($resolved, true);
                if (is_array($decoded)) {
                    return array_values($decoded);
                }
            }
        }
        $json = is_array($context['json'] ?? null) ? $context['json'] : [];
        if (is_array($json['items'] ?? null)) {
            return array_values($json['items']);
        }
        if ($json !== [] && array_is_list($json)) {
            return $json;
        }
        return [];
    }

    /** @param array<string, mixed> $context */
    private function lookupPath(string $path, array $context): mixed
    {
        $path = ltrim($path, '$');
        $path = preg_replace('/^\./', '', $path) ?? $path;
        $segments = explode('.', $path);
        $current = $context;
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}

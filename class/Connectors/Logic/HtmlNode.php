<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use DOMDocument;
use DOMXPath;
use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;

#[Connector(id: 'logic.html', category: 'logic')]
final class HtmlNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.html',
            'labelKey' => 'connectors.logic.html.label',
            'descriptionKey' => 'connectors.logic.html.description',
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
                'html' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.html.fields.html.title',
                    'descriptionKey' => 'connectors.logic.html.fields.html.description',
                    'format' => 'html',
                    'x-position' => 0,
                ],
                'xpath' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.html.fields.xpath.title',
                    'default' => '//text()',
                    'descriptionKey' => 'connectors.logic.html.fields.xpath.description',
                    'x-position' => 1,
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
        return [['id' => 'main', 'label' => 'Main']];
    }
    public function validate(array $config): array
    {
        return ['valid' => true, 'errors' => []];
    }
    public function execute(array $context): array
    {
        $config = is_array(($context['node']['config'] ?? null)) ? $context['node']['config'] : [];
        $html = (string) ($config['html'] ?? $context['json']['body'] ?? '');
        $xpath = (string) ($config['xpath'] ?? '//text()');
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $nodes = (new DOMXPath($doc))->query($xpath);
        $values = [];
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $values[] = trim($node->textContent);
            }
        }
        return ['values' => array_values(array_filter($values, static fn (string $v): bool => $v !== ''))];
    }
    public function test(array $config): array
    {
        return ['valid' => true, 'errors' => [], 'success' => true];
    }
}

<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use SimpleXMLElement;

#[Connector(id: 'logic.xml', category: 'logic')]
final class XmlNode implements ConnectorInterface
{
    public function getMetadata(): array
    {
        return [
            'id' => 'logic.xml',
            'labelKey' => 'connectors.logic.xml.label',
            'descriptionKey' => 'connectors.logic.xml.description',
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
                'xml' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.xml.fields.xml.title',
                    'descriptionKey' => 'connectors.logic.xml.fields.xml.description',
                    'x-position' => 0,
                ],
                'xpath' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.xml.fields.xpath.title',
                    'default' => '/*',
                    'descriptionKey' => 'connectors.logic.xml.fields.xpath.description',
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
        $xml = (string) ($config['xml'] ?? $context['json']['body'] ?? '<root/>');
        $xpath = (string) ($config['xpath'] ?? '/*');
        $doc = new SimpleXMLElement($xml);
        $nodes = $doc->xpath($xpath) ?: [];
        return ['values' => array_map(static fn ($node): string => (string) $node, $nodes)];
    }
    public function test(array $config): array
    {
        return ['valid' => true, 'errors' => [], 'success' => true];
    }
}

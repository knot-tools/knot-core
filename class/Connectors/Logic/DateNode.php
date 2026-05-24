<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Engine\ExpressionResolver;

/**
 * Date utilities — parse, format and shift datetime values.
 *
 * Operations:
 *  - now            : current ISO-8601 datetime
 *  - format         : reformat `input` using `format`
 *  - add / subtract : modify `input` by `interval` (PHP DateInterval string)
 *  - diff           : difference between `input` and `other` (in seconds)
 */
#[Connector(id: 'logic.date', category: 'logic')]
final class DateNode implements ConnectorInterface
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.date',
            'labelKey' => 'connectors.logic.date.label',
            'descriptionKey' => 'connectors.logic.date.description',
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
            'required' => ['operation'],
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.date.fields.operation.title',
                    'enum' => ['now', 'format', 'add', 'subtract', 'diff'],
                    'x-position' => 0,
                ],
                'input' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.date.fields.input.title',
                    'descriptionKey' => 'connectors.logic.date.fields.input.description',
                    'x-position' => 1,
                ],
                'other' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.date.fields.other.title',
                    'descriptionKey' => 'connectors.logic.date.fields.other.description',
                    'x-position' => 2,
                ],
                'format' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.date.fields.format.title',
                    'default' => 'c',
                    'descriptionKey' => 'connectors.logic.date.fields.format.description',
                    'x-position' => 3,
                ],
                'interval' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.date.fields.interval.title',
                    'default' => 'P1D',
                    'descriptionKey' => 'connectors.logic.date.fields.interval.description',
                    'x-position' => 4,
                ],
                'timezone' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.date.fields.timezone.title',
                    'default' => 'UTC',
                    'x-position' => 5,
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
        return ['valid' => isset($config['operation']), 'errors' => []];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $tz = new DateTimeZone((string) ($config['timezone'] ?? 'UTC'));
        $format = (string) ($config['format'] ?? 'c');
        $operation = (string) ($config['operation'] ?? 'now');

        try {
            $input = (string) $resolver->resolve((string) ($config['input'] ?? 'now'), $context);
            $date = new DateTimeImmutable($input ?: 'now', $tz);
        } catch (Exception) {
            return ['value' => null, 'error' => 'invalid_date'];
        }

        switch ($operation) {
            case 'now':
                return ['value' => (new DateTimeImmutable('now', $tz))->format($format)];
            case 'format':
                return ['value' => $date->format($format)];
            case 'add':
                try {
                    return [
                        'value' => $date->add(new \DateInterval((string) ($config['interval'] ?? 'P1D')))->format($format),
                    ];
                } catch (Exception) {
                    return ['value' => null, 'error' => 'invalid_interval'];
                }
            case 'subtract':
                try {
                    return [
                        'value' => $date->sub(new \DateInterval((string) ($config['interval'] ?? 'P1D')))->format($format),
                    ];
                } catch (Exception) {
                    return ['value' => null, 'error' => 'invalid_interval'];
                }
            case 'diff':
                try {
                    $other = (string) $resolver->resolve((string) ($config['other'] ?? 'now'), $context);
                    $otherDate = new DateTimeImmutable($other ?: 'now', $tz);
                    return ['seconds' => $otherDate->getTimestamp() - $date->getTimestamp()];
                } catch (Exception) {
                    return ['value' => null, 'error' => 'invalid_other'];
                }
        }

        return ['value' => $date->format($format)];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }
}

<?php

declare(strict_types=1);

namespace Knot\Connectors\Notification;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Engine\ExpressionResolver;
use Knot\Repository\AuditLogRepository;
use Throwable;

/**
 * Audit-only alert connector (`notification.alert`).
 *
 * Multi-channel fan-out (Slack, Discord, email, generic webhook) is shipped
 * by the Knot Pro Pack (`notification.alert_fanout`). Core keeps a minimal
 * audit trail write for compliance without outbound network calls.
 */
#[Connector(id: 'notification.alert', category: 'notification')]
final class AlertAction implements ConnectorInterface, DryRunAware
{
    private const SEVERITIES = ['info', 'warn', 'error', 'critical'];

    public function __construct(
        private readonly ?ExpressionResolver $resolver = null,
    ) {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'notification.alert',
            'labelKey' => 'connectors.notification.alert.label',
            'descriptionKey' => 'connectors.notification.alert.description',
            'category' => 'notification',
            'riskLevel' => 'safe',
            'reversible' => true,
            'sideEffects' => [],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'message'],
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.notification.alert.fields.title.title',
                    'descriptionKey' => 'connectors.notification.alert.fields.title.description',
                    'x-position' => 0,
                ],
                'message' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.notification.alert.fields.message.title',
                    'descriptionKey' => 'connectors.notification.alert.fields.message.description',
                    'format' => 'textarea',
                    'x-position' => 1,
                ],
                'severity' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.notification.alert.fields.severity.title',
                    'enum' => self::SEVERITIES,
                    'enumLabelKeys' => [
                        'connectors.notification.alert.severity.info',
                        'connectors.notification.alert.severity.warn',
                        'connectors.notification.alert.severity.error',
                        'connectors.notification.alert.severity.critical',
                    ],
                    'default' => 'warn',
                    'x-position' => 2,
                ],
                'tag' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.notification.alert.fields.tag.title',
                    'descriptionKey' => 'connectors.notification.alert.fields.tag.description',
                    'x-position' => 3,
                ],
                'channels' => [
                    'type' => 'object',
                    'titleKey' => 'connectors.notification.alert.fields.channels.title',
                    'descriptionKey' => 'connectors.notification.alert.fields.channels.description',
                    'x-position' => 4,
                    'properties' => [
                        'audit' => [
                            'type' => 'object',
                            'properties' => [
                                'enabled' => ['type' => 'boolean', 'default' => true],
                            ],
                        ],
                    ],
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
        $errors = [];
        if (!isset($config['title']) || trim((string) $config['title']) === '') {
            $errors[] = 'title is required';
        }
        if (!isset($config['message']) || trim((string) $config['message']) === '') {
            $errors[] = 'message is required';
        }
        if (isset($config['severity']) && !in_array((string) $config['severity'], self::SEVERITIES, true)) {
            $errors[] = 'severity must be one of: ' . implode(', ', self::SEVERITIES);
        }
        $channels = is_array($config['channels'] ?? null) ? $config['channels'] : [];
        foreach (array_keys($channels) as $name) {
            if ($name !== 'audit') {
                $errors[] = sprintf(
                    'Channel "%s" is not available in Knot Core. Install Pro Pack and use connector notification.alert_fanout.',
                    (string) $name
                );
            }
        }
        if (!isset($channels['audit']) || !is_array($channels['audit'])) {
            $errors[] = 'channels.audit must be configured';
        }
        return ['valid' => $errors === [], 'errors' => $errors];
    }

    public function execute(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();

        $title = trim((string) $resolver->resolve((string) ($config['title'] ?? ''), $context));
        $message = (string) $resolver->resolve((string) ($config['message'] ?? ''), $context);
        $severity = $this->normaliseSeverity((string) ($config['severity'] ?? 'warn'));
        $tag = trim((string) $resolver->resolve((string) ($config['tag'] ?? ''), $context));
        $channels = is_array($config['channels'] ?? null) ? $config['channels'] : [];

        if (!isset($channels['audit']) || !is_array($channels['audit']) || !((bool) ($channels['audit']['enabled'] ?? true))) {
            return [
                'severity' => $severity,
                'title' => $title,
                'tag' => $tag,
                'channels' => ['audit' => ['ok' => false, 'error' => 'audit channel disabled']],
                'delivered' => 0,
                'failed' => 1,
                'allOk' => false,
            ];
        }

        $audit = $this->writeAudit($title, $message, $severity, $tag, $context);
        $ok = (bool) ($audit['ok'] ?? false);

        return [
            'severity' => $severity,
            'title' => $title,
            'tag' => $tag,
            'channels' => ['audit' => $audit],
            'delivered' => $ok ? 1 : 0,
            'failed' => $ok ? 0 : 1,
            'allOk' => $ok,
        ];
    }

    public function test(array $config): array
    {
        return $this->validate($config) + ['success' => true];
    }

    public function simulate(array $context): array
    {
        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        return [
            '_dryRun' => true,
            'severity' => $this->normaliseSeverity((string) ($config['severity'] ?? 'warn')),
            'title' => (string) ($config['title'] ?? ''),
            'channelsThatWouldFire' => ['audit'],
            'message' => '[DRY-RUN] would write audit log entry',
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function writeAudit(string $title, string $message, string $severity, string $tag, array $context): array
    {
        global $db, $user, $conf;

        if (!isset($db) || !is_object($db)) {
            return ['ok' => false, 'error' => 'db not available'];
        }
        $entity = isset($conf->entity) ? (int) $conf->entity : 1;
        $userId = isset($user->id) ? (int) $user->id : null;

        try {
            $repo = new AuditLogRepository($db);
            $ok = $repo->record(
                'alert',
                'workflow_alert',
                null,
                $userId,
                [
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $message,
                    'tag' => $tag,
                    'workflowId' => $context['_workflowId'] ?? null,
                    'executionId' => $context['_executionId'] ?? null,
                ],
                $entity
            );
            return ['ok' => (bool) $ok];
        } catch (Throwable $t) {
            return ['ok' => false, 'error' => $t->getMessage()];
        }
    }

    private function normaliseSeverity(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, self::SEVERITIES, true) ? $value : 'warn';
    }
}

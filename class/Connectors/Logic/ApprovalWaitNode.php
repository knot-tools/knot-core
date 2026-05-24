<?php

declare(strict_types=1);

namespace Knot\Connectors\Logic;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Engine\ExpressionResolver;
use Knot\Repository\ApprovalRepository;

#[Connector(id: 'logic.approval_wait', category: 'logic')]
final class ApprovalWaitNode implements ConnectorInterface, DryRunAware
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'logic.approval_wait',
            'labelKey' => 'connectors.logic.approval_wait.label',
            'descriptionKey' => 'connectors.logic.approval_wait.description',
            'category' => 'logic',
            'riskLevel' => 'caution',
            'reversible' => true,
            'sideEffects' => ['mail'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['message'],
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.approval_wait.fields.message.title',
                    'descriptionKey' => 'connectors.logic.approval_wait.fields.message.description',
                    'format' => 'textarea',
                    'x-position' => 0,
                ],
                'approverRole' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.logic.approval_wait.fields.approverRole.title',
                    'descriptionKey' => 'connectors.logic.approval_wait.fields.approverRole.description',
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
        return [
            'valid' => trim((string) ($config['message'] ?? '')) !== '',
            'errors' => trim((string) ($config['message'] ?? '')) !== '' ? [] : ['message is required'],
        ];
    }

    public function execute(array $context): array
    {
        global $db, $conf, $user;

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();
        $message = (string) $resolver->resolve((string) ($config['message'] ?? 'Approval required'), $context);
        $approvalId = (new ApprovalRepository($db))->create(
            isset($context['execution']['id']) ? (int) $context['execution']['id'] : null,
            (string) ($node['id'] ?? ''),
            $message,
            isset($user->id) ? (int) $user->id : null,
            trim((string) ($config['approverRole'] ?? '')) ?: null,
            (int) $conf->entity
        );

        return [
            'waitingApproval' => true,
            'approvalId' => $approvalId,
            'message' => $message,
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
        $message = (string) ($config['message'] ?? 'Approval required');
        return [
            '_dryRun' => true,
            'waitingApproval' => false,
            'approvalId' => -1,
            'message' => $message,
            'note' => '[DRY-RUN] Would create an approval request and pause the workflow until a human responds.',
        ];
    }
}

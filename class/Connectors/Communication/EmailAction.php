<?php

declare(strict_types=1);

namespace Knot\Connectors\Communication;

use Knot\Connectors\Connector;
use Knot\Connectors\ConnectorInterface;
use Knot\Connectors\DryRunAware;
use Knot\Engine\ExpressionResolver;
use RuntimeException;

/**
 * Send an email via Dolibarr's native CMailFile abstraction.
 *
 * Uses whatever transport is configured in Dolibarr (sendmail, smtp, …).
 * Configuration:
 *  - to (string, comma separated)
 *  - cc, bcc
 *  - subject
 *  - body (HTML)
 *  - replyTo
 */
#[Connector(id: 'action.email', category: 'communication')]
final class EmailAction implements ConnectorInterface, DryRunAware
{
    public function __construct(private readonly ?ExpressionResolver $resolver = null)
    {
    }

    public function getMetadata(): array
    {
        return [
            'id' => 'action.email',
            'labelKey' => 'connectors.action.email.label',
            'descriptionKey' => 'connectors.action.email.description',
            'category' => 'communication',
            'riskLevel' => 'caution',
            'reversible' => false,
            'sideEffects' => ['mail'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['to', 'subject', 'body'],
            'properties' => [
                'to' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.action.email.fields.to.title',
                    'descriptionKey' => 'connectors.action.email.fields.to.description',
                    'placeholder' => 'ops@example.com',
                    'x-position' => 0,
                ],
                'cc' => ['type' => 'string', 'titleKey' => 'connectors.action.email.fields.cc.title', 'x-position' => 1],
                'bcc' => ['type' => 'string', 'titleKey' => 'connectors.action.email.fields.bcc.title', 'x-position' => 2],
                'subject' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.action.email.fields.subject.title',
                    'x-position' => 3,
                ],
                'body' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.action.email.fields.body.title',
                    'descriptionKey' => 'connectors.action.email.fields.body.description',
                    'format' => 'html',
                    'x-position' => 4,
                ],
                'replyTo' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.action.email.fields.replyTo.title',
                    'format' => 'email',
                    'x-position' => 5,
                ],
                'fromOverride' => [
                    'type' => 'string',
                    'titleKey' => 'connectors.action.email.fields.fromOverride.title',
                    'descriptionKey' => 'connectors.action.email.fields.fromOverride.description',
                    'format' => 'email',
                    'x-position' => 6,
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
        if (!isset($config['to']) || (string) $config['to'] === '') {
            $errors[] = 'to is required';
        }
        if (!isset($config['subject']) || (string) $config['subject'] === '') {
            $errors[] = 'subject is required';
        }
        return ['valid' => $errors === [], 'errors' => $errors];
    }

    public function execute(array $context): array
    {
        if (!class_exists('\\CMailFile')) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        }
        if (!class_exists('\\CMailFile')) {
            throw new RuntimeException('Dolibarr CMailFile class not available.');
        }

        $node = is_array($context['node'] ?? null) ? $context['node'] : [];
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $resolver = $this->resolver ?? new ExpressionResolver();

        $to = (string) $resolver->resolve((string) ($config['to'] ?? ''), $context);
        $subject = (string) $resolver->resolve((string) ($config['subject'] ?? ''), $context);
        $body = $this->normalizeHtmlBody((string) $resolver->resolve((string) ($config['body'] ?? ''), $context));
        $cc = (string) $resolver->resolve((string) ($config['cc'] ?? ''), $context);
        $bcc = (string) $resolver->resolve((string) ($config['bcc'] ?? ''), $context);
        $replyTo = (string) $resolver->resolve((string) ($config['replyTo'] ?? ''), $context);
        $from = (string) $resolver->resolve(
            (string) ($config['fromOverride'] ?? (function_exists('getDolGlobalString') ? getDolGlobalString('MAIN_MAIL_EMAIL_FROM') : '')),
            $context
        );

        $mail = new \CMailFile(
            $subject,
            $to,
            $from !== '' ? $from : 'noreply@knot.local',
            $body,
            [],
            [],
            [],
            $cc,
            $bcc,
            0,
            1,
            '',
            '',
            '',
            '',
            'mail',
            $replyTo
        );

        $sent = $mail->sendfile();
        return [
            'sent' => (bool) $sent,
            'to' => $to,
            'subject' => $subject,
            'errors' => is_string($mail->error ?? null) && $mail->error !== '' ? [$mail->error] : [],
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
        $resolver = $this->resolver ?? new ExpressionResolver();

        $to = (string) $resolver->resolve((string) ($config['to'] ?? ''), $context);
        $subject = (string) $resolver->resolve((string) ($config['subject'] ?? ''), $context);

        return [
            '_dryRun' => true,
            'sent' => false,
            'to' => $to,
            'subject' => $subject,
            'errors' => [],
            'message' => sprintf('[DRY-RUN] would email %s subject "%s"', $to, $subject),
        ];
    }

    /**
     * Plain-text bodies (with real or literal \n from LLM imports) become safe HTML.
     */
    private function normalizeHtmlBody(string $body): string
    {
        if ($body === '') {
            return $body;
        }

        if (preg_match('/<(br|p|div|html|table|ul|ol|h[1-6])\b/i', $body) === 1) {
            return $body;
        }

        $body = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $body);

        return nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}

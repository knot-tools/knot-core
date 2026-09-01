<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOCSRFCHECK')) { define('NOCSRFCHECK', '1'); }
if (!defined('NOTOKENRENEWAL')) { define('NOTOKENRENEWAL', '1'); }

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Connectors\ConnectorRegistry;
use Knot\Connectors\CredentialSchemaNormalizer;
use Knot\Credentials\CredentialCipher;
use Knot\Licensing\Bootstrap;
use Knot\Repository\AuditLogRepository;
use Knot\Repository\CredentialRepository;

JsonResponse::installFatalHandler();

if (!$user->hasRight('knot', 'credential', 'manage') && !$user->hasRight('knot', 'workflow', 'read')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

$entity = (int) $conf->entity;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$repo = new CredentialRepository($db);

if ($method === 'GET') {
    $id = (int) GETPOST('id', 'int');
    if ($id > 0) {
        $credential = $repo->find($id, $entity);
        if ($credential === null) {
            JsonResponse::error('not_found', 'Credential not found', 404);
            exit;
        }
        JsonResponse::success(['credential' => $credential]);
        exit;
    }

    $connectorType = (string) GETPOST('connector_type', 'alphanohtml');
    JsonResponse::success([
        'credentials' => $repo->list($entity, $connectorType !== '' ? $connectorType : null),
        'counts' => $repo->countByConnectorType($entity),
    ]);
    exit;
}

if ($method !== 'DELETE' && $method !== 'POST' && $method !== 'PUT') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!$user->hasRight('knot', 'credential', 'manage')) {
    JsonResponse::error('permission_denied', 'Permission denied', 403);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_invalid', 'Invalid CSRF token', 403);
    exit;
}

if ($method === 'POST' || $method === 'PUT') {
    $payload = readJsonBody();
    if (!is_array($payload)) {
        JsonResponse::error('invalid_json', 'Invalid JSON payload.', 400);
        exit;
    }

    $connectorType = cleanKey((string) ($payload['connectorType'] ?? $payload['connector_type'] ?? ''));
    $type = cleanKey((string) ($payload['type'] ?? ''));
    $label = trim((string) ($payload['label'] ?? ''));
    $secrets = is_array($payload['secrets'] ?? null) ? $payload['secrets'] : [];
    $expiresAt = (string) ($payload['expiresAt'] ?? $payload['expires_at'] ?? '');

    if ($connectorType === '' || $type === '' || $label === '') {
        JsonResponse::error('validation_failed', 'label, connectorType and type are required.', 400);
        exit;
    }

    $schema = credentialSchemaFor($connectorType, $type);
    $errors = validateSecrets($schema, $secrets, $method === 'PUT');
    if ($errors !== []) {
        JsonResponse::error('validation_failed', 'Credential payload is invalid.', 400, ['errors' => $errors]);
        exit;
    }

    $data = [
        'label' => $label,
        'type' => $type,
        'connector_type' => $connectorType,
        'expires_at' => $expiresAt,
    ];

    if ($secrets !== []) {
        $cipher = new CredentialCipher(instanceSecret(), 'knot-credential-v1');
        $encrypted = $cipher->encrypt([
            'connectorType' => $connectorType,
            'type' => $type,
            'secrets' => $secrets,
            'createdAt' => gmdate('c'),
        ]);
        $data['encrypted_data'] = json_encode($encrypted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $data['encryption_version'] = $encrypted['version'] ?? '1';
    } elseif ($method === 'POST') {
        JsonResponse::error('validation_failed', 'secrets are required.', 400);
        exit;
    }

    if (($payload['testOnly'] ?? false) === true) {
        JsonResponse::success(['valid' => true, 'schema' => $schema]);
        exit;
    }

    if ($method === 'POST') {
        $id = $repo->create($data, $entity, (int) $user->id);
        if ($id <= 0) {
            JsonResponse::error('create_failed', 'Unable to create credential', 500);
            exit;
        }
        (new AuditLogRepository($db))->record('credential.create', 'credential', $id, (int) $user->id, [
            'connectorType' => $connectorType,
            'type' => $type,
        ], $entity);
        JsonResponse::success(['credential' => $repo->find($id, $entity)], 201);
        exit;
    }

    $id = (int) GETPOST('id', 'int');
    if ($id <= 0) {
        JsonResponse::error('validation_failed', 'id is required.', 400);
        exit;
    }
    if (!$repo->update($id, $data, $entity, (int) $user->id)) {
        JsonResponse::error('update_failed', 'Unable to update credential', 500);
        exit;
    }
    (new AuditLogRepository($db))->record('credential.update', 'credential', $id, (int) $user->id, [
        'connectorType' => $connectorType,
        'type' => $type,
    ], $entity);
    JsonResponse::success(['credential' => $repo->find($id, $entity)]);
    exit;
}

$id = (int) GETPOST('id', 'int');
if ($id <= 0) {
    JsonResponse::error('validation_failed', 'id is required.', 400);
    exit;
}

if (!$repo->delete($id, $entity)) {
    JsonResponse::error('delete_failed', 'Unable to delete credential', 500);
    exit;
}

(new AuditLogRepository($db))->record('credential.delete', 'credential', $id, (int) $user->id, [], $entity);

JsonResponse::success(['deleted' => $id]);

/**
 * @return array<string, mixed>|null
 */
function readJsonBody(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function cleanKey(string $value): string
{
    return preg_replace('/[^a-zA-Z0-9_.:-]/', '', trim($value)) ?: '';
}

function instanceSecret(): string
{
    global $dolibarr_main_instance_unique_id, $conf;

    $parts = [];
    if (isset($dolibarr_main_instance_unique_id) && (string) $dolibarr_main_instance_unique_id !== '') {
        $parts[] = (string) $dolibarr_main_instance_unique_id;
    }
    if (function_exists('getDolGlobalString')) {
        $parts[] = (string) getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
        $parts[] = (string) getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
    }
    $parts[] = defined('DOL_DOCUMENT_ROOT') ? DOL_DOCUMENT_ROOT : '';
    $parts[] = (string) ($conf->db->name ?? '');

    return hash('sha256', implode('|', array_filter($parts)));
}

/**
 * @return array<string, mixed>
 */
function credentialSchemaFor(string $connectorType, string $type): array
{
    global $db;

    $registry = new ConnectorRegistry();
    $extensions = Bootstrap::buildExtensionRegistry($db);
    $connectors = $registry->allWithExtensions($extensions);
    if (isset($connectors[$connectorType]) && is_callable([$connectors[$connectorType], 'getCredentialSchema'])) {
        $schema = $connectors[$connectorType]->{'getCredentialSchema'}();
        if (is_array($schema)) {
            return CredentialSchemaNormalizer::normalize($schema) ?? defaultCredentialSchema($type);
        }
    }

    return defaultCredentialSchema($type);
}

/**
 * @return array<string, mixed>
 */
function defaultCredentialSchema(string $type): array
{
    return match ($type) {
        'openai_api_key', 'anthropic_api_key' => [
            'type' => 'object',
            'required' => ['apiKey'],
            'properties' => [
                'apiKey' => ['type' => 'string', 'title' => 'API key', 'secret' => true],
            ],
        ],
        'telegram_bot' => [
            'type' => 'object',
            'required' => ['botToken'],
            'properties' => [
                'botToken' => ['type' => 'string', 'title' => 'Bot token', 'secret' => true],
                'defaultChatId' => ['type' => 'string', 'title' => 'Default chat ID'],
            ],
        ],
        default => [
            'type' => 'object',
            'required' => ['apiKey'],
            'properties' => [
                'apiKey' => ['type' => 'string', 'title' => 'API key / token', 'secret' => true],
            ],
        ],
    };
}

/**
 * @param array<string, mixed> $schema
 * @param array<string, mixed> $secrets
 * @return array<int, string>
 */
function validateSecrets(array $schema, array $secrets, bool $allowEmpty): array
{
    if ($allowEmpty && $secrets === []) {
        return [];
    }

    $errors = [];
    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
    foreach ($required as $field) {
        $field = (string) $field;
        if (!array_key_exists($field, $secrets) || trim((string) $secrets[$field]) === '') {
            $errors[] = $field . ' is required';
        }
    }

    return $errors;
}

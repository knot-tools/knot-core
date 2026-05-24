<?php
/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

/**
 * Fake licence backend used by LicensingChainE2ETest and DolistoreClientTest.
 *
 * Endpoints:
 *  - GET  /health
 *  - POST /api/license/check  (production-shaped verdict envelope)
 *  - POST /license/check      (legacy flat response, optional)
 */

if (!extension_loaded('sodium')) {
    http_response_code(500);
    echo json_encode(['error' => 'libsodium missing on harness PHP runtime']);
    return;
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $uri === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    return;
}

if ($method === 'POST' && ($uri === '/api/license/check' || $uri === '/license/check')) {
    handleCheck($uri === '/api/license/check');
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not_found', 'method' => $method, 'uri' => $uri]);

function handleCheck(bool $verdictEnvelope): void
{
    $raw = file_get_contents('php://input') ?: '{}';
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        return;
    }
    $secretHex = (string) (getenv('KNOT_TEST_BACKEND_SECRET_HEX') ?: '');
    if (strlen($secretHex) !== 128) {
        http_response_code(500);
        echo json_encode(['error' => 'backend_secret_missing_or_invalid']);
        return;
    }
    $secret = sodium_hex2bin($secretHex);

    $scenario = (string) (getenv('KNOT_TEST_BACKEND_SCENARIO') ?: 'valid');
    $expiresAt = (string) (getenv('KNOT_TEST_BACKEND_EXPIRES_AT') ?: '');
    if ($expiresAt === '') {
        $expiresAt = date('c', time() + 365 * 86400);
    }
    $fingerprint = (string) ($req['instance_fingerprint'] ?? $req['instanceId'] ?? '');

    $status = 'active';
    switch ($scenario) {
        case 'expired':
            $status = 'expired';
            $expiresAt = '2024-01-01T00:00:00+00:00';
            break;
        case 'revoked':
            $status = 'revoked';
            break;
        case 'binding-mismatch':
            $fingerprint = 'foreign-instance-id';
            break;
    }

    $payload = [
        'status' => $status,
        'plan' => 'standard',
        'product_slug' => 'knot-pro-pack',
        'instance_fingerprint' => $fingerprint,
        'expires_at' => $expiresAt,
        'issued_at' => date('c'),
        'expires_in_seconds' => 86400,
    ];

    $canonical = canonicalize($payload);

    if ($scenario === 'tampered') {
        $foreignKp = sodium_crypto_sign_keypair();
        $signatureHex = sodium_bin2hex(sodium_crypto_sign_detached($canonical, sodium_crypto_sign_secretkey($foreignKp)));
    } else {
        $signatureHex = sodium_bin2hex(sodium_crypto_sign_detached($canonical, $secret));
    }

    header('Content-Type: application/json');
    if ($verdictEnvelope) {
        echo json_encode([
            'verdict' => [
                'payload' => $payload,
                'signature' => [
                    'kid' => 'test-harness',
                    'algorithm' => 'ed25519',
                    'value_hex' => $signatureHex,
                    'canonical_payload' => $canonical,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    echo json_encode([
        'valid' => $status === 'active',
        'expiresAt' => $expiresAt,
        'plan' => 'standard',
        'issuedTo' => 'Acme Corp',
        'signature' => base64_encode(sodium_hex2bin($signatureHex)),
        'signedAt' => date('c'),
        'payload' => array_merge($payload, [
            'valid' => $status === 'active',
            'instanceId' => $fingerprint,
        ]),
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * @param array<string, mixed> $payload
 */
function canonicalize(array $payload): string
{
    sortRecursive($payload);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '{}' : $json;
}

/**
 * @param array<string, mixed> $array
 */
function sortRecursive(array &$array): void
{
    ksort($array);
    foreach ($array as &$value) {
        if (is_array($value)) {
            sortRecursive($value);
        }
    }
}

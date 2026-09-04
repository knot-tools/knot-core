<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}

require_once __DIR__ . '/../lib/load_dolibarr.inc.php';
dol_include_once('/knot/class/autoload.php');

use Knot\Api\ApiAuth;
use Knot\Api\CsrfGuard;
use Knot\Api\JsonResponse;
use Knot\Extension\LicenseValidator;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\DolistoreClient;
use Knot\Licensing\InstanceBinder;
use Knot\Licensing\InstallationIdentity;
use Knot\Repository\AuditLogRepository;
use Knot\Repository\KnotConfigRepository;
use Knot\Updates\GithubReleasesClient;
use Knot\Updates\Installer;
use Knot\Updates\InstallLock;
use Knot\Updates\ReleaseVerifier;
use Knot\Updates\LicenseActivationResolver;
use Knot\Updates\UpdatesApplyPolicy;
use Knot\Updates\UpdatesApplyPostSwap;
use Knot\Updates\ZipDownloader;

JsonResponse::installFatalHandler();
ApiAuth::installCrashHandler();

ApiAuth::requireRight('knot', 'admin', 'configure');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    JsonResponse::error('method_not_allowed', 'Method not allowed', 405);
    exit;
}

if (!CsrfGuard::verify()) {
    JsonResponse::error('csrf_failed', 'CSRF token missing or invalid', 419);
    exit;
}

if (!extension_loaded('curl')) {
    JsonResponse::error('curl_required', 'PHP cURL extension is required for apply flows.', 500);
    exit;
}

@set_time_limit(600);

$dataRoot = defined('DOL_DATA_ROOT') ? (string) DOL_DATA_ROOT : sys_get_temp_dir();
$knotDataDir = rtrim($dataRoot, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'knot';
if (!is_dir($knotDataDir) && !@mkdir($knotDataDir, 0755, true) && !is_dir($knotDataDir)) {
    JsonResponse::error('data_dir_unwritable', 'Cannot prepare Knot data directory for updates.', 500);
    exit;
}

/** @phpstan-ignore-next-line constant */
$liveRoot = dol_buildpath('/knot', 0);

$rawBody = (string) file_get_contents('php://input');
$body = $rawBody !== '' ? json_decode($rawBody, true) : null;
if (!is_array($body)) {
    $body = $_POST;
}

$downloadUrlIn = trim((string) ($body['download_url'] ?? ''));
$manualShaHex = strtolower(trim((string) ($body['zip_sha256'] ?? '')));

$slugValidation = UpdatesApplyPolicy::validateSlug($body['slug'] ?? '');
if (!$slugValidation['ok']) {
    $code = $slugValidation['code'];
    $message = $code === 'invalid_slug'
        ? 'A valid slug field is required.'
        : 'This slug cannot be applied via Knot apply API.';
    JsonResponse::error($code, $message, 422);
    exit;
}
$slug = $slugValidation['slug'];

$configRepo = new KnotConfigRepository($db);
$societeName = function_exists('getDolGlobalString')
    ? (string) getDolGlobalString('MAIN_INFO_SOCIETE_NOM', '')
    : '';
$dolUrlRoot = defined('DOL_URL_ROOT') ? (string) constant('DOL_URL_ROOT') : '';
$binder = new InstanceBinder(
    $societeName,
    $dolUrlRoot,
    Bootstrap::localSalt($db),
);
$fingerprint = $binder->compute();

$baseUrlLicense = function_exists('getDolGlobalString')
    ? trim((string) getDolGlobalString('MAIN_KNOT_LICENSE_BASE_URL', ''))
    : '';
if ($baseUrlLicense === '') {
    $baseUrlLicense = DolistoreClient::FALLBACK_BASE_URL;
}

$insecureTls = false;
if (function_exists('getDolGlobalString')) {
    $flag = strtolower(trim((string) getDolGlobalString('KNOT_LICENSE_DEV_INSECURE', '')));
    $insecureTls = in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

$releaseChannel = function_exists('getDolGlobalString')
    ? strtolower(trim((string) getDolGlobalString('KNOT_RELEASE_CHANNEL', 'beta')))
    : 'beta';
if ($releaseChannel === '') {
    $releaseChannel = 'beta';
}
$bodyChannel = strtolower(trim((string) ($body['channel'] ?? '')));
if ($bodyChannel !== '' && preg_match('/^[a-z0-9-]{2,32}$/', $bodyChannel)) {
    $releaseChannel = $bodyChannel;
}

$identity = new InstallationIdentity($configRepo, $db);
$dolistoreHttp = static function () use (
    $baseUrlLicense,
    $insecureTls,
    $releaseChannel,
): DolistoreClient {
    return new DolistoreClient($baseUrlLicense, 20, $insecureTls, $releaseChannel);
};

/** @phpstan-ignore-next-line */
$doliDbGlobal = $db;
/** @phpstan-ignore-next-line */
$confGlobal = $conf;
/** @phpstan-ignore-next-line */
$userGlobal = $user;

$entityId = (int) ($confGlobal->entity ?? 1);
$userId = isset($userGlobal->id) ? (int) $userGlobal->id : null;
$auditRepo = new AuditLogRepository($doliDbGlobal);
$targetVersion = trim((string) ($body['version'] ?? ''));

$auditRepo->record('updates.apply.started', 'updates', null, $userId, [
    'slug' => $slug,
    'targetVersion' => $targetVersion !== '' ? $targetVersion : null,
], $entityId);

$migrationLog = [];

$stagingParent = $knotDataDir . DIRECTORY_SEPARATOR . 'update-stage-' . bin2hex(random_bytes(6));
$zipPath = $knotDataDir . DIRECTORY_SEPARATOR . 'update-artifact-' . bin2hex(random_bytes(5)) . '.zip';

$purgeTree = static function (string $path): void {
    if ($path === '') {
        return;
    }
    if (!is_dir($path)) {
        @unlink($path);

        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $fileinfo) {
        /** @phpstan-ignore-next-line */
        $full = $fileinfo->getPathname();
        if ($fileinfo->isDir()) {
            @rmdir($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($path);
};

$installer = new Installer();

$fallbackLines = Installer::manualFallbackInstructions($liveRoot);

$rejectInvalidReleaseSignature = static function (\RuntimeException $e) use ($fallbackLines): void {
    $msg = $e->getMessage();
    if (
        str_contains($msg, 'signature')
        || str_contains($msg, 'Signing payload')
        || str_contains($msg, 'signature_hex')
    ) {
        JsonResponse::error('release_signature_invalid', $msg, 422, ['instructions' => $fallbackLines]);
        exit;
    }

    throw $e;
};

/**
 * @param array<string, mixed> $extraSuccess
 */
$respondMigrationFailure = static function (array $failureResult) use ($fallbackLines): void {
    /** @var string $rollback */
    $rollback = (string) ($failureResult['rollback'] ?? 'failed');
    JsonResponse::error(
        'migration_failed',
        (string) ($failureResult['message'] ?? 'Database migration failed.'),
        UpdatesApplyPostSwap::migrationFailureHttpStatus($rollback),
        [
            'instructions' => $fallbackLines,
            'migrations' => $failureResult['migrations'] ?? [],
            'rollback' => $rollback,
        ],
    );
    exit;
};

/**
 * @param array<string, mixed> $extraSuccess
 */
$swapAndMigrate = static function (
    string $liveTarget,
    string $prepared,
    bool $isCore,
    array $extraSuccess = [],
) use (
    $installer,
    $doliDbGlobal,
    $auditRepo,
    $entityId,
    $userId,
    $slug,
    $respondMigrationFailure,
    &$migrationLog,
): void {
    try {
        $installer->swap($prepared, $liveTarget);
    } catch (\Throwable $e) {
        $installer->rollback();
        JsonResponse::error(
            'apply_failed',
            $e->getMessage(),
            500,
            ['instructions' => Installer::manualFallbackInstructions($liveTarget)],
        );
        exit;
    }

    UpdatesApplyPostSwap::clearExtensionRegistryCache();
    $auditRepo->record('updates.apply.swapped', 'updates', null, $userId, [
        'slug' => $slug,
        'path' => $liveTarget,
    ], $entityId);

    $result = $isCore
        ? UpdatesApplyPostSwap::migrateCore(
            $doliDbGlobal,
            $installer,
            $liveTarget,
            $auditRepo,
            $entityId,
            $userId,
            $slug,
        )
        : UpdatesApplyPostSwap::migrateExtension(
            $doliDbGlobal,
            $installer,
            $liveTarget,
            $auditRepo,
            $entityId,
            $userId,
            $slug,
        );

    if (($result['rollback'] ?? 'none') !== 'none') {
        $respondMigrationFailure($result);
    }

    $migrationLog = $result['migrations'];

    JsonResponse::success(array_merge([
        'slug' => $slug,
        'path' => $liveTarget,
        'migrations' => $migrationLog,
        'manual_fallback_instructions' => Installer::manualFallbackInstructions($liveTarget),
    ], $extraSuccess));
    exit;
};

$lock = new InstallLock();
try {
    $lock->acquire();
} catch (\RuntimeException $e) {
    JsonResponse::error('update_locked', $e->getMessage(), 423, ['instructions' => $fallbackLines]);
    exit;
}

try {
    if ($slug === 'knot') {
        if ($downloadUrlIn !== '') {
            if ($manualShaHex === '') {
                JsonResponse::error(
                    'zip_sha256_required',
                    'When supplying download_url you must supply zip_sha256 (64 hex chars) for verification.',
                    422,
                );
                exit;
            }
            ZipDownloader::fetchTo($downloadUrlIn, $zipPath);
            ReleaseVerifier::assertZipSha256($zipPath, $manualShaHex);
            $sigHexOpt = strtolower(trim((string) ($body['signature_hex'] ?? '')));
            $sigPayloadOpt = isset($body['signature_payload']) && is_array($body['signature_payload'])
                ? /** @phpstan-ignore-next-line */ $body['signature_payload']
                : null;
            $targetVersion = trim((string) ($body['version'] ?? ($sigPayloadOpt['version'] ?? '')));
            try {
                ReleaseVerifier::assertCoreReleaseSignature($targetVersion, $sigPayloadOpt, $sigHexOpt);
            } catch (\RuntimeException $e) {
                $rejectInvalidReleaseSignature($e);
            }
            $folder = 'knot';
            $liveTarget = $liveRoot;
        } else {
            $override = function_exists('getDolGlobalString')
                ? trim((string) getDolGlobalString('MAIN_KNOT_CORE_RELEASES_JSON_URL', ''))
                : '';
            $gh = new GithubReleasesClient();
            $manifest = $gh->fetchManifest($override !== '' ? $override : null);
            $artifact = GithubReleasesClient::inferLatestArtifact($manifest);
            if ($artifact['zip_url'] === '') {
                JsonResponse::error('releases_missing_zip', 'releases.json has no usable zip_url.', 502);
                exit;
            }
            ZipDownloader::fetchTo($artifact['zip_url'], $zipPath);
            try {
                GithubReleasesClient::verifyReleaseIntegrity(
                    $zipPath,
                    $artifact['zip_sha256'],
                    /** @phpstan-ignore-next-line */
                    is_array($artifact['signature_payload']) ? $artifact['signature_payload'] : null,
                    $artifact['signature_hex'],
                    $artifact['version'],
                );
            } catch (\RuntimeException $e) {
                $rejectInvalidReleaseSignature($e);
            }
            $folder = 'knot';
            $liveTarget = $liveRoot;
        }

        $prepared = $installer->prepare($zipPath, $stagingParent, $folder);
        $swapAndMigrate($liveTarget, $prepared, true);
    }

    // Commercial extensions share the Dolistore JWT download-token path.
    $registry = Bootstrap::buildExtensionRegistry($doliDbGlobal);
    $extensions = $registry->discover();
    if (!isset($extensions[$slug]) || !is_array($extensions[$slug])) {
        JsonResponse::error('extension_unknown', sprintf('Extension %s is not installed or not discoverable.', $slug), 404);
        exit;
    }

    $entry = $extensions[$slug];
    $manifestForInspect = $entry;
    foreach (['path', 'connectors', 'connectorIds', 'licenseInfo', 'status', 'error'] as $strip) {
        unset($manifestForInspect[$strip]);
    }

    $validator = Bootstrap::buildLicenseValidator($doliDbGlobal);
    $manifestPath = isset($entry['path']) && is_string($entry['path'])
        ? rtrim($entry['path'], '/\\') . '/knot-extension.json'
        : null;
    $lic = $validator->inspect($manifestForInspect, $manifestPath);
    if ($lic['status'] !== LicenseValidator::STATUS_VALID) {
        JsonResponse::error(
            'license_invalid',
            $lic['error'] ?? ('License status: ' . $lic['status']),
            422,
            ['instructions' => $fallbackLines],
        );
        exit;
    }

    $activation = LicenseActivationResolver::cleartextActivationForExtension($doliDbGlobal, $slug);
    if ($activation === null || trim($activation) === '') {
        JsonResponse::error(
            'activation_code_missing',
            'Activate this extension once so Knot can request a signed download artefact.',
            422,
        );
        exit;
    }

    if ($downloadUrlIn !== '') {
        if ($manualShaHex === '') {
            JsonResponse::error(
                'zip_sha256_required',
                'When supplying download_url you must supply zip_sha256 (64 hex chars) for verification.',
                422,
            );
            exit;
        }
        ZipDownloader::fetchTo($downloadUrlIn, $zipPath);
        ReleaseVerifier::assertZipSha256($zipPath, $manualShaHex);
        $sigHexOpt = strtolower(trim((string) ($body['signature_hex'] ?? '')));
        $sigPayloadOpt = isset($body['signature_payload']) && is_array($body['signature_payload'])
            ? /** @phpstan-ignore-next-line */ $body['signature_payload']
            : null;
        if ($sigHexOpt === '' || $sigPayloadOpt === null) {
            $clientObj = $dolistoreHttp();
            try {
                $sigMeta = $clientObj->fetchProductSignature($slug, $releaseChannel);
                $sigHexOpt = strtolower(trim((string) ($sigMeta['signature']['value_hex'] ?? '')));
                $sigPayloadOpt = is_array($sigMeta['signature_payload'] ?? null)
                    ? $sigMeta['signature_payload']
                    : null;
            } catch (\Throwable $e) {
                JsonResponse::error(
                    'backend_unreachable',
                    'Cannot fetch release signature metadata from Knot license backend.',
                    502,
                    ['cause' => $e->getMessage(), 'instructions' => $fallbackLines],
                );
                exit;
            }
        }
        try {
            ReleaseVerifier::assertExtensionReleaseSignature($sigPayloadOpt, $sigHexOpt);
        } catch (\RuntimeException $e) {
            $rejectInvalidReleaseSignature($e);
        }
        $liveTarget = isset($entry['path']) ? rtrim((string) $entry['path'], DIRECTORY_SEPARATOR . '/') : '';
        $customSafe = rtrim((string) DOL_DOCUMENT_ROOT, DIRECTORY_SEPARATOR . '/') . '/custom';
        if (
            $liveTarget === ''
            || !str_starts_with($liveTarget, rtrim($customSafe, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR)
        ) {
            JsonResponse::error('extension_path_invalid', 'Extension install path failed safety checks.', 500);
            exit;
        }
        $topFolder = basename($liveTarget);
        $prepared = $installer->prepare($zipPath, $stagingParent, $topFolder);
        $swapAndMigrate($liveTarget, $prepared, false);
    }

    $clientObj = $dolistoreHttp();
    try {
        $http = $clientObj->licenseDownloadTokenRequest([
            'activationCode' => $activation,
            'instanceFingerprint' => $fingerprint,
            'productSlug' => $slug,
            'deploymentToken' => $identity->deploymentToken(),
            'deploymentNonce' => $identity->deploymentNonce(),
        ]);
    } catch (\Throwable $e) {
        JsonResponse::error(
            'backend_unreachable',
            'Cannot reach Knot license backend.',
            502,
            ['cause' => $e->getMessage(), 'instructions' => $fallbackLines],
        );
        exit;
    }

    $decoded = json_decode($http['body'], true);
    if (!is_array($decoded) || $http['status'] !== 200) {
        $msg = is_array($decoded)
            ? trim((string) ($decoded['error'] ?? ''))
            : '';
        JsonResponse::error(
            'license_download_token_denied',
            $msg !== '' ? $msg : 'License backend denied download token issuance.',
            $http['status'] >= 400 && $http['status'] < 600 ? $http['status'] : 502,
            [],
        );
        exit;
    }

    $dl = trim((string) ($decoded['download_url'] ?? ''));
    /** @phpstan-ignore-next-line */
    $release = is_array($decoded['release'] ?? null) ? $decoded['release'] : [];

    if ($dl === '') {
        JsonResponse::error('download_url_missing', 'License backend omitted download_url.', 502);
        exit;
    }

    $releaseVersion = trim((string) ($release['version'] ?? ''));
    if ($targetVersion !== '' && $releaseVersion !== '' && $releaseVersion !== $targetVersion) {
        JsonResponse::error(
            'release_version_mismatch',
            sprintf(
                'Requested version %s but license backend resolved %s. Retry after the target version is published.',
                $targetVersion,
                $releaseVersion,
            ),
            409,
            ['requested' => $targetVersion, 'resolved' => $releaseVersion],
        );
        exit;
    }

    ZipDownloader::fetchTo($dl, $zipPath);
    /** @phpstan-ignore-next-line */
    $releaseSha = strtolower(trim((string) ($release['zip_sha256'] ?? '')));
    ReleaseVerifier::assertZipSha256($zipPath, $releaseSha);
    /** @phpstan-ignore-next-line */
    $sigHex = strtolower(trim((string) ($release['signature_hex'] ?? '')));
    /** @phpstan-ignore-next-line */
    $sigPayload = is_array($release['signature_payload'] ?? null) ? $release['signature_payload'] : null;
    try {
        ReleaseVerifier::assertExtensionReleaseSignature($sigPayload, $sigHex);
    } catch (\RuntimeException $e) {
        $rejectInvalidReleaseSignature($e);
    }
    /** @phpstan-ignore-next-line */
    $liveTarget = isset($entry['path']) ? rtrim((string) $entry['path'], DIRECTORY_SEPARATOR . '/') : '';
    $customSafe = rtrim((string) DOL_DOCUMENT_ROOT, DIRECTORY_SEPARATOR . '/') . '/custom';
    if (
        $liveTarget === ''
        || !str_starts_with($liveTarget, rtrim($customSafe, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR)
    ) {
        JsonResponse::error('extension_path_invalid', 'Extension install path failed safety checks.', 500);
        exit;
    }
    $topFolder = basename($liveTarget);

    $prepared = $installer->prepare($zipPath, $stagingParent, $topFolder);
    $swapAndMigrate($liveTarget, $prepared, false, ['release' => $release]);
} catch (\Throwable $e) {
    $installer->rollback();
    JsonResponse::error(
        'apply_failed',
        $e->getMessage(),
        500,
        ['instructions' => $fallbackLines],
    );
    exit;
} finally {
    @unlink($zipPath);
    if (isset($stagingParent) && is_dir($stagingParent)) {
        $purgeTree($stagingParent);
    }
    $lock->release();
}

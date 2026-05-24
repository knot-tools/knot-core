<?php

declare(strict_types=1);

namespace Knot\Extension;

use Knot\Connectors\ConnectorInterface;
use Knot\Version;

/**
 * Discovers Knot extensions installed alongside Knot Core.
 *
 * Discovery rules:
 *   1. Scan each configured root (typically `htdocs/custom/`): every
 *      immediate subdirectory containing a readable `knot-extension.json`
 *      at its root is a candidate (covers Dolibarr installs such as
 *      `custom/knotpropack/` as well as legacy `custom/modKnotFoo/`).
 *   2. Validate the manifest (ManifestSchema).
 *   3. Check `requires.knot` against `Knot\Version::current()` and
 *      `requires.dolibarr` against `DOL_VERSION` when available.
 *   4. Optionally load each declared connector class and instantiate it.
 *      Loading happens once and is cached per-process.
 *
 * The registry never throws on bad input — invalid extensions are listed
 * with a `status` field describing why they were rejected. This keeps
 * Knot Core operational even when a third-party add-on ships a broken
 * manifest.
 *
 * V2.5.0c — `final` removed so test doubles (TierGateTest, etc.)
 * can substitute a stub registry without standing up a full Dolibarr
 * stack. Production code must still go through
 * {@see \Knot\Licensing\Bootstrap::buildExtensionRegistry()}.
 */
class ExtensionRegistry
{
    public const STATUS_LOADED = 'loaded';
    public const STATUS_INVALID_MANIFEST = 'invalid_manifest';
    public const STATUS_INCOMPATIBLE = 'incompatible';
    public const STATUS_LICENSE_INVALID = 'license_invalid';
    public const STATUS_CONNECTOR_MISSING = 'connector_missing';
    public const STATUS_DISABLED = 'disabled';

    /** @var string[] */
    private array $rootPaths;

    private LicenseValidator $licenseValidator;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * @param string[]|null         $rootPaths Optional explicit roots
     *                                         (default = htdocs/custom).
     * @param LicenseValidator|null $licenseValidator Optional override.
     */
    public function __construct(?array $rootPaths = null, ?LicenseValidator $licenseValidator = null)
    {
        if ($rootPaths !== null && $rootPaths !== []) {
            $this->rootPaths = $rootPaths;
        } else {
            $this->rootPaths = self::defaultRootPaths();
        }
        $this->licenseValidator = $licenseValidator ?? new LicenseValidator();
    }

    /**
     * Reset the in-process cache. Wired to `actionAfterModuleEnable`
     * Dolibarr hook in V2.4.
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * Discover all extensions. Each entry contains:
     *   - id, label, version, author, category, license, requires
     *   - status: one of STATUS_*
     *   - error: ?string (when status != STATUS_LOADED)
     *   - connectors: ConnectorInterface[] (only when status = STATUS_LOADED)
     *   - connectorIds: string[] (always populated from manifest)
     *   - path: absolute extension folder
     *   - licenseInfo: array
     *
     * @return array<string, array<string, mixed>>
     */
    public function discover(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $extensions = [];
        $disabled = self::disabledIds();

        foreach ($this->scanCandidates() as $candidatePath) {
            $manifestPath = $candidatePath . '/knot-extension.json';
            if (!is_readable($manifestPath)) {
                continue;
            }
            $raw = file_get_contents($manifestPath);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            $validation = ManifestSchema::validate($decoded);
            if (!$validation['valid']) {
                $entry = self::skeleton(is_array($decoded) ? ($decoded['id'] ?? null) : null, $candidatePath);
                $entry['status'] = self::STATUS_INVALID_MANIFEST;
                $entry['error'] = implode('; ', $validation['errors']);
                $extensions[$entry['id']] = $entry;
                continue;
            }
            $manifest = $validation['normalised'];
            $entry = $manifest;
            $entry['path'] = $candidatePath;
            $entry['connectorIds'] = $manifest['connectors'];
            $entry['connectors'] = [];
            $entry['licenseInfo'] = $this->licenseValidator->inspect($manifest);

            if (in_array($manifest['id'], $disabled, true)) {
                $entry['status'] = self::STATUS_DISABLED;
                $entry['error'] = 'Disabled via KNOT_EXTENSIONS_DISABLED';
                $extensions[$manifest['id']] = $entry;
                continue;
            }

            $compatError = $this->checkCompatibility($manifest);
            if ($compatError !== null) {
                $entry['status'] = self::STATUS_INCOMPATIBLE;
                $entry['error'] = $compatError;
                $extensions[$manifest['id']] = $entry;
                continue;
            }

            if (
                !in_array(
                    $entry['licenseInfo']['status'],
                    [
                        LicenseValidator::STATUS_VALID,
                        LicenseValidator::STATUS_NOT_REQUIRED,
                    ],
                    true
                )
            ) {
                $entry['status'] = self::STATUS_LICENSE_INVALID;
                $entry['error'] = $entry['licenseInfo']['error'] ?? 'License is not valid';
                $extensions[$manifest['id']] = $entry;
                continue;
            }

            // Pass the manifest with its on-disk path so loadConnectors()
            // can `require_once` the extension's optional autoload.php.
            $manifestWithPath = $manifest;
            $manifestWithPath['path'] = $candidatePath;
            $loadResult = $this->loadConnectors($manifestWithPath);
            if ($loadResult['error'] !== null) {
                $entry['status'] = self::STATUS_CONNECTOR_MISSING;
                $entry['error'] = $loadResult['error'];
                $extensions[$manifest['id']] = $entry;
                continue;
            }

            $entry['status'] = self::STATUS_LOADED;
            $entry['error'] = null;
            $entry['connectors'] = $loadResult['connectors'];
            $extensions[$manifest['id']] = $entry;
        }

        $this->cache = $extensions;
        return $extensions;
    }

    /**
     * Return only extensions in `loaded` state (ready for runtime).
     *
     * @return array<string, array<string, mixed>>
     */
    public function active(): array
    {
        return array_filter(
            $this->discover(),
            static fn (array $ext): bool => ($ext['status'] ?? null) === self::STATUS_LOADED
        );
    }

    /**
     * Return all loaded connectors keyed by their metadata id, paired with
     * the extension that provided them.
     *
     * @return array<string, array{connector: ConnectorInterface, extension: array<string, mixed>}>
     */
    public function loadedConnectors(): array
    {
        $out = [];
        foreach ($this->active() as $extension) {
            foreach ($extension['connectors'] ?? [] as $connector) {
                if (!$connector instanceof ConnectorInterface) {
                    continue;
                }
                try {
                    $metadata = $connector->getMetadata();
                } catch (\Throwable $e) {
                    error_log(sprintf(
                        '[knot extension] getMetadata failed for %s: %s',
                        $connector::class,
                        $e->getMessage()
                    ));
                    continue;
                }
                $id = (string) ($metadata['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[$id] = [
                    'connector' => $connector,
                    'extension' => $extension,
                ];
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function scanCandidates(): array
    {
        $found = [];
        foreach ($this->rootPaths as $root) {
            if (!is_dir($root)) {
                continue;
            }
            // scan top-level modKnot* folders
            $entries = @scandir($root) ?: [];
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $root . '/' . $name;
                if (!is_dir($full)) {
                    continue;
                }
                // Installer swap stashes live trees as `{slug}.backup.{timestamp}_{rand}` — never register them.
                if (str_contains($name, '.backup.')) {
                    continue;
                }
                $manifestPath = $full . '/knot-extension.json';
                if (!is_readable($manifestPath)) {
                    continue;
                }
                $found[] = $full;
            }
        }
        return $found;
    }

    /** @param array<string, mixed> $manifest */
    private function checkCompatibility(array $manifest): ?string
    {
        $knotRange = $manifest['requires']['knot'] ?? '*';
        $current = Version::current();
        if (!ManifestSchema::satisfies($current, (string) $knotRange)) {
            return sprintf('requires Knot %s but current is %s', $knotRange, $current);
        }
        if (defined('DOL_VERSION')) {
            $doliRange = $manifest['requires']['dolibarr'] ?? '*';
            $doliVersion = (string) constant('DOL_VERSION');
            if (!ManifestSchema::satisfies($doliVersion, (string) $doliRange)) {
                return sprintf('requires Dolibarr %s but current is %s', $doliRange, $doliVersion);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array{connectors: ConnectorInterface[], error: ?string}
     */
    private function loadConnectors(array $manifest): array
    {
        // Each extension may ship its own PSR-4 autoloader at the
        // extension root (autoload.php). We require it once so the
        // class_exists() probe below can resolve FQCN that live under
        // a different on-disk layout than Knot Core's class/ tree.
        $extensionRoot = (string) ($manifest['path'] ?? '');
        if ($extensionRoot !== '') {
            $autoload = $extensionRoot . '/autoload.php';
            if (is_readable($autoload)) {
                require_once $autoload;
            }
        }
        $connectors = [];
        foreach ($manifest['connectors'] as $fqcn) {
            if (!class_exists($fqcn)) {
                return [
                    'connectors' => [],
                    'error' => sprintf('connector class "%s" not found (autoloader missing or typo)', $fqcn),
                ];
            }
            try {
                $instance = new $fqcn();
            } catch (\Throwable $e) {
                return [
                    'connectors' => [],
                    'error' => sprintf('connector "%s" instantiation failed: %s', $fqcn, $e->getMessage()),
                ];
            }
            if (!$instance instanceof ConnectorInterface) {
                return [
                    'connectors' => [],
                    'error' => sprintf('connector "%s" must implement Knot\\Connectors\\ConnectorInterface', $fqcn),
                ];
            }
            $connectors[] = $instance;
        }
        return ['connectors' => $connectors, 'error' => null];
    }

    /**
     * @return string[]
     */
    private static function defaultRootPaths(): array
    {
        $paths = [];
        if (defined('DOL_DOCUMENT_ROOT')) {
            $paths[] = ((string) constant('DOL_DOCUMENT_ROOT')) . '/custom';
        }
        // module is mounted at htdocs/custom/knot, so scanning ../ also
        // catches sibling modKnot* extensions in dev installs.
        $paths[] = dirname(__DIR__, 3);
        return array_values(array_unique(array_filter($paths, 'is_dir')));
    }

    /**
     * @param mixed $rawId
     *
     * @return array<string, mixed>
     */
    private static function skeleton($rawId, string $path): array
    {
        $id = is_string($rawId) && $rawId !== '' ? $rawId : 'unknown-' . substr(sha1($path), 0, 8);
        return [
            'id' => $id,
            'label' => null,
            'version' => null,
            'author' => null,
            'category' => 'third-party',
            'license' => null,
            'requires' => null,
            'connectors' => [],
            'connectorIds' => [],
            'namespace' => null,
            // ADR-20: ui is null for any extension whose manifest failed
            // validation. Consumers (sidebar loop, bootstrap injection)
            // must skip extensions whose `ui` is null.
            'ui' => null,
            'path' => $path,
            'licenseInfo' => [
                'status' => LicenseValidator::STATUS_NOT_REQUIRED,
                'expiresAt' => null,
                'issuedTo' => null,
                'error' => null,
            ],
            'status' => self::STATUS_INVALID_MANIFEST,
            'error' => null,
        ];
    }

    /**
     * @return string[]
     */
    private static function disabledIds(): array
    {
        if (!function_exists('getDolGlobalString')) {
            return [];
        }
        $raw = (string) getDolGlobalString('KNOT_EXTENSIONS_DISABLED', '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}

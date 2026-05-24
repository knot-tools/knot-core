<?php

declare(strict_types=1);

namespace Knot\Connectors;

use Knot\Connectors\Actions\DolibarrReadObject;
use Knot\Connectors\Communication\EmailAction;
use Knot\Connectors\Dolibarr\ObjectAction;
use Knot\Connectors\Dolibarr\SqlQuery;
use Knot\Connectors\Dolibarr\SpecializedAction;
use Knot\Connectors\Logic\ApprovalWaitNode;
use Knot\Connectors\Logic\ArrayNode;
use Knot\Connectors\Logic\CryptoNode;
use Knot\Connectors\Logic\DateNode;
use Knot\Connectors\Logic\ExecuteWorkflowNode;
use Knot\Connectors\Logic\FilterNode;
use Knot\Connectors\Logic\HtmlNode;
use Knot\Connectors\Logic\IfElseNode;
use Knot\Connectors\Logic\JsonNode;
use Knot\Connectors\Logic\LoopNode;
use Knot\Connectors\Logic\MergeNode;
use Knot\Connectors\Logic\NumberNode;
use Knot\Connectors\Logic\RespondToWebhookNode;
use Knot\Connectors\Logic\SetNode;
use Knot\Connectors\Logic\SplitNode;
use Knot\Connectors\Logic\StopAndErrorNode;
use Knot\Connectors\Logic\StringNode;
use Knot\Connectors\Logic\SwitchNode;
use Knot\Connectors\Logic\WaitNode;
use Knot\Connectors\Logic\WhileNode;
use Knot\Connectors\Logic\XmlNode;
use Knot\Connectors\Notification\AlertAction;
use Knot\Connectors\Triggers\CronTrigger;
use Knot\Connectors\Triggers\DolibarrEventTrigger;
use Knot\Connectors\Triggers\ManualTrigger;
use Knot\Connectors\Triggers\WebhookTrigger;
use Knot\Marketplace\ConnectorPresentationMerger;
use Knot\Extension\ExtensionRegistry;
use Knot\Extension\LicenseValidator;

/**
 * Static connector registry.
 *
 * Single source of truth for the workflow engine and the API
 * (`api/connectors.php`). Each connector carries its own metadata,
 * config schema and runtime behaviour.
 */
final class ConnectorRegistry
{
    /**
     * Source tag added to metadata so the frontend can render badges.
     */
    public const SOURCE_CORE = 'core';
    public const SOURCE_EXTENSION_PREFIX = 'extension:';


    /**
     * Return built-in connectors keyed by their metadata id.
     *
     * @return array<string, ConnectorInterface>
     */
    public function all(): array
    {
        $connectors = [
            new ManualTrigger(),
            new CronTrigger(),
            new WebhookTrigger(),
            new DolibarrEventTrigger(),

            new SetNode(),
            new FilterNode(),
            new IfElseNode(),
            new SwitchNode(),
            new MergeNode(),
            new WaitNode(),
            new ExecuteWorkflowNode(),
            new StopAndErrorNode(),
            new RespondToWebhookNode(),
            new ApprovalWaitNode(),
            new LoopNode(),
            new WhileNode(),
            new SplitNode(),
            new ArrayNode(),
            new HtmlNode(),
            new XmlNode(),
            new CryptoNode(),
            new JsonNode(),
            new StringNode(),
            new NumberNode(),
            new DateNode(),

            new ObjectAction(),
            new SpecializedAction(),
            new SqlQuery(),
            new DolibarrReadObject(),

            new EmailAction(),
            new AlertAction(),
        ];

        $indexed = [];
        foreach ($connectors as $connector) {
            try {
                $metadata = $connector->getMetadata();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[knot connectors] core getMetadata failed for %s: %s',
                    $connector::class,
                    $e->getMessage()
                ));
                continue;
            }
            $id = (string) ($metadata['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $indexed[$id] = $connector;
        }

        return $indexed;
    }

    /**
     * Return Core + Extension connectors merged into a single map.
     * Extensions are loaded via the provided ExtensionRegistry; if none
     * is given, a default one is created. Core connectors always win on
     * id collision (no override of built-ins).
     *
     * @return array<string, ConnectorInterface>
     */
    public function allWithExtensions(?ExtensionRegistry $extensions = null): array
    {
        $registry = $extensions ?? new ExtensionRegistry();
        $merged = $this->all();
        foreach ($registry->loadedConnectors() as $id => $bundle) {
            if (isset($merged[$id])) {
                continue; // Core wins
            }
            $merged[$id] = $bundle['connector'];
        }
        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $snippetConnectors
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeAllForPalette(?ExtensionRegistry $extensions = null, array $snippetConnectors = [], string $lang = 'en'): array
    {
        $registry = $extensions ?? new ExtensionRegistry();
        $byExtension = $registry->loadedConnectors();
        $rows = [];
        foreach ($this->all() as $id => $connector) {
            $rows[] = array_merge(self::baseDescriptor($connector, self::SOURCE_CORE, null, true), [
                'fqcn' => $connector::class,
                'extensionManifestId' => '',
            ]);
        }
        foreach ($byExtension as $id => $bundle) {
            // skip if Core already provides it
            if (in_array($id, array_keys($this->all()), true)) {
                continue;
            }
            $extension = $bundle['extension'];
            $rows[] = array_merge(self::baseDescriptor(
                $bundle['connector'],
                self::SOURCE_EXTENSION_PREFIX . ($extension['id'] ?? '?'),
                self::extensionPublicInfo($extension),
                true
            ), [
                'fqcn' => $bundle['connector']::class,
                'extensionManifestId' => (string) ($extension['id'] ?? ''),
            ]);
        }
        // Surface non-loaded extensions (license issue, missing class, etc.)
        // with a virtual descriptor so the UI can grey them out and offer
        // a renewal/install CTA.
        foreach ($registry->discover() as $extension) {
            if (($extension['status'] ?? null) === ExtensionRegistry::STATUS_LOADED) {
                continue;
            }
            foreach ($extension['connectorIds'] ?? [] as $connectorClass) {
                $virtualId = 'ext:' . ($extension['id'] ?? '?') . ':' . $connectorClass;
                $rows[] = [
                    'id' => $virtualId,
                    'label' => self::shortClassName($connectorClass),
                    'category' => 'other',
                    'description' => sprintf(
                        '%s (provided by %s)',
                        self::shortClassName($connectorClass),
                        $extension['label'] ?? $extension['id'] ?? '?'
                    ),
                    'source' => self::SOURCE_EXTENSION_PREFIX . ($extension['id'] ?? '?'),
                    'extensionInfo' => self::extensionPublicInfo($extension),
                    'available' => false,
                    'fqcn' => trim((string) $connectorClass),
                    'extensionManifestId' => (string) ($extension['id'] ?? ''),
                ];
            }
        }
        if ($snippetConnectors === []) {
            return $rows;
        }

        return ConnectorPresentationMerger::mergePaletteRows($rows, $snippetConnectors, $lang);
    }

    /**
     * Return categorised metadata for the frontend palette.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paletteSections(): array
    {
        $byCategory = [];
        foreach ($this->all() as $connector) {
            $metadata = $connector->getMetadata();
            $category = (string) ($metadata['category'] ?? 'other');
            $byCategory[$category][] = (string) $metadata['id'];
        }

        $order = ['trigger', 'logic', 'communication', 'notification', 'ai', 'dolibarr', 'saas', 'universal', 'other'];
        $sections = [];
        foreach ($order as $category) {
            if (!isset($byCategory[$category])) {
                continue;
            }
            $sections[] = [
                'category' => $category,
                'title' => match ($category) {
                    'trigger' => 'Triggers',
                    'logic' => 'Logic',
                    'communication' => 'Communication',
                    'notification' => 'Notifications',
                    'ai' => 'AI',
                    'dolibarr' => 'Dolibarr',
                    'saas' => 'SaaS',
                    'universal' => 'Universal',
                    default => ucfirst($category),
                },
                'ids' => $byCategory[$category],
            ];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $extension
     * @return array<string, mixed>
     */
    private static function extensionPublicInfo(array $extension): array
    {
        $licenseInfo = $extension['licenseInfo'] ?? [];
        return [
            'id' => $extension['id'] ?? null,
            'label' => $extension['label'] ?? null,
            'version' => $extension['version'] ?? null,
            'author' => $extension['author'] ?? null,
            'category' => $extension['category'] ?? 'third-party',
            'license_type' => is_array($extension['license'] ?? null)
                ? ($extension['license']['type'] ?? 'free')
                : 'free',
            'license_status' => $licenseInfo['status'] ?? LicenseValidator::STATUS_NOT_REQUIRED,
            'license_expires_at' => $licenseInfo['expiresAt'] ?? null,
            'license_error' => $licenseInfo['error'] ?? null,
            'status' => $extension['status'] ?? null,
            'error' => $extension['error'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $extensionInfo
     *
     * @return array<string, mixed>
     */
    private static function baseDescriptor(
        ConnectorInterface $connector,
        string $source,
        ?array $extensionInfo,
        bool $available
    ): array {
        $metadata = $connector->getMetadata();
        return array_merge(
            [
                'id' => (string) ($metadata['id'] ?? ''),
                'label' => (string) ($metadata['label'] ?? ''),
                'labelKey' => (string) ($metadata['labelKey'] ?? ''),
                'category' => (string) ($metadata['category'] ?? 'other'),
                'description' => (string) ($metadata['description'] ?? ''),
                'descriptionKey' => (string) ($metadata['descriptionKey'] ?? ''),
            ],
            [
                'source' => $source,
                'extensionInfo' => $extensionInfo,
                'available' => $available,
            ]
        );
    }

    private static function shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}

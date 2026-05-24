<?php

declare(strict_types=1);

namespace Knot\Compatibility;

/**
 * Builds a portable `dolibarr-catalog.json` artifact from a filesystem scan
 * (no vendor Dolibarr code shipped inside the Knot module artifact).
 */
final class DolibarrCatalogGenerator
{
    /**
     * @param array<string, array<string, mixed>> $map ObjectFactory::MAP
     * @param array<int, array<string, mixed>>   $scan ObjectIntrospector::scan()
     *
     * @return array<string, mixed>
     */
    public function buildCatalog(string $documentRoot, array $map, array $scan): array
    {
        $normalizedRoot = rtrim($documentRoot, '/\\');
        $basename = basename($normalizedRoot);

        return [
            'format' => 'knot.dolibarr-catalog',
            'formatVersion' => 1,
            'generatedAt' => gmdate('c'),
            'baseline' => [
                'documentRootBasename' => $basename,
                'note' => 'Pin exact Dolibarr tag via CI (dolibarr-matrix) or record git tag in docs.',
            ],
            'counts' => [
                'mapSlugs' => count($map),
                'scanDescriptors' => count($scan),
            ],
            'map' => $map,
            'scan' => $scan,
        ];
    }
}

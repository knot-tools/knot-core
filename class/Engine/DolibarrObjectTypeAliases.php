<?php

declare(strict_types=1);

namespace Knot\Engine;

/**
 * Maps common LLM / English aliases to Dolibarr read_object slugs.
 */
final class DolibarrObjectTypeAliases
{
    /** @var array<string, string> lowercase alias → canonical slug */
    public const ALIASES = [
        'invoice' => 'facture',
        'invoices' => 'facture',
        'bill' => 'facture',
        'customer' => 'thirdparty',
        'client' => 'thirdparty',
        'third_party' => 'thirdparty',
        'devis' => 'propal',
        'proposal' => 'propal',
        'quote' => 'propal',
        'societe' => 'thirdparty',
        'company' => 'thirdparty',
        'tiers' => 'thirdparty',
        'order' => 'commande',
        'purchase_order' => 'commande',
    ];

    public static function normalize(string $objectType): string
    {
        $trimmed = trim($objectType);
        if ($trimmed === '' || str_contains($trimmed, '{{')) {
            return $trimmed;
        }
        $lower = strtolower($trimmed);

        return self::ALIASES[$lower] ?? $trimmed;
    }
}

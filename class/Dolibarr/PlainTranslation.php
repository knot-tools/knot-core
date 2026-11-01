<?php

declare(strict_types=1);

namespace Knot\Dolibarr;

/**
 * Single boundary where Dolibarr translations enter Core payloads.
 *
 * Dolibarr's Translate::trans() returns HTML-escaped text ("Tag/cat&eacute;gorie")
 * because its own pages echo it into HTML. Core sends labels as JSON to the
 * Vue front, which renders them as text, so they must be plain UTF-8 here.
 */
final class PlainTranslation
{
    /**
     * Translate `$key` with the optional Dolibarr `Translate` instance and
     * return plain UTF-8. Falls back to the raw key without a translator.
     */
    public static function translate(string $key, ?object $langs): string
    {
        if ($langs !== null && method_exists($langs, 'trans')) {
            $value = $langs->trans($key);
            if (is_string($value) && $value !== '') {
                return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $key;
    }
}

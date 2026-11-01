<?php

declare(strict_types=1);

namespace Knot\Tests\Lang;

use PHPUnit\Framework\TestCase;

/**
 * Translation sources must be plain UTF-8. Accented letters written as
 * entities (&eacute;, &#233;…) leak verbatim once a label is rendered as text.
 *
 * In langs/*.lang, only markup escapes (&lt; &gt; &amp; &quot;) are allowed:
 * a few setup hints are HTML rendered by Dolibarr pages and need them.
 * Front JSON (frontend/src/i18n) is rendered as text: no entity at all.
 *
 * @coversNothing
 */
final class LangFilesNoEntityTest extends TestCase
{
    private const ENTITY = '/&(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#x[0-9a-fA-F]+);/';
    private const MARKUP_OK = ['&lt;', '&gt;', '&amp;', '&quot;'];

    public function testDolibarrLangFilesUseUtf8NotEntities(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/langs/*/*.lang') ?: [];
        self::assertNotEmpty($files);
        $bad = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                preg_match_all(self::ENTITY, $line, $m);
                foreach ($m[0] as $entity) {
                    if (!in_array($entity, self::MARKUP_OK, true)) {
                        $bad[] = basename(dirname($file)) . '/' . basename($file) . ':' . ($n + 1) . ' ' . $entity;
                    }
                }
            }
        }
        self::assertSame([], $bad);
    }

    public function testFrontendI18nJsonHasNoEntities(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/frontend/src/i18n/*.json') ?: [];
        self::assertNotEmpty($files);
        $bad = [];
        foreach ($files as $file) {
            $strings = [];
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            array_walk_recursive(
                $data,
                static function ($v, $k) use (&$strings): void {
                    if (is_string($v)) {
                        $strings[(string) $k] = $v;
                    }
                },
            );
            foreach ($strings as $k => $v) {
                if (preg_match(self::ENTITY, $v, $m) === 1) {
                    $bad[] = basename($file) . ' ' . $k . ' ' . $m[0];
                }
            }
        }
        self::assertSame([], $bad);
    }
}

<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\ExtrafieldsSchema;
use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\PlainTranslation;
use PHPUnit\Framework\TestCase;

/**
 * Dolibarr's Translate::trans() HTML-escapes its output (e.g. "Tag/cat&eacute;gorie").
 * Labels that leave Core as JSON must be plain UTF-8: the Vue front renders them
 * as text, so a raw entity would show up verbatim in the inspector.
 *
 * @covers \Knot\Dolibarr\PlainTranslation
 */
final class PlainTranslationTest extends TestCase
{
    private function escapingLangs(): object
    {
        return new class () {
            public function trans(string $key): string
            {
                $fr = ['Categories' => 'Tag/catégorie', 'Third party' => "Tiers l'été"];
                return htmlentities($fr[$key] ?? ('Catégorie ' . $key), ENT_QUOTES, 'UTF-8');
            }
        };
    }

    public function testDecodesEntitiesFromDolibarrTrans(): void
    {
        self::assertSame('Tag/catégorie', PlainTranslation::translate('Categories', $this->escapingLangs()));
        self::assertSame("Tiers l'été", PlainTranslation::translate('Third party', $this->escapingLangs()));
    }

    public function testFallsBackToKeyWithoutTranslator(): void
    {
        self::assertSame('Categories', PlainTranslation::translate('Categories', null));
    }

    public function testObjectsApiLabelsContainNoHtmlEntity(): void
    {
        $items = (new ObjectFactory())->listObjectsForApi($this->escapingLangs());
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertDoesNotMatchRegularExpression(
                '/&(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#x[0-9a-fA-F]+);/',
                $item['label'],
                'API label for ' . $item['slug'] . ' leaks an HTML entity',
            );
        }
    }
}

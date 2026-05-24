<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\DescriptorCache;
use Knot\Dolibarr\ObjectFactory;
use Knot\Dolibarr\SchemaBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Extended coverage for build/describe/list paths not hit by describe/map tests.
 *
 * @covers \Knot\Dolibarr\ObjectFactory
 */
final class ObjectFactoryExtendedTest extends TestCase
{
    private static bool $aliasesRegistered = false;

    private static string $dataRoot = '';

    public static function setUpBeforeClass(): void
    {
        if (self::$aliasesRegistered) {
            return;
        }

        $pairs = [
            'Facture' => \FakeFacture::class,
            'Societe' => \FakeSociete::class,
            'Propal' => \FakePropal::class,
            'PropaleLigne' => \FakeFactureLigne::class,
            'CustomDiscovered' => \FakeSociete::class,
        ];
        foreach ($pairs as $name => $target) {
            if (!class_exists($name, false)) {
                class_alias($target, $name);
            }
        }

        self::$dataRoot = sys_get_temp_dir() . '/knot-objectfactory-' . bin2hex(random_bytes(4));
        if (!is_dir(self::$dataRoot . '/knot')) {
            mkdir(self::$dataRoot . '/knot', 0755, true);
        }
        if (!defined('DOL_DATA_ROOT')) {
            define('DOL_DATA_ROOT', self::$dataRoot);
        }

        self::$aliasesRegistered = true;
    }

    private function db(): \DoliDB
    {
        return new class extends \DoliDB {};
    }

    protected function setUp(): void
    {
        $cachePath = DOL_DATA_ROOT . '/knot/' . DescriptorCache::FILENAME;
        if (is_file($cachePath)) {
            unlink($cachePath);
        }
    }

    public function testBuildAndFqcnForKnownSlug(): void
    {
        $factory = new ObjectFactory();
        $db = $this->db();

        $object = $factory->build('facture', $db);
        self::assertInstanceOf(\FakeFacture::class, $object);
        self::assertSame('\\Facture', $factory->fqcnForSlug('facture', $db));
    }

    public function testBuildNormalisesSlugInput(): void
    {
        $factory = new ObjectFactory();
        $object = $factory->build('  Facture ', $this->db());
        self::assertInstanceOf(\FakeFacture::class, $object);
    }

    public function testBuildRejectsUnknownSlug(): void
    {
        $factory = new ObjectFactory();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported Dolibarr object type');
        $factory->build('not-a-real-slug', $this->db());
    }

    private function aliasFactureLine(): void
    {
        if (!class_exists('FactureLigne', false)) {
            class_alias(\FakeFactureLigne::class, 'FactureLigne');
        }
    }

    public function testBuildLineForFacture(): void
    {
        $this->aliasFactureLine();
        $factory = new ObjectFactory();
        $line = $factory->buildLine('facture', $this->db());
        self::assertInstanceOf(\FakeFactureLigne::class, $line);
    }

    public function testBuildLineRejectsObjectWithoutLines(): void
    {
        $factory = new ObjectFactory();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support line items');
        $factory->buildLine('thirdparty', $this->db());
    }

    public function testGetLineClassReturnsDefinition(): void
    {
        $factory = new ObjectFactory();
        $line = $factory->getLineClass('facture');
        self::assertIsArray($line);
        self::assertSame('FactureLigne', $line['class']);
        self::assertNull($factory->getLineClass('thirdparty'));
    }

    public function testDescribeCachesSchemaPerSlug(): void
    {
        $factory = new ObjectFactory();
        $db = $this->db();

        $first = $factory->describe('facture', $db);
        $second = $factory->describe('facture', $db);

        self::assertSame($first, $second);
        self::assertSame('facture', $first['slug']);
        self::assertArrayHasKey('ref', $first['fields']);
    }

    public function testDescribeForActionFullViewAndUpdateAction(): void
    {
        $this->aliasFactureLine();
        $factory = new ObjectFactory();
        $langs = new \Translate();
        $schema = $factory->describeForAction(
            'facture',
            SchemaBuilder::ACTION_UPDATE,
            $this->db(),
            $langs,
            SchemaBuilder::FIELD_VIEW_FULL
        );

        self::assertSame('facture', $schema['x-knot-object']);
        self::assertArrayHasKey('lines', $schema['properties']);
        self::assertNotEmpty($schema['x-version-hash']);
    }

    public function testDescribeForActionUsesDescribeCache(): void
    {
        $this->aliasFactureLine();
        $factory = new ObjectFactory();
        $db = $this->db();
        $args = ['facture', SchemaBuilder::ACTION_CREATE, $db, null, SchemaBuilder::FIELD_VIEW_STANDARD];

        $first = $factory->describeForAction(...$args);
        $second = $factory->describeForAction(...$args);
        self::assertSame($first, $second);
    }

    public function testDiscoverVerbsReturnsAnnotatedList(): void
    {
        $factory = new ObjectFactory();
        $verbs = $factory->discoverVerbs('facture', $this->db(), true);

        self::assertNotEmpty($verbs);
        self::assertArrayHasKey('name', $verbs[0]);
        self::assertArrayHasKey('maturity', $verbs[0]);
    }

    public function testListObjectsForApiTranslatesLabels(): void
    {
        $factory = new ObjectFactory();
        $langs = new \Translate();
        $rows = $factory->listObjectsForApi($langs, $this->db());

        $bill = null;
        foreach ($rows as $row) {
            if ($row['slug'] === 'facture') {
                $bill = $row;
                break;
            }
        }
        self::assertNotNull($bill);
        self::assertSame('TR[Bill]', $bill['label']);
        self::assertSame('facture', $bill['element']);
    }

    public function testDiscoveredDescriptorsMergeIntoApiList(): void
    {
        $payload = [
            'hash' => 'discoveredhash',
            'generatedAt' => '2026-01-01T00:00:00+00:00',
            'descriptors' => [
                [
                    'slug' => 'customdiscovered',
                    'file' => '/custom/class/customdiscovered.class.php',
                    'class' => 'CustomDiscovered',
                    'module' => 'custom',
                    'source' => 'extension',
                    'supportsLines' => false,
                ],
            ],
        ];
        $written = file_put_contents(
            DOL_DATA_ROOT . '/knot/' . DescriptorCache::FILENAME,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        self::assertNotFalse($written);

        $factory = new ObjectFactory();
        $slugs = array_column($factory->listObjectsForApi(null, $this->db()), 'slug');
        self::assertContains('customdiscovered', $slugs);

        $built = $factory->build('customdiscovered', $this->db());
        self::assertInstanceOf(\FakeSociete::class, $built);
    }

    public function testGetVersionHashIncludesDescriptorCacheHash(): void
    {
        $payload = [
            'hash' => 'versionhash123',
            'generatedAt' => '2026-01-01T00:00:00+00:00',
            'descriptors' => [],
        ];
        file_put_contents(
            DOL_DATA_ROOT . '/knot/' . DescriptorCache::FILENAME,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );

        $factory = new ObjectFactory();
        $hash = $factory->getVersionHash($this->db());
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $hash);
    }
}

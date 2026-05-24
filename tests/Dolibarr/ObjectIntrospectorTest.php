<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\DescriptorCache;
use Knot\Dolibarr\ObjectIntrospector;
use PHPUnit\Framework\TestCase;

final class ObjectIntrospectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/knot-introspector-' . uniqid();
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmtree($this->root);
    }

    public function testSkipsClassFilesUnderNestedSkipDirSegments(): void
    {
        $goodDir = $this->root . '/societe/class';
        mkdir($goodDir, 0o755, true);
        file_put_contents($goodDir . '/goodsoc.class.php', "<?php\nclass GoodSocUnderSociete extends CommonObject {}\n");

        $cacheDir = $this->root . '/societe/cache/class';
        mkdir($cacheDir, 0o755, true);
        file_put_contents($cacheDir . '/badcache.class.php', "<?php\nclass BadSocUnderCache extends CommonObject {}\n");

        $introspector = new ObjectIntrospector($this->root);
        $classes = array_column($introspector->scan(), 'class');

        self::assertContains('GoodSocUnderSociete', $classes);
        self::assertNotContains('BadSocUnderCache', $classes);
    }

    public function testFindsClassFilesInAllowedModuleFolders(): void
    {
        $this->writeClassFile('societe', 'Societe', 'CommonObject');
        $this->writeClassFile('comm/propal', 'Propal', 'CommonProposal');

        $introspector = new ObjectIntrospector($this->root);
        $found = $introspector->scan();

        $slugs = array_column($found, 'slug');
        self::assertContains('thirdparty', $slugs);
        self::assertContains('propal', $slugs);
    }

    public function testIgnoresClassesThatDoNotExtendCommonObject(): void
    {
        $this->writeClassFile('societe', 'NonObject', 'SomeOtherBase');
        $introspector = new ObjectIntrospector($this->root);
        $found = $introspector->scan();
        self::assertSame([], $found);
    }

    public function testIgnoresHelperClasses(): void
    {
        $this->writeClassFile('societe', 'CommonObject', 'BaseClass');
        $this->writeClassFile('societe', 'SocieteHelper', 'CommonObject');
        $this->writeClassFile('societe', 'SocieteUtils', 'CommonObject');

        $introspector = new ObjectIntrospector($this->root);
        $found = $introspector->scan();
        $classes = array_column($found, 'class');
        self::assertNotContains('CommonObject', $classes);
        self::assertNotContains('SocieteHelper', $classes);
        self::assertNotContains('SocieteUtils', $classes);
    }

    public function testCustomModulesAreScanned(): void
    {
        $this->writeClassFile('custom/mymod', 'MyCustomThing', 'CommonObject');
        $introspector = new ObjectIntrospector($this->root);
        $found = $introspector->scan();
        self::assertNotEmpty($found);
        self::assertSame('mymod', $found[0]['module']);
        self::assertSame('custom', $found[0]['source']);
    }

    public function testKnotItselfIsExcludedFromCustomScan(): void
    {
        $this->writeClassFile('custom/knot', 'Whatever', 'CommonObject');
        $this->writeClassFile('custom/modKnotPro', 'WhateverElse', 'CommonObject');
        $introspector = new ObjectIntrospector($this->root);
        $found = $introspector->scan();
        self::assertSame([], $found);
    }

    public function testSlugAliasesPreserveBackwardCompatibility(): void
    {
        $introspector = new ObjectIntrospector($this->root);
        self::assertSame('thirdparty', $introspector->slugFromClass('Societe'));
        self::assertSame('member', $introspector->slugFromClass('Adherent'));
        self::assertSame('actioncomm', $introspector->slugFromClass('ActionComm'));
        self::assertSame('stockmove', $introspector->slugFromClass('MouvementStock'));
        self::assertSame('asset', $introspector->slugFromClass('Asset'));
    }

    public function testDescriptorCacheRoundTrip(): void
    {
        $cacheDir = $this->root . '/_cache';
        $cache = new DescriptorCache($cacheDir);
        self::assertNull($cache->read());
        $cache->write([
            ['slug' => 'thirdparty', 'class' => 'Societe', 'file' => '/societe/class/societe.class.php', 'module' => 'societe', 'source' => 'core', 'parent' => 'CommonObject', 'supportsLines' => false],
        ], 'abc123');
        $loaded = $cache->read();
        self::assertNotNull($loaded);
        self::assertSame('abc123', $loaded['hash']);
        self::assertCount(1, $loaded['descriptors']);
        $cache->clear();
        self::assertNull($cache->read());
    }

    private function writeClassFile(string $module, string $class, string $parent): void
    {
        $dir = $this->root . '/' . $module . '/class';
        @mkdir($dir, 0o755, true);
        $file = $dir . '/' . strtolower($class) . '.class.php';
        file_put_contents($file, "<?php\nclass $class extends $parent {}\n");
    }

    private function rmtree(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $i) {
            if ($i === '.' || $i === '..') continue;
            $full = $path . '/' . $i;
            is_dir($full) ? $this->rmtree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}

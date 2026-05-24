<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\KnotConfigRepository;
use PHPUnit\Framework\TestCase;

final class KnotConfigRepositoryTest extends TestCase
{
    public function testGetReturnsDefaultWhenNoRow(): void
    {
        $db = new InMemoryConfigDb();
        $repo = new KnotConfigRepository($db);
        self::assertSame('fallback', $repo->get('missing.key', 'fallback'));
    }

    public function testSetThenGetRoundTrip(): void
    {
        $db = new InMemoryConfigDb();
        $repo = new KnotConfigRepository($db);
        $repo->set('licensing.local_salt', 'deadbeef');
        self::assertSame('deadbeef', $repo->get('licensing.local_salt'));
    }

    public function testSetUpsertsExistingKey(): void
    {
        $db = new InMemoryConfigDb();
        $repo = new KnotConfigRepository($db);
        $repo->set('k', 'v1');
        $repo->set('k', 'v2');
        self::assertSame('v2', $repo->get('k'));
    }

    public function testDeleteRemovesKey(): void
    {
        $db = new InMemoryConfigDb();
        $repo = new KnotConfigRepository($db);
        $repo->set('k', 'v');
        $repo->delete('k');
        self::assertNull($repo->get('k'));
    }

    public function testEntityIsolation(): void
    {
        $db = new InMemoryConfigDb();

        $GLOBALS['conf'] = (object) ['entity' => 1];
        $repo1 = new KnotConfigRepository($db);
        $repo1->set('k', 'entity1-value');

        $GLOBALS['conf'] = (object) ['entity' => 2];
        $repo2 = new KnotConfigRepository($db);
        $repo2->set('k', 'entity2-value');

        $GLOBALS['conf'] = (object) ['entity' => 1];
        self::assertSame('entity1-value', $repo1->get('k'));

        $GLOBALS['conf'] = (object) ['entity' => 2];
        self::assertSame('entity2-value', $repo2->get('k'));
        unset($GLOBALS['conf']);
    }
}

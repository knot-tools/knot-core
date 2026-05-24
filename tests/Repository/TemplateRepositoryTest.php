<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Repository\TemplateRepository;
use Knot\Tests\Support\InMemoryTemplateDb;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Repository\TemplateRepository
 */
final class TemplateRepositoryTest extends TestCase
{
    public function testListCachedReturnsHydratedRowsOrderedByLabel(): void
    {
        $db = new InMemoryTemplateDb();
        $db->templates[1] = $this->templateRow(1, 'b-slug', 'B label', 1);
        $db->templates[2] = $this->templateRow(2, 'a-slug', 'A label', 1);

        $repo = new TemplateRepository($db);
        $rows = $repo->listCached(1);

        self::assertCount(2, $rows);
        self::assertSame('A label', $rows[0]['label']);
        self::assertSame('a-slug', $rows[0]['slug']);
        self::assertSame(['nodes' => [], 'edges' => []], $rows[0]['definition']);
    }

    public function testFindBySlugAndFindById(): void
    {
        $db = new InMemoryTemplateDb();
        $db->templates[5] = $this->templateRow(5, 'knot-tpl-demo', 'Demo', 1);

        $repo = new TemplateRepository($db);
        $bySlug = $repo->findBySlug('knot-tpl-demo', 1);
        self::assertNotNull($bySlug);
        self::assertSame(5, $bySlug['id']);

        self::assertNull($repo->findBySlug('missing', 1));
        self::assertSame(5, $repo->find(5, 1)['id'] ?? null);
        self::assertNull($repo->find(999, 1));
    }

    public function testCacheFromLicenseInsertsThenUpdates(): void
    {
        $db = new InMemoryTemplateDb();
        $repo = new TemplateRepository($db);

        $written = $repo->cacheFromLicense([
            [
                'slug' => 'knot-tpl-one',
                'label' => 'One',
                'description' => 'First',
                'templateCategory' => 'crm',
                'tier' => 'free',
                'icon' => 'mail',
                'definition' => ['nodes' => [['id' => 't1']]],
            ],
        ], 1);

        self::assertSame(1, $written);
        $first = $repo->findBySlug('knot-tpl-one', 1);
        self::assertNotNull($first);
        self::assertSame('One', $first['label']);

        $writtenAgain = $repo->cacheFromLicense([
            [
                'slug' => 'knot-tpl-one',
                'label' => 'One updated',
                'definition' => ['nodes' => []],
            ],
        ], 1);

        self::assertSame(1, $writtenAgain);
        self::assertSame('One updated', $repo->findBySlug('knot-tpl-one', 1)['label'] ?? null);
    }

    public function testCacheFromLicenseSkipsRowsWithoutSlug(): void
    {
        $db = new InMemoryTemplateDb();
        $repo = new TemplateRepository($db);

        self::assertSame(0, $repo->cacheFromLicense([['label' => 'Anonymous']], 1));
    }

    public function testPruneMissingDeletesStaleLicenseRows(): void
    {
        $db = new InMemoryTemplateDb();
        $db->templates[1] = $this->templateRow(1, 'keep-me', 'Keep', 1);
        $db->templates[2] = $this->templateRow(2, 'drop-me', 'Drop', 1);

        $repo = new TemplateRepository($db);
        $deleted = $repo->pruneMissing(['keep-me'], 1);

        self::assertSame(1, $deleted);
        self::assertNotNull($repo->findBySlug('keep-me', 1));
        self::assertNull($repo->findBySlug('drop-me', 1));
    }

    public function testPruneMissingReturnsZeroWhenKeepListEmpty(): void
    {
        $db = new InMemoryTemplateDb();
        $db->templates[1] = $this->templateRow(1, 'any', 'Any', 1);
        $repo = new TemplateRepository($db);

        self::assertSame(0, $repo->pruneMissing([], 1));
        self::assertNotNull($repo->findBySlug('any', 1));
    }

    public function testSeedShowcaseWorkflowsIsIdempotent(): void
    {
        $db = new InMemoryTemplateDb();
        $repo = new TemplateRepository($db);

        $first = $repo->seedShowcaseWorkflows(1, 3);
        $second = $repo->seedShowcaseWorkflows(1, 3);

        if ($first === 0) {
            self::markTestSkipped('Showcase fixtures not present in this checkout.');
        }

        self::assertGreaterThan(0, $first);
        self::assertSame(0, $second);
    }

    public function testSeedDemoWorkflowsIsIdempotent(): void
    {
        $db = new InMemoryTemplateDb();
        $repo = new TemplateRepository($db);

        $first = $repo->seedDemoWorkflows(1, 5);
        $second = $repo->seedDemoWorkflows(1, 5);

        if ($first === 0) {
            self::markTestSkipped('Starter fixtures not present in this checkout.');
        }

        self::assertGreaterThan(0, $first);
        self::assertSame(0, $second);
    }

    /** @return array<string, mixed> */
    private function templateRow(int $id, string $slug, string $label, int $entity): array
    {
        return [
            'rowid' => $id,
            'ref' => strtoupper(str_replace('-', '_', $slug)),
            'slug' => $slug,
            'label' => $label,
            'description' => 'desc',
            'category' => 'general',
            'tier' => 'free',
            'status' => 'published',
            'icon' => '',
            'json_definition' => json_encode(['nodes' => [], 'edges' => []], JSON_THROW_ON_ERROR),
            'cached_at' => '2026-01-01 00:00:00',
            'source' => 'license.knot.tools',
            'entity' => $entity,
        ];
    }
}

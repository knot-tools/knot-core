<?php

declare(strict_types=1);

namespace Knot\Tests\Dolibarr;

use Knot\Dolibarr\VerbDiscoverer;
use PHPUnit\Framework\TestCase;

final class VerbDiscovererTest extends TestCase
{
    public function testDiscoversAllowedVerbs(): void
    {
        $discoverer = new VerbDiscoverer();
        $verbs = $discoverer->discover(new FakeDolibarrObject());

        $names = array_map(static fn (array $v): string => $v['name'], $verbs);
        self::assertContains('validate', $names);
        self::assertContains('setDraft', $names);
        self::assertContains('setPaid', $names);
        self::assertContains('cloner', $names);
        self::assertContains('reopen', $names);
        self::assertContains('archive', $names);

        // Each entry has the expected shape
        foreach ($verbs as $verb) {
            self::assertArrayHasKey('parameters', $verb);
            self::assertArrayHasKey('maturity', $verb);
            self::assertArrayHasKey('pattern', $verb);
            self::assertSame(VerbDiscoverer::MATURITY_VERIFIED, $verb['maturity']);
        }
    }

    public function testRejectsCrudAndDestructiveMethods(): void
    {
        $discoverer = new VerbDiscoverer();
        $verbs = $discoverer->discover(new FakeDolibarrObject());
        $names = array_map(static fn (array $v): string => $v['name'], $verbs);
        // CRUD verbs are excluded (handled by SchemaBuilder)
        self::assertNotContains('create', $names);
        self::assertNotContains('update', $names);
        self::assertNotContains('fetch', $names);
        self::assertNotContains('delete', $names);

        // destructive verbs explicitly denied
        self::assertNotContains('deleteAll', $names);
        self::assertNotContains('wipe', $names);
        self::assertNotContains('truncateData', $names);
    }

    public function testDoesNotExposeNonMatchingMethods(): void
    {
        $discoverer = new VerbDiscoverer();
        $verbs = $discoverer->discover(new FakeDolibarrObject());
        $names = array_map(static fn (array $v): string => $v['name'], $verbs);
        self::assertNotContains('helperMethod', $names);
        self::assertNotContains('getSomething', $names);
    }

    public function testCapturesParameterMetadata(): void
    {
        $discoverer = new VerbDiscoverer();
        $verbs = $discoverer->discover(new FakeDolibarrObject());
        $cloner = null;
        foreach ($verbs as $v) {
            if ($v['name'] === 'cloner') {
                $cloner = $v;
            }
        }
        self::assertNotNull($cloner);
        self::assertCount(1, $cloner['parameters']);
        self::assertSame('newRef', $cloner['parameters'][0]['name']);
        self::assertSame('string', $cloner['parameters'][0]['type']);
        self::assertTrue($cloner['parameters'][0]['optional']);
    }

    public function testSimulateFlagsExperimentalOnException(): void
    {
        $discoverer = new VerbDiscoverer();
        $verbs = $discoverer->discover(new FakeDolibarrObject());
        $verbs = $discoverer->simulateAndAnnotate(new FakeDolibarrObject(), $verbs);

        $byName = [];
        foreach ($verbs as $v) {
            $byName[$v['name']] = $v;
        }

        // setDraft has no params and succeeds → stays verified
        self::assertSame(VerbDiscoverer::MATURITY_VERIFIED, $byName['setDraft']['maturity']);
        self::assertNull($byName['setDraft']['simulateError']);

        // archive raises a RuntimeException → downgraded to experimental
        self::assertSame(VerbDiscoverer::MATURITY_EXPERIMENTAL, $byName['archive']['maturity']);
        self::assertStringContainsString('archive failed', (string) $byName['archive']['simulateError']);

        // cloner takes one optional arg, but we pass no args, so the
        // simulator skips it and leaves it verified by pattern.
        self::assertSame(VerbDiscoverer::MATURITY_VERIFIED, $byName['cloner']['maturity']);
    }

    public function testHandlesClassNameInput(): void
    {
        $discoverer = new VerbDiscoverer();
        $verbs = $discoverer->discover(FakeDolibarrObject::class);
        self::assertNotEmpty($verbs);
    }

    public function testReturnsEmptyForUnknownClass(): void
    {
        $discoverer = new VerbDiscoverer();
        self::assertSame([], $discoverer->discover('Fully\\Bogus\\Class'));
    }
}

/**
 * Minimal Dolibarr-style stub to exercise pattern matching.
 */
class FakeDolibarrObject
{
    public function create($user = null) {}
    public function update($user = null) {}
    public function fetch($id) {}
    public function delete($user = null) {}

    public function validate(): bool { return true; }
    public function setDraft(): bool { return true; }
    public function setPaid($user = null): int { return 1; }
    public function setUnpaid($user = null): int { return 1; }
    public function reopen(): bool { return true; }
    public function archive(): void
    {
        throw new \RuntimeException('archive failed in simulate');
    }
    public function cloner(string $newRef = ''): int { return 1; }
    public function setStatusValidated($user = null): bool { return true; }

    // Verbs that must NOT be exposed
    public function deleteAll(): bool { return true; }
    public function wipe(): bool { return true; }
    public function truncateData(): bool { return true; }
    public function helperMethod() {}
    public function getSomething() {}
}

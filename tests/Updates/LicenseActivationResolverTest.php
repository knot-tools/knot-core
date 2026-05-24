<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Licensing\ActivationCodeProtector;
use Knot\Licensing\Bootstrap;
use Knot\Licensing\LicenseCache;
use Knot\Tests\Repository\InMemoryConfigDb;
use Knot\Updates\LicenseActivationResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Knot\Updates\LicenseActivationResolver
 */
final class LicenseActivationResolverTest extends TestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testReturnsNullForEmptyExtensionId(): void
    {
        $db = new InMemoryConfigDb();
        self::assertNull(LicenseActivationResolver::cleartextActivationForExtension($db, ''));
    }

    public function testReturnsNullWhenCacheMissesActivationCode(): void
    {
        $db = new InMemoryConfigDb();
        $cache = $this->cache();
        $cache->write($this->entry('knot-pro-pack'));

        self::assertNull(
            LicenseActivationResolver::cleartextActivationForExtension($db, 'knot-pro-pack', $cache),
        );
    }

    public function testDecryptsStoredActivationCode(): void
    {
        $db = new InMemoryConfigDb();
        $cache = $this->cache();
        $extensionId = 'knot-pro-pack';
        $plain = 'ACT-TEST-12345';
        $enc = ActivationCodeProtector::encrypt($plain, Bootstrap::localSalt($db), $extensionId);
        $entry = $this->entry($extensionId);
        $entry['activationCodeEnc'] = $enc;
        $cache->write($entry);

        self::assertSame(
            $plain,
            LicenseActivationResolver::cleartextActivationForExtension($db, $extensionId, $cache),
        );
    }

    private function cache(): LicenseCache
    {
        $this->tmpDir = sys_get_temp_dir() . '/knot-lic-resolver-' . bin2hex(random_bytes(4));
        return new LicenseCache($this->tmpDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $extensionId): array
    {
        return [
            'extensionId' => $extensionId,
            'instanceId' => 'demo',
            'signedPayload' => ['valid' => true, 'extensionId' => $extensionId],
            'signature' => 'sig',
            'signedAt' => '2026-05-20T00:00:00+00:00',
            'expiresAt' => null,
            'plan' => null,
            'issuedTo' => null,
            'lastSuccessfulRefresh' => '2026-05-20T00:00:00+00:00',
            'lastAttempt' => '2026-05-20T00:00:00+00:00',
            'lastError' => null,
        ];
    }
}

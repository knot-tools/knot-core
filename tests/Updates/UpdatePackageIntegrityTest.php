<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Licensing\PinnedPublicKeys;
use Knot\Licensing\SignatureVerifier;
use Knot\Tests\Licensing\Support\Ed25519TestHarness;
use Knot\Updates\GithubReleasesClient;
use Knot\Updates\ReleaseVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\ReleaseVerifier
 * @covers \Knot\Updates\GithubReleasesClient
 */
final class UpdatePackageIntegrityTest extends TestCase
{
    private const API_SOURCE = __DIR__ . '/../../api/updates_apply.php';

    /** Knot Core 2.13.24 release signature published in releases.json. */
    private const RELEASE_2_13_24 = [
        'payload' => [
            'channel' => 'beta',
            'version' => '2.13.24',
            'zip_sha256' => '8432e7f55eeceef36e055d9d530622984fead0ad4ea657d112c231c8574a3cfb',
            'zip_url' => 'https://knot.tools/downloads/knot-core/latest',
        ],
        'signature_hex' => '9b36617e7d08a3396a49a67d94121e89e61462cb60c003c72a59c7aa0bb3072a'
            . '1be43f83e5f3a9cdb48cd20dd97561108168f1ae33f21af3b86610ee1c967c07',
    ];

    /** Knot Core 2.13.23 release signature published in releases.json. */
    private const RELEASE_2_13_23 = [
        'payload' => [
            'channel' => 'beta',
            'version' => '2.13.23',
            'zip_sha256' => '35fdd965370fba68b49d37248f7dda014d3f5964952d96d216525abe01fe1253',
            'zip_url' => 'https://knot.tools/downloads/knot-core/latest',
        ],
        'signature_hex' => 'f72c533d62938da047652fe7dac9832ec64d7fd5f10080d585335c019d878498'
            . '63bfd9043b20833863d642c6cb3ff6df36c9d4e20c3111a98c8068124e579d0a',
    ];

    private string $zip = '';

    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('sodium extension required');
        }
        $path = tempnam(sys_get_temp_dir(), 'knot-package-');
        self::assertIsString($path);
        file_put_contents($path, "PK\x03\x04" . random_bytes(4096));
        $this->zip = $path;
    }

    protected function tearDown(): void
    {
        if ($this->zip !== '') {
            @unlink($this->zip);
        }
        parent::tearDown();
    }

    public function testPublishedReleaseSignaturesVerify(): void
    {
        $verifier = new SignatureVerifier(PinnedPublicKeys::releaseSigningKeysHex());
        foreach ([self::RELEASE_2_13_24, self::RELEASE_2_13_23] as $signed) {
            self::assertTrue($verifier->verify(SignatureVerifier::canonicalize($signed['payload']), $signed['signature_hex']));
        }
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function archiveHashProvider(): array
    {
        return [
            'archive hash set' => [true, '2.13.24'],
            'archive hash empty' => [false, '2.13.24'],
            'empty version' => [true, ''],
        ];
    }

    /**
     * The release signature must cover the downloaded archive and the requested version.
     */
    #[DataProvider('archiveHashProvider')]
    public function testCoreUpdateSignatureMustCoverArchive(bool $withArchiveHash, string $version): void
    {
        $sha = $withArchiveHash ? (string) hash_file('sha256', $this->zip) : '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Release signature');
        GithubReleasesClient::verifyReleaseIntegrity(
            $this->zip,
            $sha,
            self::RELEASE_2_13_24['payload'],
            self::RELEASE_2_13_24['signature_hex'],
            $version,
            'beta',
            '2.13.0',
        );
    }

    public function testCoreUpdateReadsArtefactFromManifestOnly(): void
    {
        $src = (string) file_get_contents(self::API_SOURCE);
        foreach (['download_url', 'zip_sha256', 'signature_payload', 'signature_hex'] as $field) {
            self::assertSame(0, substr_count($src, "\$body['" . $field . "']"), $field . ' must not be read from the request');
        }
    }

    /**
     * A releases.json entry is accepted only when its signature covers the listed archive.
     */
    public function testReleaseManifestEntryMustMatchSignedArchive(): void
    {
        $artifact = GithubReleasesClient::inferLatestArtifact([
            'schema_version' => 'knot-core-releases@v1',
            'latest' => [
                'version' => '2.13.23',
                'channel' => 'beta',
                'zip_url' => 'https://github.com/knot-tools/knot-core/releases/download/v2.13.23/module_knot-2.13.23.zip',
                'zip_sha256' => (string) hash_file('sha256', $this->zip),
                'signature_hex' => self::RELEASE_2_13_23['signature_hex'],
                'signature_payload' => self::RELEASE_2_13_23['payload'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Release signature');
        GithubReleasesClient::verifyReleaseIntegrity(
            $this->zip,
            $artifact['zip_sha256'],
            $artifact['signature_payload'],
            $artifact['signature_hex'],
            $artifact['version'],
            'beta',
            '2.13.0',
        );
    }

    /**
     * A version earlier than the installed one is refused (2.13.24 listed, 2.13.25 installed).
     */
    public function testCoreUpdateRefusesEarlierVersion(): void
    {
        $artifact = GithubReleasesClient::inferLatestArtifact([
            'schema_version' => 'knot-core-releases@v1',
            'latest' => [
                'version' => '2.13.24',
                'channel' => 'beta',
                'zip_url' => self::RELEASE_2_13_24['payload']['zip_url'],
                'zip_sha256' => self::RELEASE_2_13_24['payload']['zip_sha256'],
                'signature_hex' => self::RELEASE_2_13_24['signature_hex'],
                'signature_payload' => self::RELEASE_2_13_24['payload'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('older than the installed version 2.13.25');
        GithubReleasesClient::verifyReleaseIntegrity(
            $this->zip,
            $artifact['zip_sha256'],
            $artifact['signature_payload'],
            $artifact['signature_hex'],
            $artifact['version'],
            'beta',
            '2.13.25',
        );
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function coreRequirementProvider(): array
    {
        $base = ['channel' => 'beta', 'version' => '2.13.25'];

        return [
            'missing signature' => [$base, '', '2.13.25', 'beta', 'signature_hex is missing'],
            'empty version' => [$base, str_repeat('a', 128), '', 'beta', 'requested version'],
            'other version' => [$base, str_repeat('a', 128), '2.13.26', 'beta', 'requested version'],
            'other channel' => [$base, str_repeat('a', 128), '2.13.25', 'stable', 'requested channel'],
            'empty channel' => [$base, str_repeat('a', 128), '2.13.25', '', 'requested channel'],
            'invalid signature' => [$base, str_repeat('a', 128), '2.13.25', 'beta', 'verification failed'],
        ];
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('coreRequirementProvider')]
    public function testCoreReleaseRequirements(
        array $payload,
        string $signatureHex,
        string $version,
        string $channel,
        string $message,
    ): void {
        $payload['zip_sha256'] = (string) hash_file('sha256', $this->zip);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        ReleaseVerifier::assertCoreRelease($this->zip, $payload, $signatureHex, $version, $channel);
    }

    public function testExtensionUpdateSignatureMustCoverArchive(): void
    {
        $harness = new Ed25519TestHarness();
        $payload = [
            'channel' => 'beta',
            'product_slug' => 'knot-pro-pack',
            'version' => '0.1.4',
            'zip_sha256' => str_repeat('c', 64),
        ];
        $sig = sodium_bin2hex(sodium_crypto_sign_detached(SignatureVerifier::canonicalize($payload), $harness->secretKey()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not cover the downloaded ZIP');
        ReleaseVerifier::assertExtensionRelease($this->zip, $payload, $sig, 'knot-pro-pack', '0.1.4', 'beta');
    }

    public function testExtensionUpdateSignatureMustMatchProduct(): void
    {
        $payload = [
            'channel' => 'beta',
            'product_slug' => 'knot-migration',
            'version' => '0.1.4',
            'zip_sha256' => (string) hash_file('sha256', $this->zip),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requested product_slug');
        ReleaseVerifier::assertExtensionRelease($this->zip, $payload, str_repeat('a', 128), 'knot-pro-pack', '0.1.4', 'beta');
    }

    public function testExtensionUpdateRequiresSignature(): void
    {
        $payload = [
            'channel' => 'beta',
            'product_slug' => 'knot-pro-pack',
            'version' => '0.1.4',
            'zip_sha256' => (string) hash_file('sha256', $this->zip),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature_hex is missing');
        ReleaseVerifier::assertExtensionRelease($this->zip, $payload, '', 'knot-pro-pack', '0.1.4', 'beta');
    }
}

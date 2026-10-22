<?php

declare(strict_types=1);

namespace Knot\Tests\Updates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs api/updates_apply.php in a child PHP process against a loopback
 * releases.json and checks what a failed or refused Apply leaves behind.
 *
 * @coversNothing
 */
final class UpdatesApplyCleanupTest extends TestCase
{
    private const API = __DIR__ . '/../../api/updates_apply.php';

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

    private string $dir = '';

    /** @var resource|null */
    private $server;

    protected function setUp(): void
    {
        if (!extension_loaded('curl') || !extension_loaded('sodium')) {
            self::markTestSkipped('curl and sodium extensions required');
        }
        $dir = sys_get_temp_dir() . '/knot-apply-cleanup-' . bin2hex(random_bytes(4));
        mkdir($dir . '/htdocs/custom/knot', 0755, true);
        mkdir($dir . '/documents/knot', 0755, true);
        $this->dir = $dir;
        file_put_contents($dir . '/module.zip', "PK\x03\x04" . random_bytes(4096));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function failedApplyProvider(): array
    {
        return [
            'downgrade refused (409)' => ['2.13.25', 'signed', 'release_downgrade_refused'],
            'signature refused (422)' => ['2.13.0', 'served', 'release_signature_invalid'],
            'apply failed (500)' => ['2.13.0', 'wrong', 'apply_failed'],
        ];
    }

    #[DataProvider('failedApplyProvider')]
    public function testFailedApplyLeavesNoArtifactOrLock(string $installed, string $sha, string $expectedCode): void
    {
        $zipSha = match ($sha) {
            'signed' => self::RELEASE_2_13_24['payload']['zip_sha256'],
            'served' => (string) hash_file('sha256', $this->dir . '/module.zip'),
            default => str_repeat('0', 64),
        };
        $base = $this->startServer([
            'schema_version' => 'knot-core-releases@v1',
            'latest' => [
                'version' => '2.13.24',
                'channel' => 'beta',
                'zip_sha256' => $zipSha,
                'signature_hex' => self::RELEASE_2_13_24['signature_hex'],
                'signature_payload' => self::RELEASE_2_13_24['payload'],
            ],
        ]);

        $response = $this->runApply($base . '/releases.json', $installed);

        self::assertSame($expectedCode, $response['error']['code'] ?? null, json_encode($response) ?: '');
        $knotData = $this->dir . '/documents/knot';
        self::assertSame([], glob($knotData . '/update-artifact-*') ?: []);
        self::assertSame([], glob($knotData . '/update-stage-*') ?: []);
        self::assertFileDoesNotExist($knotData . '/update.lock');
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function startServer(array $manifest): string
    {
        $port = random_int(20000, 45000);
        $manifest['latest']['zip_url'] = 'http://127.0.0.1:' . $port . '/module.zip';
        file_put_contents($this->dir . '/releases.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
        $router = $this->dir . '/router.php';
        file_put_contents($router, '<?php $f = ' . var_export($this->dir, true)
            . ' . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if (!is_file($f)) { http_response_code(404); return true; }'
            . ' readfile($f); return true;');
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start loopback server');
        }
        $this->server = $process;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && @file_get_contents('http://127.0.0.1:' . $port . '/releases.json') === false) {
            usleep(50_000);
        }

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * @return array<string, mixed>
     */
    private function runApply(string $manifestUrl, string $installed): array
    {
        $env = [
            'KNOT_TEST' => '1',
            'CONTEXT_DOCUMENT_ROOT' => $this->dir . '/htdocs',
            'KNOT_TEST_DATA_ROOT' => $this->dir . '/documents',
            'KNOT_TEST_MANIFEST_URL' => $manifestUrl,
            'PATH' => (string) getenv('PATH'),
        ];
        file_put_contents($this->dir . '/htdocs/main.inc.php', $this->bootstrapSource($installed));
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', self::API], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run updates_apply.php');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $decoded = json_decode($out, true);
        self::assertIsArray($decoded, $out . $err);

        return $decoded;
    }

    private function bootstrapSource(string $installed): string
    {
        $root = var_export(dirname(__DIR__, 2), true);
        $version = var_export($installed, true);

        return <<<PHP
<?php
define('DOL_DOCUMENT_ROOT', __DIR__);
define('DOL_DATA_ROOT', (string) getenv('KNOT_TEST_DATA_ROOT'));
class modKnot
{
    public string \$version = {$version};
}
require_once {$root} . '/class/autoload.php';
require_once {$root} . '/tests/stubs/dolibarr.php';
function dol_buildpath(string \$path, int \$type = 0): string
{
    return __DIR__ . '/custom' . \$path;
}
\$GLOBALS['knot_test_globals_string'] = ['MAIN_KNOT_CORE_RELEASES_JSON_URL' => (string) getenv('KNOT_TEST_MANIFEST_URL')];
\$db = new class extends DoliDB {
};
\$conf = (object) ['entity' => 1];
\$user = new FakeUser();
\$user->rightsTriple['knot']['admin']['configure'] = true;
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SESSION = ['token' => 'knot-test-token'];
\$_POST = ['slug' => 'knot', 'token' => 'knot-test-token'];
return 1;
PHP;
    }
}

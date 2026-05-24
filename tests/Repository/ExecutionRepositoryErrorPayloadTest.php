<?php

declare(strict_types=1);

namespace Knot\Tests\Repository;

use Knot\Errors\ExecutionErrorPayloadCodec;
use Knot\Repository\ExecutionRepository;
use PHPUnit\Framework\TestCase;

final class ExecutionRepositoryErrorPayloadTest extends TestCase
{
    public function testFetchOneDecodesValidErrorPayloadJson(): void
    {
        $payload = ['code' => 'KNOT_TEST', 'user_message' => 'hello'];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        self::assertNotFalse($encoded);

        $db = $this->mockDbForFetchOne((string) $encoded);
        $repo = new ExecutionRepository($db);
        $row = $repo->fetchOne(42, 1);

        self::assertNotNull($row);
        self::assertSame($payload, $row['errorPayload']);
    }

    public function testFetchOneInvalidErrorPayloadJsonYieldsNull(): void
    {
        $db = $this->mockDbForFetchOne('{not-json');
        $repo = new ExecutionRepository($db);
        $row = $repo->fetchOne(42, 1);

        self::assertNotNull($row);
        self::assertNull($row['errorPayload']);
    }

    public function testFetchOneNullErrorPayloadColumnYieldsNull(): void
    {
        $db = $this->mockDbForFetchOne(null);
        $repo = new ExecutionRepository($db);
        $row = $repo->fetchOne(42, 1);

        self::assertNotNull($row);
        self::assertNull($row['errorPayload']);
    }

    public function testRecordFailureAndScheduleRetryPersistsEncodedErrorPayloadInSql(): void
    {
        $payload = ['code' => 'KNOT_SAVE_TEST', 'user_message' => 'boom'];
        $expectedLiteral = ExecutionErrorPayloadCodec::encode($payload);
        self::assertNotNull($expectedLiteral);

        $db = new class extends \DoliDB {
            /** @var list<string> */
            public array $queries = [];

            private bool $selectPending = true;

            public function query(string $sql): bool
            {
                $this->queries[] = $sql;

                return true;
            }

            public function num_rows($resource): int
            {
                return $this->selectPending ? 1 : 0;
            }

            public function fetch_object($resource): ?object
            {
                if (!$this->selectPending) {
                    return null;
                }
                $this->selectPending = false;

                return ExecutionRepositoryErrorPayloadTest::minimalExecutionRow(
                    retryCount: 0,
                    errorPayload: null,
                );
            }

            public function escape(string $value): string
            {
                return addslashes($value);
            }

            public function idate(int $timestamp): string
            {
                return date('Y-m-d H:i:s', $timestamp);
            }
        };

        $repo = new ExecutionRepository($db);
        $result = $repo->recordFailureAndScheduleRetry(
            99,
            7,
            'terminal msg',
            maxAttempts: 1,
            backoffStrategy: 'exponential',
            errorPayload: $payload,
        );

        self::assertSame('terminal_error', $result);
        self::assertGreaterThanOrEqual(2, count($db->queries));

        $updateSql = $db->queries[count($db->queries) - 1];
        self::assertStringContainsString('UPDATE', $updateSql);
        self::assertStringContainsString('error_payload', $updateSql);
        $escaped = "'" . addslashes((string) $expectedLiteral) . "'";
        self::assertStringContainsString('error_payload = ' . $escaped, $updateSql);
    }

    private function mockDbForFetchOne(?string $errorPayloadColumn): \DoliDB
    {
        return new class ($errorPayloadColumn) extends \DoliDB {
            public function __construct(private readonly ?string $errorPayloadColumn)
            {
            }

            public function query(string $sql): bool
            {
                return true;
            }

            public function num_rows($resource): int
            {
                return 1;
            }

            public function fetch_object($resource): ?object
            {
                return ExecutionRepositoryErrorPayloadTest::minimalExecutionRow(
                    retryCount: 0,
                    errorPayload: $this->errorPayloadColumn,
                );
            }
        };
    }

    /**
     * @internal Used by anonymous DB mocks in this test file.
     */
    public static function minimalExecutionRow(int $retryCount, ?string $errorPayload): object
    {
        return (object) [
            'rowid' => 42,
            'fk_workflow' => 3,
            'status' => 'running',
            'trigger_type' => 'manual',
            'trigger_data' => '{}',
            'retry_count' => $retryCount,
            'priority' => 5,
            'scheduled_at' => null,
            'next_retry_at' => null,
            'max_attempts' => 3,
            'backoff_strategy' => 'exponential',
            'worker_id' => null,
            'started_at' => null,
            'ended_at' => null,
            'error_message' => null,
            'error_payload' => $errorPayload,
            'duration_ms' => null,
        ];
    }
}

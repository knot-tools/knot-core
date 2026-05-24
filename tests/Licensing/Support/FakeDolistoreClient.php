<?php

declare(strict_types=1);

namespace Knot\Tests\Licensing\Support;

use Knot\Licensing\DolistoreClientContract;
use RuntimeException;

/**
 * In-memory test double for the Dolistore licence backend.
 */
final class FakeDolistoreClient implements DolistoreClientContract
{
    /** @var array<int, array{params: array<string, mixed>, ts: int}> */
    public array $calls = [];

    /** @var callable|null */
    private $responder;

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $responder
     */
    public function setResponder(callable $responder): void
    {
        $this->responder = $responder;
    }

    public function failWith(string $message): void
    {
        $this->responder = static function () use ($message): array {
            throw new RuntimeException($message);
        };
    }

    public function check(array $params): array
    {
        $this->calls[] = ['params' => $params, 'ts' => time()];
        if ($this->responder === null) {
            throw new RuntimeException('FakeDolistoreClient has no responder configured');
        }
        $resp = ($this->responder)($params);
        /**
         * @var array{
         *     valid: bool,
         *     expiresAt: ?string,
         *     plan: ?string,
         *     issuedTo: ?string,
         *     signature: string,
         *     signedAt: string,
         *     payload: array<string, mixed>
         * } $resp
         */
        return $resp;
    }
}

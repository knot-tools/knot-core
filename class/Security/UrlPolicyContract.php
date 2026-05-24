<?php

declare(strict_types=1);

namespace Knot\Security;

/**
 * Minimal contract for outbound URL validation used by {@see HttpClient}.
 */
interface UrlPolicyContract
{
    /**
     * @return array{host:string, ip:string, port:int}|null
     */
    public function resolve(string $url): ?array;
}

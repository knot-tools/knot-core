<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Tests\Updates;

use Knot\Updates\ReleaseVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \Knot\Updates\ReleaseVerifier
 */
final class ReleaseVerifierTest extends TestCase
{
    public function testAssertZipSha256SkipsWhenExpectedEmpty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knot-z1-');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'demo');
            ReleaseVerifier::assertZipSha256($path, '');
            self::addToAssertionCount(1);
        } finally {
            @unlink((string) $path);
        }
    }

    public function testAssertZipSha256MismatchThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knot-z2-');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'hello');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('checksum mismatch');
            ReleaseVerifier::assertZipSha256($path, str_repeat('a', 64));
        } finally {
            @unlink((string) $path);
        }
    }

    public function testAssertZipSha256RejectsMalformedExpectedHex(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knot-z3-');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'x');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid expected zip_sha256');
            ReleaseVerifier::assertZipSha256($path, 'not-hex');
        } finally {
            @unlink((string) $path);
        }
    }

    public function testAssertOptionalDetachedSignatureNoOpsWhenSignatureEmpty(): void
    {
        ReleaseVerifier::assertOptionalDetachedSignature(['version' => '1'], '');
        self::addToAssertionCount(1);
    }

    public function testAssertOptionalDetachedSignatureRequiresPayloadWhenSignaturePresent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical signing payload metadata is missing');
        ReleaseVerifier::assertOptionalDetachedSignature(null, str_repeat('a', 128));
    }
}

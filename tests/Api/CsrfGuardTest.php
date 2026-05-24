<?php

declare(strict_types=1);

namespace Knot\Tests\Api;

use Knot\Api\CsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * Regression for E2E-003: CsrfGuard previously called `checkToken()`
 * which Dolibarr 21 inlined into main.inc.php. The helper no longer
 * exists, `function_exists` returned false, and the guard silently
 * defaulted to `return true` — accepting empty / forged tokens.
 *
 * These tests exercise the strict pattern (compare to $_SESSION['token']
 * with hash_equals) and document the contract.
 */
final class CsrfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testRejectsEmptyToken(): void
    {
        $_SESSION['token'] = 'abcdef0123456789';
        // No token supplied anywhere
        self::assertFalse(CsrfGuard::verify());
    }

    public function testRejectsBogusToken(): void
    {
        $_SESSION['token'] = 'abcdef0123456789';
        $_POST['token'] = 'totally-bogus-token';
        self::assertFalse(CsrfGuard::verify());
    }

    public function testRejectsNotRequiredSentinel(): void
    {
        $_SESSION['token'] = 'abcdef0123456789';
        $_POST['token'] = 'notrequired';
        self::assertFalse(CsrfGuard::verify());
    }

    public function testRejectsWhenSessionHasNoToken(): void
    {
        $_POST['token'] = 'whatever';
        // Session has no stored token → cannot validate
        self::assertFalse(CsrfGuard::verify());
    }

    public function testAcceptsMatchingToken(): void
    {
        $_SESSION['token'] = 'abcdef0123456789';
        $_POST['token'] = 'abcdef0123456789';
        self::assertTrue(CsrfGuard::verify());
    }

    public function testAcceptsHeaderToken(): void
    {
        $_SESSION['token'] = 'header-token-xyz';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'header-token-xyz';
        self::assertTrue(CsrfGuard::verify());
    }

    public function testHeaderTokenLosesToExplicitPost(): void
    {
        // If $_POST already has a token, header is ignored
        $_SESSION['token'] = 'session-tok';
        $_POST['token'] = 'wrong-from-post';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'session-tok';
        self::assertFalse(CsrfGuard::verify());
    }

    public function testTimingSafeComparison(): void
    {
        // hash_equals returns false for different lengths
        $_SESSION['token'] = 'short';
        $_POST['token'] = 'much-longer-string-that-starts-with-short';
        self::assertFalse(CsrfGuard::verify());
    }
}

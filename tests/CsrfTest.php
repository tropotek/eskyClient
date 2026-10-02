<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testATokenIsStableWithinASession(): void
    {
        self::assertSame(Csrf::token(), Csrf::token());
        self::assertSame(64, strlen(Csrf::token()));
    }

    public function testTheIssuedTokenVerifies(): void
    {
        self::assertTrue(Csrf::verify(Csrf::token()));
    }

    public function testWrongEmptyAndMissingValuesAreRejected(): void
    {
        Csrf::token();

        self::assertFalse(Csrf::verify('nope'));
        self::assertFalse(Csrf::verify(''));
        self::assertFalse(Csrf::verify(null));
        self::assertFalse(Csrf::verify(['x']));
    }

    /* A form can be posted before the visitor was ever issued a token, e.g.
       with a stale cookie; empty must not equal empty. */
    public function testNothingVerifiesBeforeATokenWasIssued(): void
    {
        self::assertFalse(Csrf::verify(''));
        self::assertFalse(Csrf::verify('anything'));
    }

    public function testATamperedNonStringSessionValueFailsClosed(): void
    {
        $_SESSION['esky_csrf'] = ['abc'];

        self::assertFalse(Csrf::verify('abc'));
    }
}

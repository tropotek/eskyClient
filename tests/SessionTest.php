<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testNothingChosenYetIsNull(): void
    {
        self::assertNull(Session::vault());
    }

    public function testTheChosenVaultIsRemembered(): void
    {
        Session::setVault('work');

        self::assertSame('work', Session::vault());
    }

    /* The session is a cookie the visitor controls, so anything that is not a
       plain string is treated as nothing chosen. */
    public function testANonStringInTheSessionIsIgnored(): void
    {
        $_SESSION['esky_vault'] = ['work'];

        self::assertNull(Session::vault());
    }
}

<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Layout;
use PHPUnit\Framework\TestCase;

final class LayoutErrorTest extends TestCase
{
    public function testAServerFaultSaysSomethingWentWrong(): void
    {
        self::assertSame('Something went wrong', Layout::errorHeading(500));
    }

    /* A 403 or 404 is the visitor's request being refused, not the app failing. */
    public function testARefusedRequestDoesNotBlameTheApp(): void
    {
        foreach ([400, 403, 404, 405, 409] as $status) {
            self::assertSame('That did not work', Layout::errorHeading($status));
        }
    }
}

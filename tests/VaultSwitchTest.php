<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Layout;
use PHPUnit\Framework\TestCase;

final class VaultSwitchTest extends TestCase
{
    /* back comes straight off the query string, so only the two pages that
       mean anything after a switch are honoured. view.php is deliberately not
       among them: a uid belongs to one vault, so switching lands on the list. */
    public function testOnlyTheListAndMetricsAreAcceptedAsReturnTargets(): void
    {
        self::assertSame('/index.php', Layout::backTarget('/index.php'));
        self::assertSame('/metrics.php', Layout::backTarget('/metrics.php'));
    }

    public function testAnythingElseFallsBackToTheList(): void
    {
        foreach ([
            null,
            '',
            '/view.php?uid=abc',
            '/settings.php',
            'https://evil.test/',
            '//evil.test/',
            '/index.php?q=x',
            '../../etc/passwd',
        ] as $raw) {
            self::assertSame('/index.php', Layout::backTarget($raw), var_export($raw, true));
        }
    }
}

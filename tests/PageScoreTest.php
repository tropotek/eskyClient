<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Page;
use PHPUnit\Framework\TestCase;

final class PageScoreTest extends TestCase
{
    public function testAScoreShowsThreeDecimals(): void
    {
        self::assertSame('0.033', Page::score(0.03278688524590164));
        self::assertSame('0.016', Page::score(0.016129032258064516));
        self::assertSame('1.000', Page::score(1));
    }

    /* Older servers send no score; the card then shows nothing, not "0.000". */
    public function testAMissingOrNonNumericScoreIsBlank(): void
    {
        self::assertSame('', Page::score(null));
        self::assertSame('', Page::score('high'));
        self::assertSame('', Page::score([0.5]));
    }
}

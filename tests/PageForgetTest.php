<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Page;
use PHPUnit\Framework\TestCase;

final class PageForgetTest extends TestCase
{
    public function testFindReturnsTheRecordWithThatUid(): void
    {
        $records = [['uid' => 'a', 'n' => 1], ['uid' => 'b', 'n' => 2]];

        self::assertSame(['uid' => 'b', 'n' => 2], Page::find($records, 'b'));
    }

    public function testFindReturnsNullForAnUnknownUid(): void
    {
        self::assertNull(Page::find([['uid' => 'a']], 'zzz'));
        self::assertNull(Page::find([], 'a'));
    }

    public function testFindIgnoresRecordsWithoutAUid(): void
    {
        self::assertNull(Page::find([['title' => 'x']], ''));
    }

    public function testReasonIsTrimmed(): void
    {
        self::assertSame('stale', Page::reason("  stale \n"));
    }

    public function testAnEmptyReasonIsNull(): void
    {
        self::assertNull(Page::reason(''));
        self::assertNull(Page::reason("   \n\t"));
    }

    public function testReasonIsCappedInCharactersNotBytes(): void
    {
        $reason = Page::reason(str_repeat('é', 600));

        self::assertSame(500, mb_strlen((string) $reason));
    }

    /* Escaping is the page's job, not the helper's: the text must come back
       untouched so Page::e sees the real characters. */
    public function testReasonDoesNotAlterMarkup(): void
    {
        self::assertSame('<b>x</b>', Page::reason('<b>x</b>'));
    }
}

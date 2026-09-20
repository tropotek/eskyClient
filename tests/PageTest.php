<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testHeadingPrefersTheTitle(): void
    {
        self::assertSame('Docker notes', Page::heading(['title' => 'Docker notes', 'kind' => 'note']));
    }

    public function testHeadingFallsBackToKindWhenTitleIsAbsentOrBlank(): void
    {
        self::assertSame('note', Page::heading(['kind' => 'note']));
        self::assertSame('note', Page::heading(['title' => '  ', 'kind' => 'note']));
        self::assertSame('memory', Page::heading([]));
    }

    public function testStampShowsTheDateAndA24HourTime(): void
    {
        $tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            self::assertSame('2026-09-19 14:05', Page::stamp('2026-09-19T14:05:09.123456+00:00'));
        } finally {
            date_default_timezone_set($tz);
        }
    }

    public function testStampPassesThroughWhatItCannotParse(): void
    {
        self::assertSame('', Page::stamp(null));
        self::assertSame('not a date', Page::stamp('not a date'));
    }

    /* Tokens are shown on the settings page so an operator can tell which one
       a vault is using, without the page handing the whole secret to anyone
       who can reach the port. */
    public function testALongTokenKeepsItsEndsOnly(): void
    {
        self::assertSame('sk-…f3a2', Page::mask('sk-personal-0000f3a2'));
    }

    public function testAShortTokenIsHiddenEntirely(): void
    {
        self::assertSame('…', Page::mask('short'));
        self::assertSame('…', Page::mask('elevenchars'));
    }

    public function testAnEmptyTokenIsHiddenEntirely(): void
    {
        self::assertSame('…', Page::mask(''));
    }
}

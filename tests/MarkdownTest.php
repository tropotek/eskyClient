<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Markdown;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    public function testRendersBasicCommonMark(): void
    {
        $html = Markdown::toHtml("# Title\n\nSome **bold** text.");

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testRendersPipeTables(): void
    {
        $html = Markdown::toHtml("| Tool | Arguments |\n|---|---|\n| `memory_recent` | `limit` |");

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>Tool</th>', $html);
        self::assertStringContainsString('<td><code>memory_recent</code></td>', $html);
        self::assertStringNotContainsString('| Tool |', $html);
    }

    public function testRendersStrikethroughAndTaskLists(): void
    {
        $html = Markdown::toHtml("~~gone~~\n\n- [x] done\n- [ ] pending");

        self::assertStringContainsString('<del>gone</del>', $html);
        self::assertStringContainsString('type="checkbox"', $html);
    }

    public function testEscapesRawHtmlInMemoryText(): void
    {
        $html = Markdown::toHtml('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testRejectsUnsafeLinks(): void
    {
        $html = Markdown::toHtml('[click](javascript:alert(1))');

        self::assertStringNotContainsString('javascript:', $html);
    }
}

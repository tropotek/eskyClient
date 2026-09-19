<?php
declare(strict_types=1);

namespace Esky;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders memory text as HTML using GitHub-Flavored Markdown, which adds the
 * tables, strikethrough and task lists that plain CommonMark omits.
 * Raw HTML in the source is escaped, not trusted.
 */
final class Markdown
{
    public static function toHtml(string $text): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($text);
    }
}

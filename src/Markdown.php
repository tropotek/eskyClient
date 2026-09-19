<?php
declare(strict_types=1);

namespace Esky;

use League\CommonMark\CommonMarkConverter;

/** Renders memory text as HTML. Raw HTML in the source is escaped, not trusted. */
final class Markdown
{
    public static function toHtml(string $text): string
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($text);
    }
}

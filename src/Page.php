<?php
declare(strict_types=1);

namespace Esky;

/** Shared view helpers for the two page scripts. */
final class Page
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function preview(string $text, int $length = 150): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($flat) <= $length ? $flat : mb_substr($flat, 0, $length) . '…';
    }

    /** A record's display label: its title, or the kind when it has none. */
    public static function heading(array $record): string
    {
        $title = trim((string) ($record['title'] ?? ''));

        return $title !== '' ? $title : (string) ($record['kind'] ?? 'memory');
    }

    /**
     * esky stores ISO-8601 UTC instants; show them in the server's timezone
     * to the minute, which is as precise as anything on these pages needs.
     */
    public static function stamp(?string $iso): string
    {
        $iso = trim((string) $iso);
        if ($iso === '') {
            return '';
        }

        try {
            $when = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return $iso;
        }

        return $when->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i');
    }

    public static function error(string $message): never
    {
        http_response_code(500);
        $safe = self::e($message);
        echo <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <title>Esky — error</title>
        <link rel="stylesheet" href="/style.css"></head>
        <body><main class="wrap">
        <h1>Something went wrong</h1>
        <p class="error">{$safe}</p>
        <p><a href="/">Back to the list</a></p>
        </main></body></html>
        HTML;
        exit;
    }
}

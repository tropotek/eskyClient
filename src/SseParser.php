<?php
declare(strict_types=1);

namespace Esky;

/**
 * Turns a Server-Sent Events response body into decoded JSON-RPC messages.
 * Only `data:` lines carry payload; anything else is ignored.
 */
final class SseParser
{
    /** @return list<array<string, mixed>> */
    public static function messages(string $body): array
    {
        $messages = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));
            if ($payload === '') {
                continue;
            }

            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }
}

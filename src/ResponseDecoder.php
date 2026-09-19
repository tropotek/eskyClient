<?php
declare(strict_types=1);

namespace Esky;

/**
 * Unwraps an esky tools/call response. The payload is double encoded:
 * result.content[0].text is a JSON string holding the array of records.
 */
final class ResponseDecoder
{
    /** @return list<array<string, mixed>> */
    public static function records(array $message): array
    {
        if (isset($message['error'])) {
            $text = $message['error']['message'] ?? 'unknown error';
            $code = $message['error']['code'] ?? 0;
            throw new EskyException(sprintf('esky returned an error (%s): %s', $code, $text));
        }

        if (!isset($message['result']) || !is_array($message['result'])) {
            throw new EskyException('esky response contained no result');
        }

        $content = $message['result']['content'] ?? null;
        if (!is_array($content) || $content === []) {
            return [];
        }

        $text = $content[0]['text'] ?? null;
        if (!is_string($text)) {
            throw new EskyException('esky response content was malformed: no text member');
        }

        $records = json_decode($text, true);
        if (!is_array($records)) {
            throw new EskyException('esky response content was malformed: inner payload is not JSON');
        }

        return array_values($records);
    }
}

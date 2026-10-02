<?php
declare(strict_types=1);

namespace Esky;

/**
 * Summarises one search's results for the strip above the list. Pure: it reads
 * only the records it is handed, so it needs no server and no session.
 *
 * Every field except kind may be absent (older servers send no score or
 * layer), so each part is skipped rather than assumed.
 */
final class Stats
{
    private const TOP_TAGS = 5;

    /**
     * @param list<array<string, mixed>> $records
     * @return array{count: int, scoreMin: ?float, scoreMax: ?float, kinds: array<string, int>, layers: array<string, int>, tags: array<string, int>, oldest: ?string, newest: ?string}
     */
    public static function summarise(array $records): array
    {
        $kinds = [];
        $layers = [];
        $tags = [];
        $scores = [];
        $instants = [];

        foreach ($records as $record) {
            self::tally($kinds, $record['kind'] ?? null);
            self::tally($layers, $record['layer'] ?? null);

            $recordTags = $record['tags'] ?? null;
            foreach (is_array($recordTags) ? $recordTags : [] as $tag) {
                self::tally($tags, $tag);
            }

            $score = $record['score'] ?? null;
            if (is_int($score) || is_float($score)) {
                $scores[] = (float) $score;
            }

            $stamp = $record['updated_at'] ?? null;
            if (is_string($stamp) && $stamp !== '') {
                try {
                    $instants[$stamp] = (new \DateTimeImmutable($stamp))->getTimestamp();
                } catch (\Exception) {
                    // An unreadable date is left out of the range, not guessed at.
                }
            }
        }

        asort($instants);
        $stamps = array_keys($instants);

        return [
            'count' => count($records),
            'scoreMin' => $scores === [] ? null : min($scores),
            'scoreMax' => $scores === [] ? null : max($scores),
            'kinds' => self::ranked($kinds),
            'layers' => self::ranked($layers),
            'tags' => array_slice(self::ranked($tags), 0, self::TOP_TAGS, true),
            'oldest' => $stamps === [] ? null : $stamps[0],
            'newest' => $stamps === [] ? null : $stamps[count($stamps) - 1],
        ];
    }

    /**
     * Plain-text badge labels, unescaped: the caller escapes on output.
     *
     * @param array{count: int, scoreMin: ?float, scoreMax: ?float, kinds: array<string, int>, layers: array<string, int>, tags: array<string, int>, oldest: ?string, newest: ?string} $summary
     * @return list<string>
     */
    public static function labels(array $summary): array
    {
        $labels = [];

        if ($summary['scoreMin'] !== null && $summary['scoreMax'] !== null) {
            $min = sprintf('%.3f', $summary['scoreMin']);
            $max = sprintf('%.3f', $summary['scoreMax']);
            // The score ranks results against each other; it is not a
            // similarity, so it is shown as a range and never as a percentage.
            $labels[] = $min === $max ? "score {$min}" : "score {$min}–{$max}";
        }

        foreach (['kind' => 'kinds', 'layer' => 'layers', 'tags' => 'tags'] as $name => $key) {
            if ($summary[$key] !== []) {
                $labels[] = $name . ': ' . self::counts($summary[$key]);
            }
        }

        if ($summary['oldest'] !== null && $summary['newest'] !== null) {
            $from = substr(Page::stamp($summary['oldest']), 0, 10);
            $to = substr(Page::stamp($summary['newest']), 0, 10);
            $labels[] = $from === $to ? $from : "{$from} → {$to}";
        }

        return $labels;
    }

    /** @param array<string, int> $counts */
    private static function tally(array &$counts, mixed $value): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    /**
     * Most frequent first, name ascending on a tie, so the order is stable
     * from one request to the next.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function ranked(array $counts): array
    {
        $keys = array_keys($counts);
        usort($keys, static fn ($a, $b): int => $counts[$b] <=> $counts[$a] ?: strcmp((string) $a, (string) $b));

        $ranked = [];
        foreach ($keys as $key) {
            $ranked[$key] = $counts[$key];
        }

        return $ranked;
    }

    /** @param array<string, int> $counts */
    private static function counts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $name => $n) {
            $parts[] = $name . ' ' . $n;
        }

        return implode(' · ', $parts);
    }
}

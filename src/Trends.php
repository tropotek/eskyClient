<?php
declare(strict_types=1);

namespace Esky;

/**
 * Derivations over the server's daily rows. Pure: it reads only the rows it is
 * handed, so the metrics page can be rendered without a server and the suite
 * can test each shape against a configuration held in memory.
 */
final class Trends
{
    /**
     * Daily miss rate as a percentage. A day with no searches has no rate at
     * all — plotting zero would read as "all answered", so it is left as zero
     * only for the chart's sake and the hover carries the context.
     *
     * @param list<array<string, mixed>> $daily rows with date, searches, zero_match
     * @return list<array{label: string, values: list<float>}>
     */
    public static function missRateDaily(array $daily): array
    {
        $out = [];
        foreach ($daily as $row) {
            $searches = (int) ($row['searches'] ?? 0);
            $misses = (int) ($row['zero_match'] ?? 0);
            $rate = $searches > 0 ? round($misses / $searches * 100, 1) : 0.0;
            $out[] = ['label' => (string) $row['date'], 'values' => [$rate]];
        }

        return $out;
    }

    /**
     * Daily counts rolled up by ISO week, labelled by the Monday that starts
     * it. A partial first or last week is kept as-is: the window the user
     * chose is what the totals belong to.
     *
     * @param list<array<string, mixed>> $daily rows with a date and the named counts
     * @param list<string> $keys which counts to sum
     * @return list<array{label: string, values: list<int>}>
     */
    public static function weekly(array $daily, array $keys): array
    {
        $buckets = [];
        foreach ($daily as $row) {
            try {
                $date = new \DateTimeImmutable((string) $row['date']);
            } catch (\Exception) {
                continue;
            }
            // N is 1 for Monday … 7 for Sunday; shift back to that week's Monday.
            $monday = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
            $label = $monday->format('Y-m-d');
            if (!isset($buckets[$label])) {
                $buckets[$label] = array_fill(0, count($keys), 0);
            }
            foreach ($keys as $i => $k) {
                $buckets[$label][$i] += (int) ($row[$k] ?? 0);
            }
        }

        ksort($buckets);

        $out = [];
        foreach ($buckets as $label => $values) {
            $out[] = ['label' => $label, 'values' => $values];
        }

        return $out;
    }

    /**
     * Memories that are still answering searches but have not been touched in
     * a long time. The server hands over `updated_at`; the threshold is a
     * client-side decision so it can be tuned without a deploy.
     *
     * @param list<array<string, mixed>> $topFacts rows with uid, title, count, updated_at
     * @return list<array{label: string, values: list<int>, href: string}>
     */
    public static function staleAnswerers(array $topFacts, string $staleBefore): array
    {
        $out = [];
        foreach ($topFacts as $row) {
            $updated = (string) ($row['updated_at'] ?? '');
            if ($updated === '' || $updated >= $staleBefore) {
                continue;
            }
            $uid = (string) ($row['uid'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $out[] = [
                'label' => $title !== '' ? $title : $uid,
                'values' => [(int) ($row['count'] ?? 0)],
                'href' => '/view.php?uid=' . rawurlencode($uid),
            ];
        }

        return $out;
    }

    /**
     * Tags that callers searched for but the store does not carry on any live
     * memory. The clearest "what's missing" signal already in the summary:
     * both lists are capped at the server's top-N, so a rare tag may be
     * absent from either side — the gap is suggestive, not exhaustive.
     *
     * @param list<array<string, mixed>> $searched rows with tag and count
     * @param list<array<string, mixed>> $held rows with tag
     * @return list<array{label: string, values: list<int>}> ordered by search count, largest first
     */
    public static function coverageGap(array $searched, array $held): array
    {
        $heldTags = [];
        foreach ($held as $row) {
            $tag = (string) ($row['tag'] ?? '');
            if ($tag !== '') {
                $heldTags[$tag] = true;
            }
        }

        $out = [];
        foreach ($searched as $row) {
            $tag = (string) ($row['tag'] ?? '');
            if ($tag === '' || isset($heldTags[$tag])) {
                continue;
            }
            $out[] = ['label' => $tag, 'values' => [(int) ($row['count'] ?? 0)]];
        }

        return $out;
    }

    /**
     * Running count of live memories across the window, back-derived from the
     * current total: start = live − Σcreated + Σretired, then cumulated day
     * by day. The server's daily rows do not include a running total, and
     * every write passes through created/retired, so the arithmetic lines up
     * at the end of the window by construction.
     *
     * @param list<array<string, mixed>> $daily rows with date, created, retired
     * @return list<array{label: string, values: list<int>}>
     */
    public static function growth(array $daily, int $endLive): array
    {
        if ($daily === []) {
            return [];
        }

        $created = 0;
        $retired = 0;
        foreach ($daily as $row) {
            $created += (int) ($row['created'] ?? 0);
            $retired += (int) ($row['retired'] ?? 0);
        }

        $live = $endLive - $created + $retired;
        $out = [];
        foreach ($daily as $row) {
            $live += (int) ($row['created'] ?? 0) - (int) ($row['retired'] ?? 0);
            $out[] = ['label' => (string) $row['date'], 'values' => [$live]];
        }

        return $out;
    }
}

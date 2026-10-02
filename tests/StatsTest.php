<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function testEmptyInputSummarisesToNothing(): void
    {
        $s = Stats::summarise([]);

        self::assertSame(0, $s['count']);
        self::assertNull($s['scoreMin']);
        self::assertNull($s['oldest']);
        self::assertSame([], Stats::labels($s));
    }

    public function testCountsKindsLayersAndScoreRange(): void
    {
        $s = Stats::summarise([
            ['kind' => 'decision', 'layer' => 'facts', 'score' => 0.03, 'tags' => ['a'], 'updated_at' => '2026-09-19T03:40:27+00:00'],
            ['kind' => 'decision', 'layer' => 'facts', 'score' => 0.01, 'tags' => ['a', 'b'], 'updated_at' => '2026-09-20T03:40:27+00:00'],
            ['kind' => 'research', 'layer' => 'facts', 'score' => 0.02, 'tags' => [], 'updated_at' => '2026-09-18T03:40:27+00:00'],
        ]);

        self::assertSame(3, $s['count']);
        self::assertSame(0.01, $s['scoreMin']);
        self::assertSame(0.03, $s['scoreMax']);
        self::assertSame(['decision' => 2, 'research' => 1], $s['kinds']);
        self::assertSame(['facts' => 3], $s['layers']);
        self::assertSame(['a' => 2, 'b' => 1], $s['tags']);
        self::assertSame('2026-09-18T03:40:27+00:00', $s['oldest']);
        self::assertSame('2026-09-20T03:40:27+00:00', $s['newest']);
    }

    public function testTagsAreCutToTheTopFiveWithTiesBrokenByName(): void
    {
        $records = [];
        foreach (['f', 'e', 'd', 'c', 'b', 'a'] as $tag) {
            $records[] = ['tags' => [$tag]];
        }

        $s = Stats::summarise($records);

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_keys($s['tags']));
    }

    /* Older servers send no score or layer, and a record's tags can be
       null; none of that may break the page. */
    public function testRecordsMissingOptionalFieldsAreTolerated(): void
    {
        $s = Stats::summarise([
            ['kind' => 'note', 'tags' => null],
            ['score' => 'high', 'layer' => '', 'tags' => 'oops', 'updated_at' => 'not a date'],
        ]);

        self::assertSame(2, $s['count']);
        self::assertNull($s['scoreMin']);
        self::assertSame([], $s['layers']);
        self::assertSame([], $s['tags']);
        self::assertNull($s['oldest']);
        self::assertSame(['note' => 1], $s['kinds']);
    }

    public function testDatesAreOrderedByInstantNotByString(): void
    {
        $s = Stats::summarise([
            ['updated_at' => '2026-09-19T23:00:00-05:00'],  // = 2026-09-20 04:00 UTC
            ['updated_at' => '2026-09-20T01:00:00+00:00'],
        ]);

        self::assertSame('2026-09-20T01:00:00+00:00', $s['oldest']);
        self::assertSame('2026-09-19T23:00:00-05:00', $s['newest']);
    }

    public function testLabelsShowARangeNeverAPercentage(): void
    {
        $labels = Stats::labels(Stats::summarise([
            ['kind' => 'decision', 'layer' => 'facts', 'score' => 0.0328, 'tags' => ['esky'], 'updated_at' => '2026-09-19T03:40:27+00:00'],
            ['kind' => 'research', 'layer' => 'facts', 'score' => 0.0161, 'tags' => ['esky'], 'updated_at' => '2026-09-20T03:40:27+00:00'],
        ]));

        self::assertContains('score 0.016–0.033', $labels);
        self::assertContains('kind: decision 1 · research 1', $labels);
        self::assertContains('layer: facts 2', $labels);
        self::assertContains('tags: esky 2', $labels);
        foreach ($labels as $label) {
            self::assertStringNotContainsString('%', $label);
        }
    }

    public function testASingleScoreIsNotShownAsARange(): void
    {
        $labels = Stats::labels(Stats::summarise([['score' => 0.0328]]));

        self::assertContains('score 0.033', $labels);
    }
}

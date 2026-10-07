<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Trends;
use PHPUnit\Framework\TestCase;

final class TrendsTest extends TestCase
{
    public function testMissRateIsThePercentageOfSearchesThatMatchedNothing(): void
    {
        $rows = Trends::missRateDaily([
            ['date' => '2026-10-01', 'searches' => 4, 'zero_match' => 1],
            ['date' => '2026-10-02', 'searches' => 10, 'zero_match' => 3],
        ]);

        self::assertSame([25.0, 30.0], [$rows[0]['values'][0], $rows[1]['values'][0]]);
        self::assertSame('2026-10-01', $rows[0]['label']);
    }

    public function testADayWithNoSearchesHasNoRateAndReadsAsZero(): void
    {
        /* A missing denominator is not a 100% miss and is not a 0% hit — the
           day simply has no rate. Zero keeps the line continuous. */
        $rows = Trends::missRateDaily([
            ['date' => '2026-10-01', 'searches' => 0, 'zero_match' => 0],
        ]);

        self::assertSame(0.0, $rows[0]['values'][0]);
    }

    public function testWeeklyBucketsDaysByTheMondayThatStartsTheirIsoWeek(): void
    {
        /* 2026-10-05 is a Monday; 2026-10-04 is the Sunday before it. */
        $rows = Trends::weekly([
            ['date' => '2026-10-04', 'searches' => 2, 'zero_match' => 1],
            ['date' => '2026-10-05', 'searches' => 3, 'zero_match' => 0],
            ['date' => '2026-10-07', 'searches' => 5, 'zero_match' => 2],
        ], ['searches', 'zero_match']);

        self::assertSame('2026-09-28', $rows[0]['label']);
        self::assertSame([2, 1], $rows[0]['values']);
        self::assertSame('2026-10-05', $rows[1]['label']);
        self::assertSame([8, 2], $rows[1]['values']);
    }

    public function testWeeklyReturnsBucketsInChronologicalOrder(): void
    {
        $rows = Trends::weekly([
            ['date' => '2026-10-20', 'searches' => 1],
            ['date' => '2026-10-06', 'searches' => 1],
            ['date' => '2026-10-13', 'searches' => 1],
        ], ['searches']);

        self::assertSame(['2026-10-05', '2026-10-12', '2026-10-19'], array_column($rows, 'label'));
    }

    public function testGrowthEndsAtTheCurrentLiveCount(): void
    {
        $rows = Trends::growth([
            ['date' => '2026-10-01', 'created' => 2, 'retired' => 0],
            ['date' => '2026-10-02', 'created' => 1, 'retired' => 1],
            ['date' => '2026-10-03', 'created' => 3, 'retired' => 0],
        ], endLive: 20);

        self::assertSame(20, $rows[2]['values'][0]);
        self::assertSame(17, $rows[0]['values'][0]);
        self::assertSame(17, $rows[1]['values'][0]);
    }

    public function testGrowthOnAnEmptyWindowReturnsNoRows(): void
    {
        self::assertSame([], Trends::growth([], endLive: 42));
    }

    public function testCoverageGapKeepsTagsSearchedForButNotHeld(): void
    {
        $gap = Trends::coverageGap(
            searched: [
                ['tag' => 'php', 'count' => 9],
                ['tag' => 'docker', 'count' => 4],
                ['tag' => 'kafka', 'count' => 2],
            ],
            held: [['tag' => 'php'], ['tag' => 'bash']],
        );

        self::assertSame(
            [
                ['label' => 'docker', 'values' => [4]],
                ['label' => 'kafka', 'values' => [2]],
            ],
            $gap,
        );
    }

    public function testStaleAnswerersKeepsTopFactsOlderThanTheCutoff(): void
    {
        $stale = Trends::staleAnswerers(
            [
                ['uid' => 'a', 'title' => 'Fresh', 'count' => 3, 'updated_at' => '2026-09-01T00:00:00+00:00'],
                ['uid' => 'b', 'title' => 'Old',   'count' => 2, 'updated_at' => '2025-02-14T00:00:00+00:00'],
                ['uid' => 'c', 'title' => '',      'count' => 1, 'updated_at' => '2024-10-01T00:00:00+00:00'],
            ],
            staleBefore: '2026-04-07',
        );

        self::assertSame(
            [
                ['label' => 'Old', 'values' => [2], 'href' => '/view.php?uid=b'],
                ['label' => 'c',   'values' => [1], 'href' => '/view.php?uid=c'],
            ],
            $stale,
        );
    }

    public function testStaleAnswerersSkipsRowsWithoutATimestamp(): void
    {
        /* A top_fact whose uid no longer resolves on the server comes back
           without updated_at — those cannot be judged stale, so they are left
           out rather than counted as ancient. */
        $stale = Trends::staleAnswerers(
            [['uid' => 'gone', 'count' => 4, 'updated_at' => null]],
            staleBefore: '2026-04-07',
        );

        self::assertSame([], $stale);
    }

    public function testCoverageGapIsEmptyWhenEverySearchedTagIsHeld(): void
    {
        $gap = Trends::coverageGap(
            searched: [['tag' => 'php', 'count' => 3]],
            held: [['tag' => 'php']],
        );

        self::assertSame([], $gap);
    }
}

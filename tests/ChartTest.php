<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Chart;
use PHPUnit\Framework\TestCase;

final class ChartTest extends TestCase
{
    public function testALineChartDrawsOnePolylinePerSeries(): void
    {
        $svg = Chart::lines([
            ['label' => '2026-09-18', 'values' => [2, 1]],
            ['label' => '2026-09-19', 'values' => [4, 0]],
        ], ['searches', 'unanswered']);

        self::assertSame(2, substr_count($svg, '<polyline'));
        self::assertStringContainsString('<svg', $svg);
    }

    public function testALineChartNamesEachSeriesInALegend(): void
    {
        $svg = Chart::lines([['label' => 'a', 'values' => [1, 0]]], ['searches', 'unanswered']);

        self::assertStringContainsString('searches', $svg);
        self::assertStringContainsString('unanswered', $svg);
    }

    public function testAFlatSeriesStillDrawsInsideThePlot(): void
    {
        /* A zero maximum would divide by zero and put the line off-canvas. */
        $svg = Chart::lines([
            ['label' => 'a', 'values' => [0]],
            ['label' => 'b', 'values' => [0]],
        ], ['searches']);

        self::assertMatchesRegularExpression('/points="[\d.,\s]+"/', $svg);
        self::assertStringNotContainsString('NAN', strtoupper($svg));
    }

    public function testBarsDrawOneRectanglePerValue(): void
    {
        $svg = Chart::bars([
            ['label' => 'Mon', 'values' => [3, 1]],
            ['label' => 'Tue', 'values' => [0, 2]],
        ], ['created', 'retired']);

        self::assertSame(4, substr_count($svg, '<rect'));
    }

    public function testBarsCarryAHoverLabelForEveryMark(): void
    {
        $svg = Chart::bars([['label' => 'Mon', 'values' => [3]]], ['created']);

        self::assertStringContainsString('<title>Mon · created: 3</title>', $svg);
    }

    public function testRankedBarsScaleToTheLargestValue(): void
    {
        $svg = Chart::ranked([
            ['label' => 'infra', 'values' => [10]],
            ['label' => 'docker', 'values' => [5]],
        ], ['searches']);

        preg_match_all('/class="bar-fill"[^>]*style="width:([\d.]+)%/', $svg, $m);
        self::assertSame(['100', '50'], $m[1]);
    }

    public function testRankedBarsShowEachValue(): void
    {
        $svg = Chart::ranked([['label' => 'infra', 'values' => [10]]], ['searches']);

        self::assertStringContainsString('>10<', $svg);
    }

    public function testRankedBarsLinkALabelWhenGivenAHref(): void
    {
        $html = Chart::ranked(
            [['label' => 'Deployment', 'values' => [2], 'href' => '/view.php?uid=abc']],
            ['searches']
        );

        self::assertStringContainsString('href="/view.php?uid=abc"', $html);
    }

    public function testRankedBarsSplitAValueIntoItsSegments(): void
    {
        $html = Chart::ranked([
            ['label' => 'how do we deploy', 'values' => [1, 3]],
        ], ['answered', 'unanswered']);

        self::assertSame(2, substr_count($html, 'class="bar-fill"'));
    }

    public function testLabelsAreEscaped(): void
    {
        foreach ([
            Chart::lines([['label' => '<script>', 'values' => [1]]], ['<x>']),
            Chart::bars([['label' => '<script>', 'values' => [1]]], ['<x>']),
            Chart::ranked([['label' => '<script>', 'values' => [1]]], ['<x>']),
        ] as $markup) {
            self::assertStringNotContainsString('<script>', $markup);
            self::assertStringContainsString('&lt;script&gt;', $markup);
        }
    }

    public function testAHrefIsEscaped(): void
    {
        $html = Chart::ranked(
            [['label' => 'x', 'values' => [1], 'href' => '/view.php?uid=a"><script>']],
            ['n']
        );

        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAnEmptyChartSaysSoRatherThanDrawingNothing(): void
    {
        foreach ([Chart::lines([], ['a']), Chart::bars([], ['a']), Chart::ranked([], ['a'])] as $markup) {
            self::assertStringContainsString('Nothing', $markup);
            self::assertStringNotContainsString('<polyline', $markup);
        }
    }

    public function testAStatTileShowsItsValueAndLabel(): void
    {
        $html = Chart::tile('Searches', '142', '30 days');

        self::assertStringContainsString('142', $html);
        self::assertStringContainsString('Searches', $html);
        self::assertStringContainsString('30 days', $html);
    }

    public function testALongTileValueIsSetSmallerSoItFitsTheTile(): void
    {
        /* A timestamp is three times the width of a count; at the headline size
           it overflows the tile. */
        self::assertStringContainsString('tile-value long', Chart::tile('Oldest', '2026-09-06 06:53'));
        self::assertStringNotContainsString('long', Chart::tile('Searches', '142'));
    }

    public function testEveryDateLabelIsNotDrawnOnACrowdedAxis(): void
    {
        /* 90 daily labels cannot fit; thinning is what keeps them readable. */
        $rows = [];
        for ($i = 1; $i <= 90; $i++) {
            $rows[] = ['label' => sprintf('2026-06-%02d', $i % 28 + 1), 'values' => [$i]];
        }

        $svg = Chart::lines($rows, ['searches']);

        self::assertLessThan(15, substr_count($svg, 'class="axis-label"'));
    }

    public function testAxisLabelsAreEvenlySpacedSoTheEndsDoNotCollide(): void
    {
        /* Picking every nth row leaves a stub gap before the final label, which
           is what made the last two dates overlap. */
        $rows = [];
        for ($i = 0; $i < 90; $i++) {
            $rows[] = ['label' => sprintf('2026-06-%02d', $i % 28 + 1), 'values' => [1]];
        }

        preg_match_all('/class="axis-label" x="([\d.]+)"/', Chart::lines($rows, ['searches']), $m);
        $gaps = [];
        for ($i = 1, $n = count($m[1]); $i < $n; $i++) {
            $gaps[] = (float) $m[1][$i] - (float) $m[1][$i - 1];
        }

        self::assertGreaterThan(1, count($gaps));
        self::assertGreaterThan(0.75 * max($gaps), min($gaps));
    }

    public function testTheAxisIsAnchoredAtBothEndsOfTheData(): void
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['label' => sprintf('2026-06-%02d', $i), 'values' => [1]];
        }

        $svg = Chart::lines($rows, ['searches']);

        self::assertStringContainsString('>06-01</text>', $svg);
        self::assertStringContainsString('>06-30</text>', $svg);
    }

    public function testChartsScaleUniformlySoLabelsAreNotStretched(): void
    {
        self::assertStringNotContainsString(
            'preserveAspectRatio="none"',
            Chart::lines([['label' => 'a', 'values' => [1]]], ['searches'])
        );
    }
}

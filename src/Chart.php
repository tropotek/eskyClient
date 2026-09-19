<?php
declare(strict_types=1);

namespace Esky;

/**
 * Inline SVG charts, drawn server-side.
 *
 * No JavaScript and no CDN: this dashboard is read on a LAN that need not have
 * a route to the internet, and a chart that silently fails to load is worse
 * than a plain table. Hover labels are native SVG <title> elements for the same
 * reason — the browser draws the tooltip, nothing has to run.
 *
 * Series colours come from --series-1… in the stylesheet, assigned in fixed
 * order so a series keeps its colour when another chart shows fewer of them.
 *
 * @phpstan-type Row array{label: string, values: list<int|float>, href?: string}
 */
final class Chart
{
    private const WIDTH = 720;
    private const HEIGHT = 200;
    private const PAD_LEFT = 38;
    private const PAD_RIGHT = 10;
    private const PAD_TOP = 12;
    private const PAD_BOTTOM = 24;
    private const MAX_AXIS_LABELS = 8;

    /**
     * A time series: one line per series, shown as a share of the same axis.
     *
     * @param list<Row> $rows in chronological order
     * @param list<string> $names
     */
    public static function lines(array $rows, array $names): string
    {
        if ($rows === []) {
            return self::empty();
        }

        $max = self::ceiling($rows);
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $count = count($rows);
        $step = $count > 1 ? $plotW / ($count - 1) : 0.0;

        $svg = self::grid($max);

        foreach ($names as $index => $name) {
            $points = [];
            $markers = '';
            foreach ($rows as $i => $row) {
                $value = (float) ($row['values'][$index] ?? 0);
                $x = self::PAD_LEFT + ($count > 1 ? $i * $step : $plotW / 2);
                $y = self::PAD_TOP + $plotH - ($value / $max) * $plotH;
                $points[] = self::n($x) . ',' . self::n($y);
                // Beyond a few dozen points the dots merge into the line and
                // only cost markup, so the hover targets go with them.
                if ($count <= 40) {
                    $markers .= sprintf(
                        '<circle class="dot" cx="%s" cy="%s" r="3" fill="var(--series-%d)">%s</circle>',
                        self::n($x),
                        self::n($y),
                        $index + 1,
                        self::hover($row['label'], $name, $value)
                    );
                }
            }
            $svg .= sprintf(
                '<polyline fill="none" stroke="var(--series-%d)" stroke-width="2" '
                . 'stroke-linejoin="round" stroke-linecap="round" points="%s"/>%s',
                $index + 1,
                implode(' ', $points),
                $markers
            );
        }

        $svg .= self::axis($rows);

        return self::legend($names) . self::wrap($svg);
    }

    /**
     * Counts side by side: one group of bars per row.
     *
     * @param list<Row> $rows
     * @param list<string> $names
     */
    public static function bars(array $rows, array $names): string
    {
        if ($rows === []) {
            return self::empty();
        }

        $max = self::ceiling($rows);
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $baseline = self::PAD_TOP + $plotH;
        $slot = $plotW / max(1, count($rows));
        $series = max(1, count($names));
        // The 2px gap is what keeps two adjacent fills from reading as one mark.
        $barW = max(1.5, ($slot - 6) / $series - 2);

        $svg = self::grid($max);

        foreach ($rows as $i => $row) {
            foreach ($names as $index => $name) {
                $value = (float) ($row['values'][$index] ?? 0);
                $height = ($value / $max) * $plotH;
                $x = self::PAD_LEFT + $i * $slot + 3 + $index * ($barW + 2);
                $svg .= sprintf(
                    '<rect class="bar" x="%s" y="%s" width="%s" height="%s" rx="2" '
                    . 'fill="var(--series-%d)">%s</rect>',
                    self::n($x),
                    self::n($baseline - $height),
                    self::n($barW),
                    self::n($height),
                    $index + 1,
                    self::hover($row['label'], $name, $value)
                );
            }
        }

        $svg .= self::axis($rows);

        return self::legend($names) . self::wrap($svg);
    }

    /**
     * A ranked list as horizontal bars — HTML, not SVG, so the labels wrap,
     * stay selectable and can be links.
     *
     * A row's values are segments of one bar, all scaled against the largest
     * row total, so the rows compare with each other and within themselves.
     *
     * @param list<Row> $rows already ordered, largest first
     * @param list<string> $names
     */
    public static function ranked(array $rows, array $names): string
    {
        if ($rows === []) {
            return self::empty();
        }

        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, array_sum($row['values']));
        }
        $max = $max > 0 ? $max : 1.0;

        $html = count($names) > 1 ? self::legend($names) : '';
        $html .= '<ul class="ranked">';
        foreach ($rows as $row) {
            $total = array_sum($row['values']);
            $label = Page::e($row['label']);
            if (isset($row['href'])) {
                $label = '<a href="' . Page::e($row['href']) . '">' . $label . '</a>';
            }

            $fills = '';
            foreach ($row['values'] as $index => $value) {
                $fills .= sprintf(
                    '<span class="bar-fill" title="%s: %s" style="width:%s%%;'
                    . 'background:var(--series-%d)"></span>',
                    Page::e($names[$index] ?? ''),
                    self::n((float) $value),
                    self::n(((float) $value / $max) * 100),
                    $index + 1
                );
            }

            $html .= sprintf(
                '<li><span class="ranked-label">%s</span>'
                . '<span class="bar">%s</span>'
                . '<span class="ranked-value">%s</span></li>',
                $label,
                $fills,
                self::n((float) $total)
            );
        }

        return $html . '</ul>';
    }

    /** A single headline figure — no plot, because one number is not a chart. */
    public static function tile(string $label, string $value, string $note = ''): string
    {
        // A timestamp is several times the width of a count and overflows the
        // tile at the headline size, so anything that long is set smaller.
        $long = mb_strlen($value) > 8 ? ' long' : '';

        return sprintf(
            '<div class="tile"><span class="tile-label">%s</span>'
            . '<span class="tile-value%s">%s</span><span class="tile-note">%s</span></div>',
            Page::e($label),
            $long,
            Page::e($value),
            Page::e($note)
        );
    }

    /** @param list<Row> $rows */
    private static function ceiling(array $rows): float
    {
        $max = 0.0;
        foreach ($rows as $row) {
            foreach ($row['values'] as $value) {
                $max = max($max, (float) $value);
            }
        }

        // A flat zero series would otherwise divide by zero and leave the plot.
        return $max > 0 ? $max : 1.0;
    }

    private static function grid(float $max): string
    {
        $plotH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $svg = '';
        foreach ([0.0, 0.5, 1.0] as $fraction) {
            $y = self::PAD_TOP + $plotH * (1 - $fraction);
            $svg .= sprintf(
                '<line class="grid" x1="%d" y1="%s" x2="%d" y2="%s"/>'
                . '<text class="axis-value" x="%d" y="%s">%s</text>',
                self::PAD_LEFT,
                self::n($y),
                self::WIDTH - self::PAD_RIGHT,
                self::n($y),
                self::PAD_LEFT - 6,
                self::n($y + 4),
                self::n($max * $fraction)
            );
        }

        return $svg;
    }

    /**
     * Thinned x labels, spread evenly from the first row to the last.
     *
     * Even spacing rather than every nth row: the axis has to be anchored at
     * both ends, and every nth row leaves a short gap before the final label,
     * which puts the last two dates on top of each other.
     *
     * @param list<Row> $rows
     */
    private static function axis(array $rows): string
    {
        $count = count($rows);
        $plotW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $wanted = min($count, self::MAX_AXIS_LABELS);
        $y = self::HEIGHT - 6;
        $svg = '';

        $indices = $wanted < 2
            ? [0]
            : array_map(
                static fn (int $j): int => (int) round($j * ($count - 1) / ($wanted - 1)),
                range(0, $wanted - 1)
            );

        foreach (array_unique($indices) as $i) {
            $row = $rows[$i];
            $x = self::PAD_LEFT + ($count > 1 ? $i * ($plotW / ($count - 1)) : $plotW / 2);
            $anchor = $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle');
            $svg .= sprintf(
                '<text class="axis-label" x="%s" y="%d" text-anchor="%s">%s</text>',
                self::n(min(max($x, 0), self::WIDTH)),
                $y,
                $anchor,
                Page::e(self::shortLabel($row['label']))
            );
        }

        return $svg;
    }

    /** Dates carry their year in the data; the axis only needs month and day. */
    private static function shortLabel(string $label): string
    {
        return preg_match('/^\d{4}-(\d{2}-\d{2})$/', $label, $m) === 1 ? $m[1] : $label;
    }

    /** @param list<string> $names */
    private static function legend(array $names): string
    {
        $html = '<p class="legend">';
        foreach ($names as $index => $name) {
            $html .= sprintf(
                '<span class="legend-item"><span class="swatch" style="background:var(--series-%d)">'
                . '</span>%s</span>',
                $index + 1,
                Page::e($name)
            );
        }

        return $html . '</p>';
    }

    private static function hover(string $label, string $name, float $value): string
    {
        return '<title>' . Page::e($label) . ' · ' . Page::e($name) . ': '
            . self::n($value) . '</title>';
    }

    private static function wrap(string $body): string
    {
        return sprintf(
            // Scaled uniformly, not stretched to the panel: stretching widens
            // the glyphs without heightening them, and the labels collide.
            '<svg class="chart" viewBox="0 0 %d %d" role="img">%s</svg>',
            self::WIDTH,
            self::HEIGHT,
            $body
        );
    }

    private static function empty(): string
    {
        return '<p class="empty">Nothing recorded in this window.</p>';
    }

    /** Trims the float noise that would otherwise fill the markup. */
    private static function n(float $value): string
    {
        $rendered = number_format($value, 2, '.', '');

        return rtrim(rtrim($rendered, '0'), '.') ?: '0';
    }
}

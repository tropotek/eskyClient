<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Api;
use Esky\Chart;
use Esky\Config;
use Esky\EskyException;
use Esky\Page;

/** The windows the server's aggregates are cheap at, and a human reads. */
const WINDOWS = [7, 30, 90];

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, WINDOWS, true)) {
    $days = 30;
}

try {
    $config = Config::fromEnvironment(dirname(__DIR__));
    $api = new Api($config);
    $summary = $api->querySummary($days);
    $stats = $api->stats($days);
} catch (EskyException $e) {
    Page::error($e->getMessage());
}

$totals = $summary['totals'];
$searches = (int) $totals['searches'];
$unanswered = (int) $totals['zero_match'];
$missRate = $searches > 0 ? round($unanswered / $searches * 100) . '%' : '—';

$daily = static fn (array $rows, array $keys): array => array_map(
    static fn (array $row): array => [
        'label' => (string) $row['date'],
        'values' => array_map(static fn (string $k): int => (int) $row[$k], $keys),
    ],
    $rows
);

$ranked = static fn (array $rows, string $label, string $value): array => array_map(
    static fn (array $row): array => [
        'label' => (string) $row[$label],
        'values' => [(int) $row[$value]],
    ],
    $rows
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>esky metrics</title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="wrap">
    <nav class="nav">
        <a href="/index.php">Memories</a>
        <span class="here">Metrics</span>
        <span class="nav-profile"><?= Page::e((string) $config->profile) ?></span>
    </nav>

    <h1>esky metrics</h1>

    <p class="windows">
        <?php foreach (WINDOWS as $window): ?>
            <?php if ($window === $days): ?>
                <span class="window current"><?= $window ?> days</span>
            <?php else: ?>
                <a class="window" href="/metrics.php?days=<?= $window ?>"><?= $window ?> days</a>
            <?php endif; ?>
        <?php endforeach; ?>
        <span class="meta"><?= Page::e((string) $summary['from']) ?>
            → <?= Page::e((string) $summary['to']) ?></span>
    </p>

    <div class="tiles">
        <?= Chart::tile('Memories held', (string) $stats['facts'], (string) $stats['retired'] . ' retired') ?>
        <?= Chart::tile('Searches', (string) $searches, $days . ' days') ?>
        <?= Chart::tile('Unanswered', (string) $unanswered, $missRate . ' of searches') ?>
        <?= Chart::tile('Distinct questions', (string) $totals['distinct_queries'], 'asked in the window') ?>
        <?= Chart::tile('Oldest memory', Page::stamp($stats['oldest']) ?: '—', 'newest ' . (Page::stamp($stats['newest']) ?: '—')) ?>
    </div>

    <?php if ((int) $totals['unknown_matched'] > 0): ?>
        <p class="meta note">
            <?= (int) $totals['unknown_matched'] ?> searches were logged before the
            store recorded how much they matched; they are counted as searches but
            appear as <em>unknown</em> below rather than as hits or misses.
        </p>
    <?php endif; ?>

    <section class="panel">
        <h2>Searches per day</h2>
        <p class="meta">Whether memory is being used at all, and how often it came back empty.</p>
        <?= Chart::lines($daily($summary['daily'], ['searches', 'zero_match']), ['searches', 'unanswered']) ?>
    </section>

    <section class="panel">
        <h2>How much each search found</h2>
        <p class="meta">Matches before the caller's limit truncated them. A store leaning on <em>0</em> is being asked things it does not hold; one leaning on <em>11+</em> is answering vaguely.</p>
        <?= Chart::bars(
            array_map(
                static fn (array $b): array => ['label' => (string) $b['label'], 'values' => [(int) $b['count']]],
                $summary['match_buckets']
            ),
            ['searches']
        ) ?>
    </section>

    <section class="panel">
        <h2>What was asked most</h2>
        <p class="meta">A frequent question that stays unanswered is the clearest signal of what belongs in the store.</p>
        <?= Chart::ranked(
            array_map(
                static fn (array $q): array => [
                    'label' => (string) $q['query'],
                    'values' => [(int) $q['count'] - (int) $q['zero_match'], (int) $q['zero_match']],
                ],
                $summary['top_queries']
            ),
            ['answered', 'unanswered']
        ) ?>
    </section>

    <div class="pair">
        <section class="panel">
            <h2>Memories that answered</h2>
            <p class="meta">Top hit of a search, counted. A store where one memory answers everything is not being searched well.</p>
            <?= Chart::ranked(
                array_map(
                    static fn (array $f): array => [
                        'label' => (string) ($f['title'] ?? '') !== '' ? (string) $f['title'] : (string) $f['uid'],
                        'values' => [(int) $f['count']],
                        'href' => '/view.php?uid=' . rawurlencode((string) $f['uid']),
                    ],
                    $summary['top_facts']
                ),
                ['searches']
            ) ?>
        </section>

        <section class="panel">
            <h2>Tags searched with</h2>
            <p class="meta">Filters callers actually used, against the tags below that the store actually carries.</p>
            <?= Chart::ranked($ranked($summary['top_tags'], 'tag', 'count'), ['searches']) ?>
        </section>
    </div>

    <section class="panel">
        <h2>Memories added and retired</h2>
        <p class="meta">Growth against pruning. Nothing being retired over a long window usually means nothing is being reviewed.</p>
        <?= Chart::bars($daily($stats['daily'], ['created', 'retired']), ['added', 'retired']) ?>
    </section>

    <div class="pair">
        <section class="panel">
            <h2>Kinds held</h2>
            <p class="meta">The mix of live memories.</p>
            <?= Chart::ranked($ranked($stats['kinds'], 'kind', 'count'), ['memories']) ?>
        </section>

        <section class="panel">
            <h2>Tags held</h2>
            <p class="meta">Across live memories.</p>
            <?= Chart::ranked($ranked($stats['top_tags'], 'tag', 'count'), ['memories']) ?>
        </section>
    </div>
</main>
</body>
</html>

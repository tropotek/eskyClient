<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Api;
use Esky\Chart;
use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;

/** The windows the server's aggregates are cheap at, and a human reads. */
const WINDOWS = [7, 30, 90];

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, WINDOWS, true)) {
    $days = 30;
}

try {
    $config = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $api = new Api($config);
    $summary = $api->querySummary($days);
    $stats = $api->stats($days);
} catch (EskyException $e) {
    Layout::error($e->getMessage());
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
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky Metrics') ?>
<body>
<?= Layout::navbar('/metrics.php') ?>
<main class="container pb-5">
    <h1>Metrics</h1>

    <p class="d-flex align-items-baseline gap-2 mb-4">
        <?php foreach (WINDOWS as $window): ?>
            <?php if ($window === $days): ?>
                <span class="badge rounded-pill border window current"><?= $window ?> days</span>
            <?php else: ?>
                <a class="badge rounded-pill border window" href="/metrics.php?days=<?= $window ?>"><?= $window ?> days</a>
            <?php endif; ?>
        <?php endforeach; ?>
        <span class="ms-auto text-body-secondary small"><?= Page::e((string) $summary['from']) ?>
            → <?= Page::e((string) $summary['to']) ?></span>
    </p>

    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3">
        <?= Chart::tile('Memories held', (string) $stats['facts'], (string) $stats['retired'] . ' retired') ?>
        <?= Chart::tile('Searches', (string) $searches, $days . ' days') ?>
        <?= Chart::tile('Unanswered', (string) $unanswered, $missRate . ' of searches') ?>
        <?= Chart::tile('Distinct questions', (string) $totals['distinct_queries'], 'asked in the window') ?>
        <?= Chart::tile('Oldest memory', Page::stamp($stats['oldest']) ?: '—', 'newest ' . (Page::stamp($stats['newest']) ?: '—')) ?>
    </div>

    <?php if ((int) $totals['unknown_matched'] > 0): ?>
        <p class="text-body-secondary small mt-3">
            <?= (int) $totals['unknown_matched'] ?> searches were logged before the
            store recorded how much they matched; they are counted as searches but
            appear as <em>unknown</em> below rather than as hits or misses.
        </p>
    <?php endif; ?>

    <section class="card mt-3"><div class="card-body">
        <h2 class="h6 mb-1">Searches per day</h2>
        <p class="text-body-secondary small mb-3">Whether memory is being used at all, and how often it came back empty.</p>
        <?= Chart::lines($daily($summary['daily'], ['searches', 'zero_match']), ['searches', 'unanswered']) ?>
    </div></section>

    <section class="card mt-3"><div class="card-body">
        <h2 class="h6 mb-1">How much each search found</h2>
        <p class="text-body-secondary small mb-3">Matches before the caller's limit truncated them. A store leaning on <em>0</em> is being asked things it does not hold; one leaning on <em>11+</em> is answering vaguely.</p>
        <?= Chart::bars(
            array_map(
                static fn (array $b): array => ['label' => (string) $b['label'], 'values' => [(int) $b['count']]],
                $summary['match_buckets']
            ),
            ['searches']
        ) ?>
    </div></section>

    <section class="card mt-3"><div class="card-body">
        <h2 class="h6 mb-1">What was asked most</h2>
        <p class="text-body-secondary small mb-3">A frequent question that stays unanswered is the clearest signal of what belongs in the store.</p>
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
    </div></section>

    <div class="row g-3 align-items-start">
        <div class="col-12 col-lg-6"><section class="card mt-3"><div class="card-body">
            <h2 class="h6 mb-1">Memories that answered</h2>
            <p class="text-body-secondary small mb-3">Top hit of a search, counted. A store where one memory answers everything is not being searched well.</p>
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
        </div></section></div>

        <div class="col-12 col-lg-6"><section class="card mt-3"><div class="card-body">
            <h2 class="h6 mb-1">Tags searched with</h2>
            <p class="text-body-secondary small mb-3">Filters callers actually used, against the tags below that the store actually carries.</p>
            <?= Chart::ranked($ranked($summary['top_tags'], 'tag', 'count'), ['searches']) ?>
        </div></section></div>
    </div>

    <section class="card mt-3"><div class="card-body">
        <h2 class="h6 mb-1">Memories added and retired</h2>
        <p class="text-body-secondary small mb-3">Growth against pruning. Nothing being retired over a long window usually means nothing is being reviewed.</p>
        <?= Chart::bars($daily($stats['daily'], ['created', 'retired']), ['added', 'retired']) ?>
    </div></section>

    <div class="row g-3 align-items-start">
        <div class="col-12 col-lg-6"><section class="card mt-3"><div class="card-body">
            <h2 class="h6 mb-1">Kinds held</h2>
            <p class="text-body-secondary small mb-3">The mix of live memories.</p>
            <?= Chart::ranked($ranked($stats['kinds'], 'kind', 'count'), ['memories']) ?>
        </div></section></div>

        <div class="col-12 col-lg-6"><section class="card mt-3"><div class="card-body">
            <h2 class="h6 mb-1">Tags held</h2>
            <p class="text-body-secondary small mb-3">Across live memories.</p>
            <?= Chart::ranked($ranked($stats['top_tags'], 'tag', 'count'), ['memories']) ?>
        </div></section></div>
    </div>
</main>
</body>
</html>

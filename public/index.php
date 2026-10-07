<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Api;
use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Stats;
use Esky\Vaults;

/** Sizes the limit dropdown offers. The server caps at 200. */
const PAGE_SIZES = [20, 50, 100, 200];

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$limit = (int) ($_GET['limit'] ?? 50);
if (!in_array($limit, PAGE_SIZES, true)) {
    $limit = 50;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

try {
    $config = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $response = (new Api($config))->facts($limit, $offset, $query);
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}

$records = $response['facts'];
$total = (int) $response['total'];
$lastPage = max(1, (int) ceil($total / $limit));
// Round-trip past the last page lands the viewer on page 1 — a stale bookmark
// should still show something, not an empty frame.
if ($records === [] && $page > 1 && $total > 0) {
    header('Location: /index.php?' . http_build_query(array_filter([
        'q' => $query,
        'limit' => $limit !== 50 ? $limit : null,
        'page' => $lastPage,
    ])));
    exit;
}

$firstOnPage = $total === 0 ? 0 : $offset + 1;
$lastOnPage = $offset + count($records);

$link = static fn (array $params): string => '/index.php?' . http_build_query(
    array_filter(array_merge(
        ['q' => $query, 'limit' => $limit, 'page' => $page],
        $params,
    ), static fn ($v): bool => $v !== null && $v !== '')
);
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky Memories') ?>
<body>
<?= Layout::navbar('/index.php') ?>
<main class="container pb-5">
    <h1>Memories</h1>

    <?php if (isset($_GET['forgotten'])): ?>
        <div class="alert alert-success" role="alert">Memory forgotten. It no longer appears in searches.</div>
    <?php endif; ?>

    <p class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
        <span class="text-body-secondary small">
            <?php if ($total === 0): ?>
                No <?= $query === '' ? 'memories yet' : 'memories match ' . Page::e($query) ?>
            <?php else: ?>
                <?= $firstOnPage ?>–<?= $lastOnPage ?> of <?= $total ?>
                <?= $total === 1 ? 'memory' : 'memories' ?>
                <?= $query === '' ? 'most recently updated' : 'matching ' . Page::e($query) ?>
            <?php endif; ?>
            <?php if ($query !== ''): ?>
                — <a href="/index.php">clear the search</a>
            <?php endif; ?>
        </span>
        <span class="ms-auto d-flex align-items-baseline gap-2">
            <span class="text-body-secondary small">per page</span>
            <?php foreach (PAGE_SIZES as $size): ?>
                <?php if ($size === $limit): ?>
                    <span class="badge rounded-pill border window current"><?= $size ?></span>
                <?php else: ?>
                    <a class="badge rounded-pill border window"
                       href="<?= Page::e($link(['limit' => $size, 'page' => 1])) ?>"><?= $size ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </span>
    </p>

    <?php $labels = ($query !== '' && $records !== []) ? Stats::labels(Stats::summarise($records)) : []; ?>
    <?php if ($labels !== []): ?>
        <p class="d-flex flex-wrap gap-1 mb-0">
        <?php foreach ($labels as $label): ?>
            <span class="badge rounded-pill tag"><?= Page::e($label) ?></span>
        <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <?php if ($records === []): ?>
        <p class="text-body-secondary small">Nothing to show.</p>
    <?php endif; ?>

    <ul class="list-unstyled d-grid gap-3 mt-3 mb-0">
    <?php foreach ($records as $record): ?>
        <?php
        $href = '/view.php?uid=' . rawurlencode((string) $record['uid']);
        if ($query !== '') {
            $href .= '&q=' . rawurlencode($query);
        }
        ?>
        <li class="card">
            <div class="card-body">
                <a class="card-link-row d-flex justify-content-between align-items-baseline gap-3" href="<?= Page::e($href) ?>">
                    <span class="title"><?= Page::e(Page::heading($record)) ?></span>
                    <?php $score = $query === '' ? '' : Page::score($record['score'] ?? null); ?>
                    <span class="meta text-nowrap text-body-secondary">
                        <span title="Kind of memory"><?= Page::e((string) ($record['kind'] ?? '')) ?></span>
                        <?php if ($score !== ''): ?>
                            <span class="sep" aria-hidden="true">|</span>
                            <span title="Rank score: compare within this search only"><?= Page::e($score) ?></span>
                        <?php endif; ?>
                    </span>
                </a>
                <p class="mt-2 mb-0"><?= Page::e(Page::preview((string) ($record['text'] ?? ''))) ?></p>
                <div class="d-flex justify-content-between align-items-end gap-3 mt-2">
                    <p class="d-flex flex-wrap gap-1 mb-0">
                    <?php foreach ((array) ($record['tags'] ?? []) as $tag): ?>
                        <span class="badge rounded-pill tag"><?= Page::e((string) $tag) ?></span>
                    <?php endforeach; ?>
                    </p>
                    <time class="small text-nowrap text-body-secondary ms-auto" title="Last updated"><?= Page::e(Page::stamp((string) ($record['updated_at'] ?? ''))) ?></time>
                </div>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>

    <?php if ($lastPage > 1): ?>
        <nav class="mt-4" aria-label="Memory pages">
            <ul class="pagination justify-content-center mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= Page::e($link(['page' => $page - 1])) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link">Page <?= $page ?> of <?= $lastPage ?></span>
                </li>
                <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= Page::e($link(['page' => $page + 1])) ?>" <?= $page >= $lastPage ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</main>
<?= Layout::footer() ?>
</body>
</html>

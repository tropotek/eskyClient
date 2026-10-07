<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Client;
use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Stats;
use Esky\Vaults;

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $vault = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $client = new Client($vault);
    $records = $query === '' ? $client->recent(50) : $client->search($query, 50);
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}
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

    <p class="text-body-secondary small">
        <?= count($records) ?> <?= count($records) === 1 ? 'memory' : 'memories' ?>
        <?= $query === '' ? 'most recently updated' : 'matching ' . Page::e($query) ?>
        <?php if ($query !== ''): ?>
            — <a href="/index.php">clear the search</a>
        <?php endif; ?>
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
</main>
<?= Layout::footer() ?>
</body>
</html>

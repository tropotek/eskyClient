<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Client;
use Esky\Config;
use Esky\EskyException;
use Esky\Layout;
use Esky\Page;

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $client = new Client(Config::fromEnvironment(dirname(__DIR__)));
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

    <form class="d-flex gap-2 align-items-center mb-3" method="get" action="/index.php">
        <input class="form-control" type="search" name="q" value="<?= Page::e($query) ?>" placeholder="Search memories…" aria-label="Search memories">
        <button class="btn btn-outline-secondary" type="submit">Search</button>
        <?php if ($query !== ''): ?>
            <a class="small" href="/index.php">Clear</a>
        <?php endif; ?>
    </form>

    <p class="text-body-secondary small">
        <?= count($records) ?> <?= count($records) === 1 ? 'memory' : 'memories' ?>
        <?= $query === '' ? 'most recently updated' : 'matching ' . Page::e($query) ?>
    </p>

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
                    <span class="d-flex align-items-baseline gap-2 text-nowrap text-body-secondary small">
                        <span><?= Page::e((string) ($record['kind'] ?? '')) ?></span>
                        <time><?= Page::e(Page::stamp((string) ($record['updated_at'] ?? ''))) ?></time>
                    </span>
                </a>
                <p class="mt-2 mb-0"><?= Page::e(Page::preview((string) ($record['text'] ?? ''))) ?></p>
                <p class="d-flex flex-wrap gap-1 mt-2 mb-0">
                <?php foreach ((array) ($record['tags'] ?? []) as $tag): ?>
                    <span class="badge rounded-pill tag"><?= Page::e((string) $tag) ?></span>
                <?php endforeach; ?>
                </p>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
</main>
</body>
</html>

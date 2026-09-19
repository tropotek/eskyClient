<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Client;
use Esky\Config;
use Esky\EskyException;
use Esky\Page;

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

try {
    $client = new Client(Config::fromEnvironment(dirname(__DIR__)));
    $records = $query === '' ? $client->recent(50) : $client->search($query, 50);
} catch (EskyException $e) {
    Page::error($e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Esky Memories</title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="wrap">
    <nav class="nav">
        <span class="here">Memories</span>
        <a href="/metrics.php">Metrics</a>
    </nav>

    <h1>🧊 Esky Memories</h1>

    <form class="search" method="get" action="/index.php">
        <input type="search" name="q" value="<?= Page::e($query) ?>" placeholder="Search memories…" aria-label="Search memories">
        <button type="submit">Search</button>
        <?php if ($query !== ''): ?>
            <a class="clear" href="/index.php">Clear</a>
        <?php endif; ?>
    </form>

    <p class="meta">
        <?= count($records) ?> <?= count($records) === 1 ? 'memory' : 'memories' ?>
        <?= $query === '' ? 'most recently updated' : 'matching ' . Page::e($query) ?>
    </p>

    <?php if ($records === []): ?>
        <p class="empty">Nothing to show.</p>
    <?php endif; ?>

    <ul class="list">
    <?php foreach ($records as $record): ?>
        <?php
        $href = '/view.php?uid=' . rawurlencode((string) $record['uid']);
        if ($query !== '') {
            $href .= '&q=' . rawurlencode($query);
        }
        ?>
        <li class="card">
            <a class="card-link" href="<?= Page::e($href) ?>">
                <span class="title"><?= Page::e(Page::heading($record)) ?></span>
                <span class="card-meta">
                    <span class="kind"><?= Page::e((string) ($record['kind'] ?? '')) ?></span>
                    <time><?= Page::e(Page::stamp((string) ($record['updated_at'] ?? ''))) ?></time>
                </span>
            </a>
            <p class="excerpt"><?= Page::e(Page::preview((string) ($record['text'] ?? ''))) ?></p>
            <p class="tags">
            <?php foreach ((array) ($record['tags'] ?? []) as $tag): ?>
                <span class="tag"><?= Page::e((string) $tag) ?></span>
            <?php endforeach; ?>
            </p>
        </li>
    <?php endforeach; ?>
    </ul>
</main>
</body>
</html>

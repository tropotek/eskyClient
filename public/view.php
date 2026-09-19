<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Client;
use Esky\Config;
use Esky\EskyException;
use Esky\Markdown;
use Esky\Page;

$uid = isset($_GET['uid']) ? trim((string) $_GET['uid']) : '';
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if ($uid === '') {
    Page::error('No memory id was given.');
}

/**
 * esky exposes no get-by-uid tool, and searching by uid returns nothing, so the
 * record is located by filtering a list. The store is small; see the design doc.
 */
$find = static function (array $records, string $uid): ?array {
    foreach ($records as $record) {
        if (($record['uid'] ?? null) === $uid) {
            return $record;
        }
    }

    return null;
};

try {
    $client = new Client(Config::fromEnvironment(dirname(__DIR__)));

    $memory = null;
    if ($query !== '') {
        $memory = $find($client->search($query, 500), $uid);
    }
    if ($memory === null) {
        $memory = $find($client->recent(500), $uid);
    }
} catch (EskyException $e) {
    Page::error($e->getMessage());
}

if ($memory === null) {
    http_response_code(404);
}

$backHref = $query === '' ? '/index.php' : '/index.php?q=' . rawurlencode($query);
$text = (string) ($memory['text'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $memory === null ? 'Not found' : 'esky — ' . Page::e((string) $memory['uid']) ?></title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="wrap">
    <p><a href="<?= Page::e($backHref) ?>">&larr; Back to the list</a></p>

<?php if ($memory === null): ?>
    <h1>Memory not found</h1>
    <p class="empty">No memory with the id <code><?= Page::e($uid) ?></code> was found.</p>
<?php else: ?>
    <h1><?= Page::e((string) ($memory['kind'] ?? 'memory')) ?></h1>

    <div class="panes">
        <section class="pane">
            <h2>Markdown</h2>
            <div class="body"><pre><?= Page::e($text) ?></pre></div>
        </section>
        <section class="pane">
            <h2>HTML</h2>
            <div class="body rendered"><?= Markdown::toHtml($text) ?></div>
        </section>
    </div>

    <table class="fields">
        <tr><th>uid</th><td><?= Page::e((string) $memory['uid']) ?></td></tr>
        <tr><th>kind</th><td><?= Page::e((string) ($memory['kind'] ?? '')) ?></td></tr>
        <tr><th>tags</th><td><?= Page::e(implode(', ', array_map('strval', (array) ($memory['tags'] ?? [])))) ?></td></tr>
        <tr><th>source</th><td><?= Page::e((string) ($memory['source'] ?? '')) ?></td></tr>
        <tr><th>confidence</th><td><?= Page::e((string) ($memory['confidence'] ?? '')) ?></td></tr>
        <tr><th>created</th><td><?= Page::e((string) ($memory['created_at'] ?? '')) ?></td></tr>
        <tr><th>updated</th><td><?= Page::e((string) ($memory['updated_at'] ?? '')) ?></td></tr>
        <?php if (!empty($memory['supersedes'])): ?>
            <tr><th>supersedes</th><td><?= Page::e((string) $memory['supersedes']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($memory['retired_at'])): ?>
            <tr><th>retired</th><td><?= Page::e((string) $memory['retired_at']) ?></td></tr>
        <?php endif; ?>
    </table>
<?php endif; ?>
</main>
</body>
</html>

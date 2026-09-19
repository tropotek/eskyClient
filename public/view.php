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
<title><?= $memory === null ? 'Not found' : 'esky — ' . Page::e(Page::heading($memory)) ?></title>
<link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="wrap">
    <p><a href="<?= Page::e($backHref) ?>">&larr; Back to the list</a></p>

<?php if ($memory === null): ?>
    <h1>Memory not found</h1>
    <p class="empty">No memory with the id <code><?= Page::e($uid) ?></code> was found.</p>
<?php else: ?>
    <h1><?= Page::e(Page::heading($memory)) ?></h1>
    <p class="meta"><?= Page::e((string) ($memory['kind'] ?? '')) ?> &middot; updated <?= Page::e(Page::stamp((string) ($memory['updated_at'] ?? ''))) ?></p>

    <div class="detail">
    <section class="viewer">
        <input class="tab-state" type="radio" name="pane" id="pane-markdown" checked>
        <input class="tab-state" type="radio" name="pane" id="pane-html">
        <nav class="tabs">
            <label for="pane-markdown">Markdown</label>
            <label for="pane-html">HTML</label>
        </nav>
        <div class="body pane-markdown"><pre><?= Page::e($text) ?></pre></div>
        <div class="body pane-html rendered"><?= Markdown::toHtml($text) ?></div>
    </section>

    <aside class="fields">
        <h2>Details</h2>
        <dl>
            <dt>uid</dt><dd><?= Page::e((string) $memory['uid']) ?></dd>
            <dt>title</dt><dd><?= Page::e((string) ($memory['title'] ?? '')) ?></dd>
            <dt>kind</dt><dd><?= Page::e((string) ($memory['kind'] ?? '')) ?></dd>
            <dt>tags</dt><dd><?= Page::e(implode(', ', array_map('strval', (array) ($memory['tags'] ?? [])))) ?></dd>
            <dt>source</dt><dd><?= Page::e((string) ($memory['source'] ?? '')) ?></dd>
            <dt>confidence</dt><dd><?= Page::e((string) ($memory['confidence'] ?? '')) ?></dd>
            <dt>created</dt><dd><?= Page::e(Page::stamp((string) ($memory['created_at'] ?? ''))) ?></dd>
            <dt>updated</dt><dd><?= Page::e(Page::stamp((string) ($memory['updated_at'] ?? ''))) ?></dd>
            <?php if (!empty($memory['supersedes'])): ?>
                <dt>supersedes</dt><dd><?= Page::e((string) $memory['supersedes']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($memory['retired_at'])): ?>
                <dt>retired</dt><dd><?= Page::e(Page::stamp((string) $memory['retired_at'])) ?></dd>
            <?php endif; ?>
        </dl>
    </aside>
    </div>
<?php endif; ?>
</main>
</body>
</html>

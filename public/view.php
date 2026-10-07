<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Client;
use Esky\EskyException;
use Esky\Layout;
use Esky\Markdown;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;

$uid = isset($_GET['uid']) ? trim((string) $_GET['uid']) : '';
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if ($uid === '') {
    Layout::error('No memory id was given.');
}

try {
    $vault = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $client = new Client($vault);

    $memory = $client->find($uid, $query);
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}

if ($memory === null) {
    http_response_code(404);
}

$backHref = $query === '' ? '/index.php' : '/index.php?q=' . rawurlencode($query);
$text = (string) ($memory['text'] ?? '');
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head($memory === null ? 'Not found' : 'Esky — ' . Page::heading($memory)) ?>
<body>
<?= Layout::navbar('/index.php') ?>
<main class="container pb-5">
    <p><a href="<?= Page::e($backHref) ?>">&larr; Back to the list</a></p>

<?php if ($memory === null): ?>
    <h1>Memory not found</h1>
    <p class="text-body-secondary small">No memory with the id <code><?= Page::e($uid) ?></code> was found.</p>
<?php else: ?>
    <h1><?= Page::e(Page::heading($memory)) ?></h1>
    <p class="text-body-secondary small"><?= Page::e((string) ($memory['kind'] ?? '')) ?> &middot; updated <?= Page::e(Page::stamp((string) ($memory['updated_at'] ?? ''))) ?></p>
    <?php if (empty($memory['retired_at'])): ?>
        <a class="btn btn-sm btn-outline-danger" href="<?= Page::e('/forget.php?uid=' . rawurlencode((string) $memory['uid']) . ($query === '' ? '' : '&q=' . rawurlencode($query))) ?>">Forget</a>
    <?php endif; ?>

    <!-- Content left, metadata in a fixed-width right column. -->
    <div class="row g-3 mt-2 align-items-start">
    <div class="col-12 col-lg">
    <section class="card overflow-hidden">
        <input class="tab-state" type="radio" name="pane" id="pane-markdown" checked>
        <input class="tab-state" type="radio" name="pane" id="pane-html">
        <nav class="tabs">
            <label for="pane-markdown">Markdown</label>
            <label for="pane-html">HTML</label>
        </nav>
        <div class="card-body pane-markdown overflow-auto"><pre><?= Page::e($text) ?></pre></div>
        <div class="card-body pane-html rendered overflow-auto"><?= Markdown::toHtml($text) ?></div>
    </section>
    </div>

    <aside class="col-12 col-lg-auto fields-col">
    <div class="card fields">
        <div class="card-body small">
        <h2>Details</h2>
        <dl class="mb-0">
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
        </div>
    </div>
    </aside>
    </div>
<?php endif; ?>
</main>
<?= Layout::footer() ?>
</body>
</html>

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Client;
use Esky\Csrf;
use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;

/**
 * The only place the app writes. GET shows a confirm page and changes nothing;
 * the POST behind it forgets one memory. esky keeps a forgotten memory, so
 * this hides rather than destroys.
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    Layout::error('That method is not allowed here.', 405);
}

$uid = trim((string) ($method === 'POST' ? ($_POST['uid'] ?? '') : ($_GET['uid'] ?? '')));
if ($uid === '') {
    Layout::error('No memory id was given.', 400);
}

if ($method === 'POST' && !Csrf::verify($_POST['csrf'] ?? null)) {
    Layout::error('The form expired or was not valid. Go back and try again.', 403);
}

try {
    $vault = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $client = new Client($vault);

    // Re-checked on POST too: a forged uid is refused here, not sent to esky.
    $memory = Page::find($client->recent(500), $uid);
    if ($memory === null) {
        Layout::error('No memory with that id was found.', 404);
    }

    if ($method === 'POST') {
        $client->forget($uid, Page::reason((string) ($_POST['reason'] ?? '')));
        header('Location: /index.php?forgotten=1', true, 303);
        exit;
    }
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}

$back = '/view.php?uid=' . rawurlencode($uid);
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky — forget a memory') ?>
<body>
<?= Layout::navbar('/index.php') ?>
<main class="container pb-5">
    <h1>Forget this memory?</h1>
    <p class="text-body-secondary small">
        <?= Page::e(Page::heading($memory)) ?> &middot; <?= Page::e((string) ($memory['kind'] ?? '')) ?>
    </p>
    <p><?= Page::e(Page::preview((string) ($memory['text'] ?? ''), 300)) ?></p>
    <p class="small">It will stop appearing in searches. esky keeps it, so it can be restored on the server.</p>

    <form method="post" action="/forget.php" class="d-grid gap-3" style="max-width: 32rem">
        <input type="hidden" name="uid" value="<?= Page::e($uid) ?>">
        <input type="hidden" name="csrf" value="<?= Page::e(Csrf::token()) ?>">
        <label class="form-label mb-0">
            Reason (optional)
            <textarea class="form-control mt-1" name="reason" rows="2" maxlength="500"></textarea>
        </label>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-danger">Forget</button>
            <a class="btn btn-outline-secondary" href="<?= Page::e($back) ?>">Cancel</a>
        </div>
    </form>
</main>
</body>
</html>

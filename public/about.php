<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;

/* The active vault is resolved here rather than left to the navbar: resolving
   it starts the session, and the navbar renders after the first byte has gone
   out, by which point PHP can no longer send the cookie. */
try {
    $vaults = Vaults::load(dirname(__DIR__));
    $active = $vaults->current(Session::vault())->name;
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky — about') ?>
<body>
<?= Layout::navbar() ?>
<main class="container pb-5">
    <h1>About</h1>
    <p>
        A read-only browser for the Esky memory server. It lists and searches
        memories, shows one in full, and charts the store's aggregates. It
        never writes, updates or retires a memory.
    </p>
    <p class="text-body-secondary small">
        Pages read one vault at a time — the one chosen in the top-right menu.
        The search is semantic rather than literal, so an empty result is not a
        reliable “no match”.
    </p>

    <h2 class="h5 mt-4">Vaults</h2>
    <ul>
    <?php foreach ($vaults->all() as $vault): ?>
        <li>
            <strong><?= Page::e($vault->title) ?></strong>
            <?php if ($vault->name === $active): ?>
                <span class="badge text-bg-secondary">active</span>
            <?php endif; ?>
            — <code class="small"><?= Page::e($vault->url) ?></code>
        </li>
    <?php endforeach; ?>
    </ul>
    <p class="small"><a href="/settings.php">How these are configured</a></p>
</main>
</body>
</html>

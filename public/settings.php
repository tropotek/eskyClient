<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\EskyException;
use Esky\Health;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;

/**
 * A read-only view of config.json. There is no form: the app has no users and
 * no authentication, so vaults are edited in the file on the host.
 */
try {
    $vaults = Vaults::load(dirname(__DIR__));
    $active = $vaults->current(Session::vault())->name;
    $rows = Health::check($vaults);
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky — settings') ?>
<body>
<?= Layout::navbar() ?>
<main class="container pb-5">
    <h1>Settings</h1>
    <p class="text-body-secondary small">
        Vaults are configured in <code>config.json</code> in the project root.
        Edit that file and reload this page; nothing here writes to it.
    </p>

    <table class="table table-sm align-middle">
        <thead>
            <tr>
                <th scope="col">Vault</th>
                <th scope="col">MCP endpoint</th>
                <th scope="col">Profile</th>
                <th scope="col">Token</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>
                    <strong><?= Page::e($row['title']) ?></strong>
                    <?php if ($row['name'] === $active): ?>
                        <span class="badge text-bg-secondary ms-1">active</span>
                    <?php endif; ?>
                    <div class="text-body-secondary small"><?= Page::e($row['name']) ?></div>
                </td>
                <td class="small"><code><?= Page::e($row['url']) ?></code></td>
                <td class="small"><?= Page::e($row['profile'] ?? '—') ?></td>
                <td class="small"><code><?= Page::e($row['token']) ?></code></td>
                <td class="small">
                    <?php if ($row['ok']): ?>
                        <span class="health ok">●</span> reachable
                    <?php else: ?>
                        <span class="health bad">●</span> unreachable
                        <div class="text-body-secondary"><?= Page::e($row['message']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>

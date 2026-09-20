<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\EskyException;
use Esky\Layout;
use Esky\Session;
use Esky\Vaults;

/**
 * Sets the vault the session reads and sends the visitor back. Nothing is
 * rendered here — the chosen vault lives in the session, never in a url, so
 * this is the only place it is written.
 */
try {
    $vaults = Vaults::load(dirname(__DIR__));
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}

$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
if ($vaults->get($to) !== null) {
    Session::setVault($to);
}

$back = Layout::backTarget(isset($_GET['back']) ? (string) $_GET['back'] : null);

header('Location: ' . $back, true, 302);
exit;

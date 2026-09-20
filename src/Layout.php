<?php
declare(strict_types=1);

namespace Esky;

/**
 * The chrome every page wears: the head block, the navbar and the error page.
 * Kept apart from Page, which holds the helpers that format a record's fields.
 */
final class Layout
{
    /** The pages a visitor can reach, as href => label, in navbar order. */
    private const NAV = [
        '/index.php' => 'Memories',
        '/metrics.php' => 'Metrics',
    ];

    /** The pages the vault selector may return to after a switch. */
    private const SWITCHABLE = ['/index.php', '/metrics.php'];

    /**
     * Bootstrap is vendored rather than loaded from a CDN: these pages are read
     * on a LAN that need not have a route to the internet. Its JavaScript
     * bundle is vendored the same way, for the navbar's dropdowns — the vault
     * selector and the settings menu — which are the only components here that
     * need it.
     */
    public static function head(string $title): string
    {
        $safe = Page::e($title);

        return <<<HTML
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$safe}</title>
        <link rel="stylesheet" href="/vendor/bootstrap.min.css">
        <link rel="stylesheet" href="/style.css">
        <script src="/vendor/bootstrap.bundle.min.js" defer></script>
        </head>
        HTML;
    }

    /**
     * The brand carries the product name, so a page heading below it need not
     * repeat it. $here is the href of the page being shown, when it is one of
     * the navigable ones.
     *
     * The vaults are resolved here rather than passed in, so a page cannot
     * render the navbar without naming the one it is reading.
     */
    public static function navbar(string $here = ''): string
    {
        return self::navbarFor(self::vaults(), $here);
    }

    /**
     * $vaults is null when the configuration will not load, which is the error
     * page's case: the chrome still renders, it simply names no vault. Taking
     * them as an argument is also what lets the markup be tested against a
     * configuration held in memory, with no file on disk.
     */
    public static function navbarFor(?Vaults $vaults, string $here = ''): string
    {
        $links = '';
        foreach (self::NAV as $href => $label) {
            $links .= $href === $here
                ? sprintf(
                    '<li class="nav-item"><span class="nav-link active" aria-current="page">%s</span></li>',
                    Page::e($label)
                )
                : sprintf(
                    '<li class="nav-item"><a class="nav-link" href="%s">%s</a></li>',
                    Page::e($href),
                    Page::e($label)
                );
        }

        /* One menu, not two: the vaults sit at the top of the avatar dropdown
           with a tick on the active one, then the pages. Bootstrap's own
           avatar-dropdown pattern, with the same placeholder tk8base serves.
           There is no user behind it — no accounts, no login — so the name
           beside the avatar is the vault being read, not a person. */
        $items = '';
        $name = '';
        if ($vaults !== null) {
            $active = $vaults->current(Session::vault());
            $name = sprintf(
                '<span class="vault d-none d-sm-inline ms-2">%s</span>',
                Page::e($active->title)
            );
            $back = self::backTarget($here);

            $items = '<li><h6 class="dropdown-header">Vaults</h6></li>';
            foreach ($vaults->all() as $vault) {
                $items .= sprintf(
                    '<li><a class="dropdown-item%s" href="/vault.php?to=%s&amp;back=%s">%s%s</a></li>',
                    $vault->name === $active->name ? ' active' : '',
                    Page::e(rawurlencode($vault->name)),
                    Page::e(rawurlencode($back)),
                    Page::e($vault->title),
                    $vault->name === $active->name ? ' &#10003;' : ''
                );
            }
            $items .= '<li><hr class="dropdown-divider"></li>';
        }

        $menu = <<<HTML
        <div class="dropdown user-menu">
            <button class="btn user-toggle dropdown-toggle d-flex align-items-center" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu">
                <img src="/img/user.png" alt="" width="32" height="32" class="rounded-circle">
                {$name}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                {$items}
                <li><a class="dropdown-item" href="/settings.php">Settings</a></li>
                <li><a class="dropdown-item" href="/about.php">About</a></li>
            </ul>
        </div>
        HTML;

        return <<<HTML
        <nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom mb-4">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center gap-2" href="/index.php">
                    <span aria-hidden="true">🧊</span> Esky
                </a>
                <div class="d-flex align-items-center gap-2 order-lg-last ms-auto ms-lg-0">
                    {$menu}
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                            data-bs-target="#esky-nav" aria-controls="esky-nav"
                            aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
                <div class="collapse navbar-collapse" id="esky-nav">
                    <ul class="navbar-nav me-auto">{$links}</ul>
                </div>
            </div>
        </nav>
        HTML;
    }

    /**
     * back comes off the query string, so only the pages a switch makes sense
     * on are honoured. Anything else — including a detail page, whose uid
     * belongs to one vault — lands on the list.
     */
    public static function backTarget(?string $raw): string
    {
        return in_array($raw, self::SWITCHABLE, true) ? $raw : '/index.php';
    }

    /**
     * Reading the configuration costs a file read and no network call, so the
     * navbar resolves the vaults itself. A configuration that will not load is
     * the error page's business, not the navbar's — it simply names no vault.
     */
    private static function vaults(): ?Vaults
    {
        try {
            return Vaults::load(dirname(__DIR__));
        } catch (EskyException) {
            return null;
        }
    }

    /** A self-contained 500 page. It exits, so nothing after it runs. */
    public static function error(string $message): never
    {
        http_response_code(500);
        $safe = Page::e($message);
        $head = self::head('Esky — error');
        $navbar = self::navbar();
        echo <<<HTML
        <!doctype html>
        <html lang="en" data-bs-theme="dark">
        {$head}
        <body>
        {$navbar}
        <main class="container pb-5">
        <h1>Something went wrong</h1>
        <div class="alert alert-danger" role="alert">{$safe}</div>
        <p><a href="/">Back to the list</a></p>
        </main></body></html>
        HTML;
        exit;
    }
}

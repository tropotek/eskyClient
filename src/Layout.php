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

    /** What the vault name in the navbar means, for anyone who has not met it. */
    private const VAULT_TIP =
        'Memory vault: the store these pages read. Derived from ESKY_URL, which the token grants access to.';

    /**
     * Bootstrap is vendored rather than loaded from a CDN: these pages are read
     * on a LAN that need not have a route to the internet. Its own JavaScript
     * is not loaded at all — nothing here needs it.
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
        </head>
        HTML;
    }

    /**
     * The brand carries the product name, so a page heading below it need not
     * repeat it. $here is the href of the page being shown, when it is one of
     * the navigable ones.
     *
     * The vault name is resolved here rather than passed in, so a page cannot
     * render the navbar without it.
     */
    public static function navbar(string $here = ''): string
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

        /* A native title attribute rather than Bootstrap's tooltip: that one
           needs Popper and Bootstrap's JavaScript, which these pages do not
           load — the same reason the charts label themselves with <title>. */
        $vault = self::vault();
        $trailing = $vault === ''
            ? ''
            : sprintf(
                '<span class="navbar-text small"><strong class="vault" title="%s">%s</strong></span>',
                Page::e(self::VAULT_TIP),
                Page::e($vault)
            );

        return <<<HTML
        <nav class="navbar navbar-expand bg-body-tertiary border-bottom mb-4">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center gap-2" href="/index.php">
                    <span aria-hidden="true">🧊</span> Esky
                </a>
                <ul class="navbar-nav me-auto">{$links}</ul>
                {$trailing}
            </div>
        </nav>
        HTML;
    }

    /**
     * Reading the config costs an environment lookup and no network call, so
     * the navbar resolves the vault itself. A config that will not load is the
     * error page's business, not the navbar's — it simply names no vault.
     */
    private static function vault(): string
    {
        try {
            return (string) Config::fromEnvironment(dirname(__DIR__))->profile;
        } catch (EskyException) {
            return '';
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

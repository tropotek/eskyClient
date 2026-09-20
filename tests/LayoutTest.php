<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Layout;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    /** @return list<string> */
    private function vaultTitles(): array
    {
        try {
            $vaults = \Esky\Vaults::load(dirname(__DIR__));
        } catch (\Esky\EskyException) {
            self::markTestSkipped('No config.json in the project root.');
        }

        return array_column($vaults->all(), 'title');
    }

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTheCurrentPageIsMarkedAndTheOthersAreLinks(): void
    {
        $html = Layout::navbar('/metrics.php');

        self::assertStringContainsString('aria-current="page">Metrics<', $html);
        self::assertStringContainsString('href="/index.php">Memories<', $html);
        self::assertStringNotContainsString('href="/metrics.php"', $html);
    }

    /* The vault is resolved inside the navbar rather than passed in, so that a
       page cannot render the navbar and silently leave the vault unnamed. */
    public function testEveryNavbarNamesTheActiveVault(): void
    {
        $first = $this->vaultTitles()[0];

        foreach (['/index.php', '/metrics.php', ''] as $here) {
            $html = Layout::navbar($here);

            self::assertStringContainsString(
                '<span class="vault d-none d-sm-inline ms-2">' . \Esky\Page::e($first) . '</span>',
                $html
            );
        }
    }

    public function testTheNavbarOffersEveryConfiguredVault(): void
    {
        $titles = $this->vaultTitles();
        $html = Layout::navbar('/index.php');

        foreach ($titles as $title) {
            self::assertStringContainsString('>' . \Esky\Page::e($title) . '<', $html);
        }
        self::assertStringContainsString('href="/vault.php?to=', $html);
    }

    public function testTheActiveVaultIsTicked(): void
    {
        $this->vaultTitles();
        $html = Layout::navbar('/index.php');

        self::assertSame(1, substr_count($html, 'dropdown-item active'));
    }

    public function testTheSwitchLinksCarryThePageToReturnTo(): void
    {
        $this->vaultTitles();

        self::assertStringContainsString('&amp;back=%2Fmetrics.php', Layout::navbar('/metrics.php'));
        self::assertStringContainsString('&amp;back=%2Findex.php', Layout::navbar('/view.php'));
    }

    public function testTheSettingsMenuIsPresent(): void
    {
        $html = Layout::navbar('/index.php');

        self::assertStringContainsString('href="/settings.php"', $html);
        self::assertStringContainsString('href="/about.php"', $html);
    }

    /* The avatar is the same placeholder tk8base uses, vendored like every
       other asset here — nothing is fetched from off the LAN. */
    public function testTheUserMenuShowsAVendoredAvatar(): void
    {
        $html = Layout::navbar('/index.php');

        self::assertStringContainsString('src="/img/user.png"', $html);
        self::assertStringContainsString('rounded-circle', $html);
        self::assertStringNotContainsString('//cdn', $html);
        self::assertFileExists(dirname(__DIR__) . '/public/img/user.png');
    }

    /* One menu at the right-hand end, its panel aligned to the toggle rather
       than spilling off the edge of the viewport. */
    public function testTheOneDropdownPanelIsRightAligned(): void
    {
        $html = Layout::navbar('/index.php');

        self::assertSame(1, substr_count($html, 'dropdown-menu-end'));
        self::assertSame(1, substr_count($html, 'data-bs-toggle="dropdown"'));
    }

    /* Bootstrap's own caret, from .dropdown-toggle, so the avatar reads as a
       menu rather than a button. It stays visible when the vault name is
       hidden at phone widths. */
    public function testTheAvatarCarriesADropdownCaret(): void
    {
        self::assertStringContainsString('user-toggle dropdown-toggle', Layout::navbar('/index.php'));
    }

    /* The vaults head the menu, above the pages. */
    public function testTheVaultsSitAboveThePagesInTheMenu(): void
    {
        $this->vaultTitles();
        $html = Layout::navbar('/index.php');

        self::assertStringContainsString('dropdown-header">Vaults<', $html);
        self::assertGreaterThan(
            strpos($html, 'href="/vault.php?to='),
            strpos($html, 'href="/settings.php"'),
            'the vault list should come before the page links'
        );
    }

    /* The nav links collapse behind a toggler below the lg breakpoint, the way
       a stock Bootstrap navbar does; the vault and user menus stay outside the
       collapse so they are reachable at every width. */
    public function testTheNavbarCollapsesOnSmallScreens(): void
    {
        $html = Layout::navbar('/index.php');

        self::assertStringContainsString('navbar-expand-lg', $html);
        self::assertStringContainsString('navbar-toggler', $html);
        self::assertStringContainsString('data-bs-toggle="collapse"', $html);
        self::assertStringContainsString('id="esky-nav"', $html);
        self::assertStringContainsString('data-bs-target="#esky-nav"', $html);
        self::assertStringContainsString('class="collapse navbar-collapse"', $html);
    }

    /* A configuration that will not load is the error page's business: the
       navbar still has to render, it simply names no vault. */
    public function testANavbarWithNoVaultToNameStillRenders(): void
    {
        $html = Layout::navbarFor(null, '/index.php');

        self::assertStringContainsString('navbar-brand', $html);
        self::assertStringContainsString('href="/settings.php"', $html);
        self::assertStringNotContainsString('href="/vault.php', $html);
        self::assertStringNotContainsString('class="vault', $html);
    }

    public function testTheVaultTitleIsEscaped(): void
    {
        $vaults = \Esky\Vaults::fromJson(
            '{"vaults": [{"name": "x", "title": "<script>",'
            . ' "url": "http://h/mcp/x", "token": "t"}]}'
        );

        self::assertStringNotContainsString('<script>', Layout::navbarFor($vaults, '/index.php'));
    }

    public function testTheHeadLoadsTheVendoredBootstrapAndNoCdn(): void
    {
        $html = Layout::head('Esky Memories');

        self::assertStringContainsString('href="/vendor/bootstrap.min.css"', $html);
        self::assertStringContainsString('src="/vendor/bootstrap.bundle.min.js"', $html);
        self::assertStringContainsString('href="/style.css"', $html);
        self::assertStringNotContainsString('//cdn', $html);
        self::assertStringNotContainsString('http', $html);
    }

    /* The dropdowns need Bootstrap's JavaScript, which is why it is vendored
       at all — so the file has to actually be there. */
    public function testTheBootstrapBundleIsVendoredOnDisk(): void
    {
        $path = dirname(__DIR__) . '/public/vendor/bootstrap.bundle.min.js';

        self::assertFileExists($path);
        self::assertGreaterThan(50_000, filesize($path));
    }

    public function testTheTitleIsEscaped(): void
    {
        self::assertStringNotContainsString('<script>', Layout::head('<script>'));
    }
}

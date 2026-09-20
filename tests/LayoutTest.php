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

            self::assertStringContainsString(\Esky\Page::e($first), $html);
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

    /* A configuration that will not load is the error page's business: the
       navbar still has to render, it simply names no vault. */
    public function testANavbarWithNoVaultToNameStillRenders(): void
    {
        $html = Layout::navbarFor(null, '/index.php');

        self::assertStringContainsString('navbar-brand', $html);
        self::assertStringNotContainsString('vault-menu', $html);
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

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

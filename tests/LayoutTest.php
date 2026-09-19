<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Layout;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('ESKY_URL=http://example.test/mcp/personal');
        putenv('ESKY_TOKEN=tok');
    }

    protected function tearDown(): void
    {
        putenv('ESKY_URL');
        putenv('ESKY_TOKEN');
        putenv('ESKY_PROFILE');
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
    public function testEveryNavbarNamesTheVaultWithoutBeingToldIt(): void
    {
        foreach (['/index.php', '/metrics.php', ''] as $here) {
            $html = Layout::navbar($here);

            self::assertStringContainsString('>personal<', $html);
            self::assertStringContainsString('class="vault" title="Memory vault:', $html);
        }
    }

    /* A url with no profile segment names no vault, and the navbar has to
       render anyway — the error page wears one at the moment the config is the
       thing that went wrong. */
    public function testANavbarWithNoVaultToNameStillRenders(): void
    {
        putenv('ESKY_URL=http://example.test/mcp');

        $html = Layout::navbar('/index.php');

        self::assertStringContainsString('navbar-brand', $html);
        self::assertStringNotContainsString('class="vault"', $html);
    }

    public function testTheVaultNameIsEscaped(): void
    {
        putenv('ESKY_PROFILE=<script>');

        self::assertStringNotContainsString('<script>', Layout::navbar());
    }

    public function testTheHeadLoadsTheVendoredBootstrapAndNoCdn(): void
    {
        $html = Layout::head('Esky Memories');

        self::assertStringContainsString('href="/vendor/bootstrap.min.css"', $html);
        self::assertStringContainsString('href="/style.css"', $html);
        self::assertStringNotContainsString('//cdn', $html);
    }

    public function testTheTitleIsEscaped(): void
    {
        self::assertStringNotContainsString('<script>', Layout::head('<script>'));
    }
}

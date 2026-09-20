<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Config;
use Esky\EskyException;
use Esky\Health;
use Esky\Vaults;
use PHPUnit\Framework\TestCase;

final class HealthTest extends TestCase
{
    private const TWO = <<<'JSON'
    {"vaults": [
      {"name": "personal", "title": "Personal",
       "url": "http://host:8011/mcp/personal", "token": "sk-personal-0000f3a2"},
      {"name": "work", "title": "Work",
       "url": "http://host:8011/mcp/work", "token": "sk-work-00000abcd"}
    ]}
    JSON;

    public function testAReachableVaultIsOkAndCarriesItsDetails(): void
    {
        $rows = Health::check(Vaults::fromJson(self::TWO), static fn (Config $c) => null);

        self::assertCount(2, $rows);
        self::assertTrue($rows[0]['ok']);
        self::assertSame('personal', $rows[0]['name']);
        self::assertSame('Personal', $rows[0]['title']);
        self::assertSame('http://host:8011/mcp/personal', $rows[0]['url']);
        self::assertSame('personal', $rows[0]['profile']);
        self::assertSame('', $rows[0]['message']);
    }

    public function testTheTokenNeverLeavesTheProbeUnmasked(): void
    {
        $rows = Health::check(Vaults::fromJson(self::TWO), static fn (Config $c) => null);

        self::assertSame('sk-…f3a2', $rows[0]['token']);
        self::assertStringNotContainsString('personal-0000', $rows[0]['token']);
    }

    /* One unreachable vault must not take the settings page down with it. */
    public function testAnUnreachableVaultCarriesItsErrorAndTheOthersStillReport(): void
    {
        $probe = static function (Config $c): void {
            if ($c->name === 'work') {
                throw new EskyException('Could not reach Esky: connection refused');
            }
        };

        $rows = Health::check(Vaults::fromJson(self::TWO), $probe);

        self::assertTrue($rows[0]['ok']);
        self::assertFalse($rows[1]['ok']);
        self::assertStringContainsString('connection refused', $rows[1]['message']);
    }
}

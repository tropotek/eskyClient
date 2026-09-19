<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Config;
use Esky\EskyException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/esky-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
        putenv('ESKY_URL');
        putenv('ESKY_TOKEN');
    }

    public function testReadsFromEnvironment(): void
    {
        putenv('ESKY_URL=http://example.test/mcp');
        putenv('ESKY_TOKEN=tok-from-env');

        $config = Config::fromEnvironment($this->dir);

        self::assertSame('http://example.test/mcp', $config->url);
        self::assertSame('tok-from-env', $config->token);
    }

    public function testFallsBackToDotEnvFile(): void
    {
        file_put_contents(
            $this->dir . '/.env',
            "ESKY_URL=http://file.test/mcp\nESKY_TOKEN=tok-from-file\n"
        );

        $config = Config::fromEnvironment($this->dir);

        self::assertSame('http://file.test/mcp', $config->url);
        self::assertSame('tok-from-file', $config->token);
    }

    public function testEnvironmentWinsOverDotEnvFile(): void
    {
        file_put_contents(
            $this->dir . '/.env',
            "ESKY_URL=http://file.test/mcp\nESKY_TOKEN=tok-from-file\n"
        );
        putenv('ESKY_TOKEN=tok-from-env');

        $config = Config::fromEnvironment($this->dir);

        self::assertSame('tok-from-env', $config->token);
    }

    public function testMissingTokenNamesTheVariable(): void
    {
        putenv('ESKY_URL=http://example.test/mcp');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/ESKY_TOKEN/');

        Config::fromEnvironment($this->dir);
    }

    public function testMissingUrlNamesTheVariable(): void
    {
        putenv('ESKY_TOKEN=tok');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/ESKY_URL/');

        Config::fromEnvironment($this->dir);
    }
}

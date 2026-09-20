<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testTheRestBaseAndProfileAreDerivedFromTheMcpUrl(): void
    {
        $config = new Config('personal', 'Personal', 'http://192.168.0.7:8011/mcp/personal', 'tok');

        self::assertSame('http://192.168.0.7:8011', $config->apiBase);
        self::assertSame('personal', $config->profile);
    }

    public function testAnMcpUrlWithNoProfileSegmentLeavesTheProfileUnknown(): void
    {
        self::assertNull(new Config('p', 'P', 'http://example.test/mcp', 'tok')->profile);
    }

    public function testTheDerivedRestBaseAndProfileCanBeOverridden(): void
    {
        $config = new Config(
            'personal',
            'Personal',
            'http://192.168.0.7:8011/mcp/personal',
            'tok',
            'http://elsewhere.test:9000/',
            'work',
        );

        self::assertSame('http://elsewhere.test:9000', $config->apiBase);
        self::assertSame('work', $config->profile);
    }

    public function testATrailingSlashIsTrimmedFromTheRestBase(): void
    {
        $config = new Config('p', 'P', 'http://h/mcp/p', 'tok', 'http://h:9000/');

        self::assertSame('http://h:9000', $config->apiBase);
    }
}

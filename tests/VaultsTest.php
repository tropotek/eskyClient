<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\EskyException;
use Esky\Vaults;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VaultsTest extends TestCase
{
    private const TWO = <<<'JSON'
    {"vaults": [
      {"name": "personal", "title": "Personal",
       "url": "http://host:8011/mcp/personal", "token": "sk-personal-0000f3a2"},
      {"name": "work", "title": "Work",
       "url": "http://host:8011/mcp/work", "token": "sk-work-0000abcd"}
    ]}
    JSON;

    public function testEveryVaultBecomesAConfigInFileOrder(): void
    {
        $all = Vaults::fromJson(self::TWO)->all();

        self::assertCount(2, $all);
        self::assertSame(['personal', 'work'], array_column($all, 'name'));
        self::assertSame(['Personal', 'Work'], array_column($all, 'title'));
        self::assertSame('sk-personal-0000f3a2', $all[0]->token);
    }

    public function testTheRestBaseAndProfileAreStillDerivedFromTheMcpUrl(): void
    {
        $vault = Vaults::fromJson(self::TWO)->get('work');

        self::assertNotNull($vault);
        self::assertSame('http://host:8011', $vault->apiBase);
        self::assertSame('work', $vault->profile);
    }

    public function testTheDerivedRestBaseAndProfileCanBeOverriddenPerVault(): void
    {
        $json = <<<'JSON'
        {"vaults": [{"name": "work", "title": "Work",
          "url": "http://host:8011/mcp/work", "token": "tok",
          "apiUrl": "http://elsewhere.test:9000/", "profile": "other"}]}
        JSON;

        $vault = Vaults::fromJson($json)->first();

        self::assertSame('http://elsewhere.test:9000', $vault->apiBase);
        self::assertSame('other', $vault->profile);
    }

    public function testAnUnknownVaultIsNull(): void
    {
        self::assertNull(Vaults::fromJson(self::TWO)->get('nope'));
    }

    /* The session may name a vault that has since been removed from the file,
       and a stale cookie must not take the pages down. */
    public function testCurrentFallsBackToTheFirstVault(): void
    {
        $vaults = Vaults::fromJson(self::TWO);

        self::assertSame('work', $vaults->current('work')->name);
        self::assertSame('personal', $vaults->current('gone')->name);
        self::assertSame('personal', $vaults->current(null)->name);
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        Vaults::fromJson('{"vaults": [');
    }

    public function testAnEmptyVaultListIsRejected(): void
    {
        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/no vaults/');

        Vaults::fromJson('{"vaults": []}');
    }

    public function testAMissingVaultsKeyIsRejected(): void
    {
        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/no vaults/');

        Vaults::fromJson('{}');
    }

    public function testADuplicateNameIsRejected(): void
    {
        $json = <<<'JSON'
        {"vaults": [
          {"name": "work", "title": "A", "url": "http://h/mcp/work", "token": "t"},
          {"name": "work", "title": "B", "url": "http://h/mcp/work", "token": "t"}
        ]}
        JSON;

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/duplicate.*work/i');

        Vaults::fromJson($json);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function missingFields(): array
    {
        return [
            ['name', '{"vaults": [{"title": "W", "url": "http://h/mcp/w", "token": "t"}]}'],
            ['title', '{"vaults": [{"name": "w", "url": "http://h/mcp/w", "token": "t"}]}'],
            ['url', '{"vaults": [{"name": "w", "title": "W", "token": "t"}]}'],
            ['token', '{"vaults": [{"name": "w", "title": "W", "url": "http://h/mcp/w"}]}'],
        ];
    }

    #[DataProvider('missingFields')]
    public function testAMissingFieldNamesItself(string $field, string $json): void
    {
        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/' . $field . '/');

        Vaults::fromJson($json);
    }

    public function testAnIllegalVaultNameIsRejected(): void
    {
        $json = '{"vaults": [{"name": "My Vault!", "title": "W",'
            . ' "url": "http://h/mcp/w", "token": "t"}]}';

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/name/');

        Vaults::fromJson($json);
    }

    public function testAMissingFileNamesThePathItLookedIn(): void
    {
        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('#/nowhere/config\.json#');

        Vaults::load('/nowhere');
    }

    /* config.json holds bearer tokens, so it lives beside the project root and
       never under public/, which is the only directory FrankenPHP serves. */
    public function testTheConfigFileLivesOutsideTheWebRoot(): void
    {
        self::assertSame('/srv/app/config.json', Vaults::path('/srv/app'));
        self::assertStringNotContainsString('public', Vaults::path('/srv/app'));
    }
}

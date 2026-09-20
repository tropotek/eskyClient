# Multi-vault Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Configure several esky memory servers in one `config.json`, switch between them from a navbar dropdown, and see which are reachable on a settings page.

**Architecture:** A new `Vaults` class loads `config.json` and hands out `Config` objects — one per vault — so `Client` and `Api` are untouched and still take a single `Config`. The active vault lives in the PHP session, resolved through a tiny `Session` helper that keeps session handling out of `Vaults` so the loader stays testable. Bootstrap's JavaScript bundle is vendored (no CDN) so the navbar can use real dropdowns.

**Tech Stack:** PHP 8.4, PHPUnit 11, Bootstrap 5.3.3 (vendored), FrankenPHP, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-21-multi-vault-design.md`

## Global Constraints

- PHP 8.4. `declare(strict_types=1);` at the top of every PHP file.
- All classes `final`. Constructor property promotion with `readonly`.
- Comments explain *why* (an API quirk, a deliberate limitation), never *what*.
- **No test may open a socket.** The PHPUnit suite is offline-only. Anything
  that talks to the network is injected as a closure and stubbed in tests.
- No CDN. Every asset is served from `public/vendor/`.
- The app stays read-only against esky: only `memory_recent` and
  `memory_search` are ever called.
- Commits are Conventional Commits, one logical layer per commit.
- Everything runs in the container. Tests:
  `docker compose run --rm app vendor/bin/phpunit`

## File Structure

| File | Responsibility |
|---|---|
| `src/Vaults.php` (new) | Parse and validate `config.json`; hand out `Config` objects; resolve the active one |
| `src/Session.php` (new) | Read and write the active vault name in the session; the only file that touches `$_SESSION` |
| `src/Health.php` (new) | Probe each vault and reduce the result to a plain array for rendering |
| `src/Config.php` | Gains `name` and `title`; loses `fromEnvironment()` |
| `src/Client.php` | Gains `ping()` — handshake only, no tool call |
| `src/Page.php` | Gains `mask()` for tokens |
| `src/Layout.php` | Navbar dropdowns; loads the vendored Bootstrap bundle |
| `public/vault.php` (new) | Set the session's vault and redirect |
| `public/settings.php` (new) | Read-only vault table with health badges |
| `public/about.php` (new) | What Esky is, the vaults configured |
| `config.json.example` (new) | Tracked template for the git-ignored `config.json` |

---

### Task 1: Load vaults from config.json

**Files:**
- Create: `src/Vaults.php`
- Create: `config.json.example`
- Modify: `src/Config.php` (add `name` and `title` to the constructor)
- Modify: `tests/ApiTest.php:21` (the one `new Config(...)` call site)
- Modify: `.gitignore`
- Test: `tests/VaultsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Esky\Config::__construct(string $name, string $title, string $url, string $token, ?string $apiBase = null, ?string $profile = null)`, with public readonly `name`, `title`, `url`, `token`, `apiBase`, `profile`.
  - `Esky\Vaults::fromJson(string $json): self`
  - `Esky\Vaults::load(string $projectDir): self`
  - `Esky\Vaults::path(string $projectDir): string`
  - `Esky\Vaults::all(): list<Config>`
  - `Esky\Vaults::get(string $name): ?Config`
  - `Esky\Vaults::first(): Config`
  - `Esky\Vaults::current(?string $name): Config`
  - All failures throw `Esky\EskyException`.

- [ ] **Step 1: Write the failing tests**

Create `tests/VaultsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\EskyException;
use Esky\Vaults;
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

    #[\PHPUnit\Framework\Attributes\DataProvider('missingFields')]
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm app vendor/bin/phpunit tests/VaultsTest.php`
Expected: FAIL — `Class "Esky\Vaults" not found`.

- [ ] **Step 3: Add `name` and `title` to `Config`**

In `src/Config.php`, change the constructor signature and the class docblock.
Delete `fromEnvironment()` and the private `value()` helper — they are replaced
in Task 2, and nothing else uses them once `Vaults` exists. Keep
`deriveBase()` and `deriveProfile()` exactly as they are.

```php
/**
 * One configured esky vault: the MCP endpoint, the token that opens it, and
 * the display name the navbar shows.
 *
 * The REST surface (`/api/{profile}/…`, which the metrics page reads) lives on
 * the same host and behind the same token as the MCP endpoint, so both the base
 * and the profile name are derived from the MCP url rather than configured
 * twice and left to drift. The optional apiUrl and profile fields in
 * config.json override the derivation for a deployment where that assumption
 * does not hold.
 */
final class Config
{
    public readonly string $apiBase;
    public readonly ?string $profile;

    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $url,
        public readonly string $token,
        ?string $apiBase = null,
        ?string $profile = null,
    ) {
        $this->apiBase = rtrim($apiBase ?? self::deriveBase($url), '/');
        $this->profile = $profile ?? self::deriveProfile($url);
    }

    // deriveBase() and deriveProfile() unchanged below.
}
```

- [ ] **Step 4: Write `src/Vaults.php`**

```php
<?php
declare(strict_types=1);

namespace Esky;

/**
 * The vaults configured in config.json, as Config objects.
 *
 * Vaults are configured in a file rather than through the app because the app
 * has no users and no authentication: a form that stored bearer tokens would
 * be writable by anyone who could reach the port.
 *
 * Nothing here touches the session — Session resolves the active name and
 * passes it to current(), which keeps this loader testable in CLI.
 */
final class Vaults
{
    private const NAME = '/^[a-z0-9_-]+$/';

    /** @param list<Config> $vaults */
    private function __construct(private readonly array $vaults)
    {
    }

    public static function path(string $projectDir): string
    {
        return rtrim($projectDir, '/') . '/config.json';
    }

    public static function load(string $projectDir): self
    {
        $path = self::path($projectDir);
        $json = is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new EskyException(sprintf(
                'Cannot read the vault configuration at %s — copy config.json.example to config.json.',
                $path
            ));
        }

        return self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new EskyException('config.json is not valid JSON: ' . $e->getMessage());
        }

        $list = (is_array($data) ? $data['vaults'] ?? null : null);
        if (!is_array($list) || $list === []) {
            throw new EskyException('config.json defines no vaults.');
        }

        $vaults = [];
        $seen = [];
        foreach (array_values($list) as $i => $entry) {
            if (!is_array($entry)) {
                throw new EskyException(sprintf('Vault %d in config.json is not an object.', $i + 1));
            }

            $name = self::required($entry, 'name', $i);
            if (preg_match(self::NAME, $name) !== 1) {
                throw new EskyException(sprintf(
                    'Vault name "%s" is not usable: names may hold lowercase letters, digits, - and _ only.',
                    $name
                ));
            }
            if (isset($seen[$name])) {
                throw new EskyException(sprintf('config.json has a duplicate vault name: %s', $name));
            }
            $seen[$name] = true;

            $vaults[] = new Config(
                $name,
                self::required($entry, 'title', $i),
                self::required($entry, 'url', $i),
                self::required($entry, 'token', $i),
                self::optional($entry, 'apiUrl'),
                self::optional($entry, 'profile'),
            );
        }

        return new self($vaults);
    }

    /** @return list<Config> */
    public function all(): array
    {
        return $this->vaults;
    }

    public function get(string $name): ?Config
    {
        foreach ($this->vaults as $vault) {
            if ($vault->name === $name) {
                return $vault;
            }
        }

        return null;
    }

    public function first(): Config
    {
        return $this->vaults[0];
    }

    /**
     * The session may still name a vault that has been removed from the file,
     * so an unknown name falls back rather than failing the request.
     */
    public function current(?string $name): Config
    {
        return ($name === null ? null : $this->get($name)) ?? $this->first();
    }

    private static function required(array $entry, string $key, int $index): string
    {
        $value = $entry[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new EskyException(sprintf(
                'Vault %d in config.json is missing a "%s".',
                $index + 1,
                $key
            ));
        }

        return trim($value);
    }

    private static function optional(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
```

- [ ] **Step 5: Fix the one existing `new Config(...)` call site**

`tests/ApiTest.php:21` — change `new Config($url, 'tok'),` to:

```php
            new Config('personal', 'Personal', $url, 'tok'),
```

- [ ] **Step 6: Add `config.json.example`**

```json
{
  "vaults": [
    {
      "name": "personal",
      "title": "Personal",
      "url": "http://host:8011/mcp/personal",
      "token": "replace-with-your-bearer-token"
    },
    {
      "name": "work",
      "title": "Work",
      "url": "http://host:8011/mcp/work",
      "token": "replace-with-your-bearer-token"
    }
  ]
}
```

- [ ] **Step 7: Ignore the real file**

Add to `.gitignore`, after the `/.env` line:

```
/config.json
```

- [ ] **Step 8: Run the tests**

Run: `docker compose run --rm app vendor/bin/phpunit tests/VaultsTest.php tests/ApiTest.php`
Expected: PASS. `ConfigTest` and `LayoutTest` will now fail because
`Config::fromEnvironment()` is gone — that is Task 2's work; do not run the
full suite yet.

- [ ] **Step 9: Commit**

```bash
git add src/Vaults.php src/Config.php tests/VaultsTest.php tests/ApiTest.php config.json.example .gitignore
git commit -m "feat: load several vaults from config.json"
```

---

### Task 2: Point every page at the selected vault

**Files:**
- Create: `src/Session.php`
- Modify: `public/index.php:15`, `public/view.php:35`, `public/metrics.php:22`
- Modify: `src/Layout.php:99` (the `vault()` helper) and `src/Layout.php:20` (the tooltip text)
- Modify: `bin/smoke.php:11`
- Modify: `tests/ConfigTest.php` (rewrite), `tests/LayoutTest.php` (setUp/tearDown)
- Test: `tests/SessionTest.php`

**Interfaces:**
- Consumes: `Vaults::load()`, `Vaults::current()`, `Config::$name`, `Config::$title` from Task 1.
- Produces:
  - `Esky\Session::vault(): ?string`
  - `Esky\Session::setVault(string $name): void`
  - `Esky\Layout::vaults(): ?Vaults` (private→ internal helper; the navbar uses it)

- [ ] **Step 1: Write the failing test**

Create `tests/SessionTest.php`:

```php
<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testNothingChosenYetIsNull(): void
    {
        self::assertNull(Session::vault());
    }

    public function testTheChosenVaultIsRemembered(): void
    {
        Session::setVault('work');

        self::assertSame('work', Session::vault());
    }

    /* The session is a cookie the visitor controls, so anything that is not a
       plain string is treated as nothing chosen. */
    public function testANonStringInTheSessionIsIgnored(): void
    {
        $_SESSION['esky_vault'] = ['work'];

        self::assertNull(Session::vault());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose run --rm app vendor/bin/phpunit tests/SessionTest.php`
Expected: FAIL — `Class "Esky\Session" not found`.

- [ ] **Step 3: Write `src/Session.php`**

```php
<?php
declare(strict_types=1);

namespace Esky;

/**
 * The active vault name, held in the session and nowhere else.
 *
 * The only file that touches $_SESSION. Kept apart from Vaults so the loader
 * can be tested in CLI, where starting a session is neither possible nor
 * wanted — under CLI this reads and writes the superglobal directly.
 */
final class Session
{
    private const KEY = 'esky_vault';

    public static function vault(): ?string
    {
        self::start();
        $value = $_SESSION[self::KEY] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function setVault(string $name): void
    {
        self::start();
        $_SESSION[self::KEY] = $name;
    }

    private static function start(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `docker compose run --rm app vendor/bin/phpunit tests/SessionTest.php`
Expected: PASS.

- [ ] **Step 5: Switch the three page scripts and the smoke script**

`public/index.php` — replace the `use` block entry and line 15:

```php
use Esky\Client;
use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;
```

```php
    $vault = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $client = new Client($vault);
```

`public/view.php:35` — the same two lines, with the same `use` changes
(`Esky\Config` out, `Esky\Session` and `Esky\Vaults` in).

`public/metrics.php:22` — replace:

```php
    $config = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $api = new Api($config);
```

with `Esky\Config` dropped from the `use` block and `Esky\Session`,
`Esky\Vaults` added.

`bin/smoke.php` — take the vault name from the command line, defaulting to the
first:

```php
use Esky\Client;
use Esky\EskyException;
use Esky\Vaults;

try {
    $vaults = Vaults::load(dirname(__DIR__));
    $vault = $vaults->current($argv[1] ?? null);
    printf("vault: %s (%s)\n", $vault->title, $vault->url);

    $client = new Client($vault);
```

The rest of `bin/smoke.php` is unchanged.

- [ ] **Step 6: Point the navbar at `Vaults`**

In `src/Layout.php`, replace the `VAULT_TIP` constant and the `vault()` helper:

```php
    /** What the vault name in the navbar means, for anyone who has not met it. */
    private const VAULT_TIP =
        'Memory vault: the store these pages read. Configured in config.json.';
```

```php
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
```

and change the `$vault = self::vault();` line in `navbar()` to:

```php
        $vaults = self::vaults();
        $active = $vaults?->current(Session::vault());
        $vault = $active?->title ?? '';
```

The rest of `navbar()` is untouched for now — Task 4 replaces the trailing
`<span>` with the dropdown. Note this changes what the navbar shows from the
derived *profile* to the configured *title*.

- [ ] **Step 7: Rewrite `tests/ConfigTest.php`**

`Config` no longer reads the environment, so the file becomes a test of the
derivation only:

```php
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
```

- [ ] **Step 8: Point `tests/LayoutTest.php` at a config file**

`Layout::navbar()` reads the project's own `config.json`, so these two tests
assert on whatever is configured rather than on a fixed vault name, and skip
when the file is absent. Task 4 adds `Layout::navbarFor()`, which takes the
vaults explicitly and lets the rest of the navbar be tested against an
in-memory configuration with no file at all.

Replace `setUp`/`tearDown` and the first two test methods with:

```php
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
```

Delete `testTheVaultNameIsEscaped` and `testANavbarWithNoVaultToNameStillRenders`
for now — Task 4 reinstates both against the dropdown markup, where they can be
driven without environment variables. Keep
`testTheHeadLoadsTheVendoredBootstrapAndNoCdn` and `testTheTitleIsEscaped` as
they are.

- [ ] **Step 9: Create a working `config.json`**

```bash
cp config.json.example config.json
```

Then edit it with the real URL and token from the old `.env` (both vaults, if
the work vault's token is to hand; one entry is enough to proceed).

- [ ] **Step 10: Remove the dead variables from `.env`**

Delete the `ESKY_URL` and `ESKY_TOKEN` lines from `.env` and from
`.env.example`. `HTTP_APP_PORT`, `UID` and `GID` stay.

- [ ] **Step 11: Run the full suite**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: PASS, all files.

- [ ] **Step 12: Check the app still serves**

Run: `docker compose up -d` then open `http://localhost:8080`.
Expected: the list renders and the navbar shows the vault's *title*.

- [ ] **Step 13: Commit**

```bash
git add src/Session.php src/Layout.php public/index.php public/view.php public/metrics.php bin/smoke.php tests/ConfigTest.php tests/LayoutTest.php tests/SessionTest.php .env.example
git commit -m "feat: read the vault the session selected"
```

---

### Task 3: Vendor Bootstrap's JavaScript bundle

**Files:**
- Create: `public/vendor/bootstrap.bundle.min.js`
- Modify: `src/Layout.php` (`head()`)
- Modify: `tests/LayoutTest.php` (extend the vendored-asset test)
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `Layout::head()` emits `<script src="/vendor/bootstrap.bundle.min.js" defer></script>`.

This reverses the project's earlier "no Bootstrap JavaScript" decision. The
reason for that decision — the LAN this is read on need not have a route to the
internet — is unaffected, because the bundle is vendored exactly as the
stylesheet is and no CDN is contacted.

- [ ] **Step 1: Write the failing test**

In `tests/LayoutTest.php`, replace `testTheHeadLoadsTheVendoredBootstrapAndNoCdn`:

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose run --rm app vendor/bin/phpunit tests/LayoutTest.php`
Expected: FAIL on both new assertions — the script tag is absent and the file
does not exist.

- [ ] **Step 3: Download the bundle**

Run on the **host** (it needs the internet; the container may not have it), at
the same version as the vendored stylesheet, 5.3.3:

```bash
curl -sSLo public/vendor/bootstrap.bundle.min.js \
  https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js
head -c 120 public/vendor/bootstrap.bundle.min.js
```

Expected: the header comment names Bootstrap v5.3.3.

- [ ] **Step 4: Load it from `head()`**

In `src/Layout.php`, extend the heredoc in `head()` and its docblock:

```php
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
```

- [ ] **Step 5: Run the tests**

Run: `docker compose run --rm app vendor/bin/phpunit tests/LayoutTest.php`
Expected: PASS.

- [ ] **Step 6: Record the reversal in CLAUDE.md**

In the Architecture section, replace the sentence beginning "No CDN and no
Bootstrap JavaScript" with:

```markdown
No CDN: this is read on a LAN that need not have a route to the internet.
Bootstrap's JavaScript bundle is vendored alongside the stylesheet, for the
navbar's two dropdowns (the settings menu and the vault selector) — that is
the only thing here that uses it. The detail page's tabs remain hidden radio
buttons and the vault tooltip remains a native `title` attribute; converting
them is possible now but has not been done.
```

- [ ] **Step 7: Commit**

```bash
git add public/vendor/bootstrap.bundle.min.js src/Layout.php tests/LayoutTest.php CLAUDE.md
git commit -m "build: vendor Bootstrap's JavaScript bundle for the navbar dropdowns"
```

---

### Task 4: Switch vaults from the navbar

**Files:**
- Create: `public/vault.php`
- Modify: `src/Layout.php` (`navbar()`, `NAV`)
- Test: `tests/LayoutTest.php`, `tests/VaultSwitchTest.php`

**Interfaces:**
- Consumes: `Vaults`, `Session`, `Config::$name`, `Config::$title` from Tasks 1–2.
- Produces:
  - `Esky\Layout::backTarget(?string $raw): string` — the whitelist used by `vault.php`.
  - `public/vault.php?to=<name>&back=<path>` — sets the session, redirects.

- [ ] **Step 1: Write the failing tests**

Create `tests/VaultSwitchTest.php`:

```php
<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Layout;
use PHPUnit\Framework\TestCase;

final class VaultSwitchTest extends TestCase
{
    /* back comes straight off the query string, so only the two pages that
       mean anything after a switch are honoured. view.php is deliberately not
       among them: a uid belongs to one vault, so switching lands on the list. */
    public function testOnlyTheListAndMetricsAreAcceptedAsReturnTargets(): void
    {
        self::assertSame('/index.php', Layout::backTarget('/index.php'));
        self::assertSame('/metrics.php', Layout::backTarget('/metrics.php'));
    }

    public function testAnythingElseFallsBackToTheList(): void
    {
        foreach ([
            null,
            '',
            '/view.php?uid=abc',
            '/settings.php',
            'https://evil.test/',
            '//evil.test/',
            '/index.php?q=x',
            '../../etc/passwd',
        ] as $raw) {
            self::assertSame('/index.php', Layout::backTarget($raw), var_export($raw, true));
        }
    }
}
```

In `tests/LayoutTest.php`, add:

```php
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
        $html = Layout::navbar('/index.php');

        self::assertSame(1, substr_count($html, 'dropdown-item active'));
    }

    public function testTheSwitchLinksCarryThePageToReturnTo(): void
    {
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose run --rm app vendor/bin/phpunit tests/VaultSwitchTest.php tests/LayoutTest.php`
Expected: FAIL — `Layout::backTarget()` and `Layout::navbarFor()` do not exist.

- [ ] **Step 3: Rewrite `Layout::navbar()`**

`navbar()` becomes a thin wrapper so the markup can be tested against a
`Vaults` built in memory, without a file on disk:

```php
    /** The pages the vault selector may return to after a switch. */
    private const SWITCHABLE = ['/index.php', '/metrics.php'];

    public static function navbar(string $here = ''): string
    {
        return self::navbarFor(self::vaults(), $here);
    }

    /**
     * $vaults is null when the configuration will not load, which is the error
     * page's case: the chrome still renders, it simply names no vault.
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

        return <<<HTML
        <nav class="navbar navbar-expand bg-body-tertiary border-bottom mb-4">
            <div class="container">
                <a class="navbar-brand d-flex align-items-center gap-2" href="/index.php">
                    <span aria-hidden="true">🧊</span> Esky
                </a>
                {$menu}
                <ul class="navbar-nav me-auto">{$links}</ul>
                {$selector}
            </div>
        </nav>
        HTML;
    }
```

Both `$menu` and `$selector` are built before that `return`, immediately after
the `foreach` that builds `$links`:

```php
        $menu = <<<HTML
        <div class="dropdown me-3">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu">☰</button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="/settings.php">Settings</a></li>
                <li><a class="dropdown-item" href="/about.php">About</a></li>
            </ul>
        </div>
        HTML;

        $selector = '';
        if ($vaults !== null) {
            $active = $vaults->current(Session::vault());
            $back = self::backTarget($here);

            $items = '';
            foreach ($vaults->all() as $vault) {
                $items .= sprintf(
                    '<li><a class="dropdown-item%s" href="/vault.php?to=%s&amp;back=%s">%s%s</a></li>',
                    $vault->name === $active->name ? ' active' : '',
                    Page::e(rawurlencode($vault->name)),
                    Page::e(rawurlencode($back)),
                    Page::e($vault->title),
                    $vault->name === $active->name ? ' ✓' : ''
                );
            }

            $selector = sprintf(
                '<div class="dropdown vault-menu">'
                . '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"'
                . ' data-bs-toggle="dropdown" aria-expanded="false" title="%s">%s</button>'
                . '<ul class="dropdown-menu dropdown-menu-end">%s</ul></div>',
                Page::e(self::VAULT_TIP),
                Page::e($active->title),
                $items
            );
        }
```

and the whitelist:

```php
    /**
     * back comes off the query string, so only the pages a switch makes sense
     * on are honoured. Anything else — including a detail page, whose uid
     * belongs to one vault — lands on the list.
     */
    public static function backTarget(?string $raw): string
    {
        return in_array($raw, self::SWITCHABLE, true) ? $raw : '/index.php';
    }
```

Delete the old `$vault` / `$trailing` block and the `self::vault()` method.

- [ ] **Step 4: Write `public/vault.php`**

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\EskyException;
use Esky\Layout;
use Esky\Session;
use Esky\Vaults;

/**
 * Sets the vault the session reads and sends the visitor back. Nothing is
 * rendered here — the chosen vault lives in the session, never in a url, so
 * this is the only place it is written.
 */
try {
    $vaults = Vaults::load(dirname(__DIR__));
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}

$to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
if ($vaults->get($to) !== null) {
    Session::setVault($to);
}

$back = Layout::backTarget(isset($_GET['back']) ? (string) $_GET['back'] : null);

header('Location: ' . $back, true, 302);
exit;
```

- [ ] **Step 5: Style the tick**

Append to `public/style.css`:

```css
.vault-menu .dropdown-item.active { background: var(--bs-secondary-bg); color: var(--bs-body-color); }
```

- [ ] **Step 6: Run the tests**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Check it by hand**

With two vaults in `config.json`, `docker compose up -d`, open
`http://localhost:8080`, pick the other vault from the top-right dropdown.
Expected: the list reloads showing that vault's memories, the dropdown label
changes, the tick moves, and the url stays `/index.php`. Switch again from
`/metrics.php` and confirm you stay on metrics.

- [ ] **Step 8: Commit**

```bash
git add src/Layout.php public/vault.php public/style.css tests/LayoutTest.php tests/VaultSwitchTest.php
git commit -m "feat: select the active vault from the navbar"
```

---

### Task 5: Probe a vault and mask its token

**Files:**
- Create: `src/Health.php`
- Modify: `src/Client.php` (add `ping()`)
- Modify: `src/Page.php` (add `mask()`)
- Test: `tests/HealthTest.php`, `tests/PageTest.php`

**Interfaces:**
- Consumes: `Vaults`, `Config` from Task 1.
- Produces:
  - `Esky\Client::ping(): void` — throws `EskyException` when the vault cannot be reached.
  - `Esky\Page::mask(string $token): string`
  - `Esky\Health::check(Vaults $vaults, ?\Closure $probe = null, int $timeout = 2): list<array{name: string, title: string, url: string, profile: ?string, token: string, ok: bool, message: string}>`

- [ ] **Step 1: Write the failing tests**

Add to `tests/PageTest.php`:

```php
    /* Tokens are shown on the settings page so an operator can tell which one
       a vault is using, without the page handing the whole secret to anyone
       who can reach the port. */
    public function testALongTokenKeepsItsEndsOnly(): void
    {
        self::assertSame('sk-…f3a2', Page::mask('sk-personal-0000f3a2'));
    }

    public function testAShortTokenIsHiddenEntirely(): void
    {
        self::assertSame('…', Page::mask('short'));
        self::assertSame('…', Page::mask('elevenchars'));
    }

    public function testAnEmptyTokenIsHiddenEntirely(): void
    {
        self::assertSame('…', Page::mask(''));
    }
```

Create `tests/HealthTest.php`:

```php
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose run --rm app vendor/bin/phpunit tests/HealthTest.php tests/PageTest.php`
Expected: FAIL — `Esky\Health` not found, `Page::mask()` not defined.

- [ ] **Step 3: Add `Page::mask()`**

In `src/Page.php`:

```php
    /**
     * A token shown for recognition, not for use: the ends only. Anything too
     * short to hide meaningfully is hidden completely rather than half
     * revealed.
     */
    public static function mask(string $token): string
    {
        return mb_strlen($token) < 12
            ? '…'
            : mb_substr($token, 0, 3) . '…' . mb_substr($token, -4);
    }
```

- [ ] **Step 4: Add `Client::ping()`**

In `src/Client.php`, below `search()`:

```php
    /**
     * The cheapest call that proves a vault is reachable and the token is
     * accepted: the handshake alone, with no tools/call behind it.
     */
    public function ping(): void
    {
        $this->handshake();
    }
```

- [ ] **Step 5: Write `src/Health.php`**

```php
<?php
declare(strict_types=1);

namespace Esky;

/**
 * Reduces each configured vault to a row the settings page renders.
 *
 * The probe is injectable for the same reason Api's transport is: the suite
 * must not open a socket. Tokens are masked here rather than in the view, so
 * no caller can render one whole by accident.
 */
final class Health
{
    private const TIMEOUT = 2;

    /**
     * @param ?\Closure(Config): void $probe
     * @return list<array{name: string, title: string, url: string, profile: ?string, token: string, ok: bool, message: string}>
     */
    public static function check(Vaults $vaults, ?\Closure $probe = null, int $timeout = self::TIMEOUT): array
    {
        /* A short timeout: this page waits on every vault in turn, and an
           unreachable one must not hold the others up for the full 30s the
           memory pages allow. */
        $probe ??= static fn (Config $config) => (new Client($config, $timeout))->ping();

        $rows = [];
        foreach ($vaults->all() as $vault) {
            $ok = true;
            $message = '';
            try {
                $probe($vault);
            } catch (EskyException $e) {
                $ok = false;
                $message = $e->getMessage();
            }

            $rows[] = [
                'name' => $vault->name,
                'title' => $vault->title,
                'url' => $vault->url,
                'profile' => $vault->profile,
                'token' => Page::mask($vault->token),
                'ok' => $ok,
                'message' => $message,
            ];
        }

        return $rows;
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Health.php src/Client.php src/Page.php tests/HealthTest.php tests/PageTest.php
git commit -m "feat: probe each vault and mask its token"
```

---

### Task 6: Settings and About pages

**Files:**
- Create: `public/settings.php`
- Create: `public/about.php`
- Modify: `public/style.css`

**Interfaces:**
- Consumes: `Health::check()`, `Vaults::load()`, `Session::vault()`, `Layout::head()`, `Layout::navbar()`, `Page::e()`.
- Produces: nothing other tasks consume.

Both pages render from data the earlier tasks already cover with tests. The
markup itself is checked by eye in Step 4 — there is no HTTP test, because the
suite may not open a socket.

- [ ] **Step 1: Write `public/settings.php`**

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\EskyException;
use Esky\Health;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;

/**
 * A read-only view of config.json. There is no form: the app has no users and
 * no authentication, so vaults are edited in the file on the host.
 */
try {
    $vaults = Vaults::load(dirname(__DIR__));
    $active = $vaults->current(Session::vault())->name;
    $rows = Health::check($vaults);
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky — settings') ?>
<body>
<?= Layout::navbar() ?>
<main class="container pb-5">
    <h1>Settings</h1>
    <p class="text-body-secondary small">
        Vaults are configured in <code>config.json</code> in the project root.
        Edit that file and reload this page; nothing here writes to it.
    </p>

    <table class="table table-sm align-middle">
        <thead>
            <tr>
                <th scope="col">Vault</th>
                <th scope="col">MCP endpoint</th>
                <th scope="col">Profile</th>
                <th scope="col">Token</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td>
                    <strong><?= Page::e($row['title']) ?></strong>
                    <?php if ($row['name'] === $active): ?>
                        <span class="badge text-bg-secondary ms-1">active</span>
                    <?php endif; ?>
                    <div class="text-body-secondary small"><?= Page::e($row['name']) ?></div>
                </td>
                <td class="small"><code><?= Page::e($row['url']) ?></code></td>
                <td class="small"><?= Page::e($row['profile'] ?? '—') ?></td>
                <td class="small"><code><?= Page::e($row['token']) ?></code></td>
                <td class="small">
                    <?php if ($row['ok']): ?>
                        <span class="health ok">●</span> reachable
                    <?php else: ?>
                        <span class="health bad">●</span> unreachable
                        <div class="text-body-secondary"><?= Page::e($row['message']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
```

- [ ] **Step 2: Write `public/about.php`**

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Vaults;

try {
    $vaults = Vaults::load(dirname(__DIR__));
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky — about') ?>
<body>
<?= Layout::navbar() ?>
<main class="container pb-5">
    <h1>About</h1>
    <p>
        A read-only browser for the Esky memory server. It lists and searches
        memories, shows one in full, and charts the store's aggregates. It
        never writes, updates or retires a memory.
    </p>
    <p class="text-body-secondary small">
        Pages read one vault at a time — the one chosen in the top-right menu.
        The search is semantic rather than literal, so an empty result is not a
        reliable “no match”.
    </p>

    <h2 class="h5 mt-4">Vaults</h2>
    <ul>
    <?php foreach ($vaults->all() as $vault): ?>
        <li>
            <strong><?= Page::e($vault->title) ?></strong>
            — <code class="small"><?= Page::e($vault->url) ?></code>
        </li>
    <?php endforeach; ?>
    </ul>
    <p class="small"><a href="/settings.php">How these are configured</a></p>
</main>
</body>
</html>
```

- [ ] **Step 3: Style the health dots**

Append to `public/style.css`:

```css
.health { font-size: .75rem; line-height: 1; }
.health.ok { color: var(--bs-success); }
.health.bad { color: var(--bs-danger); }
```

- [ ] **Step 4: Check both pages by hand**

`docker compose up -d`, then from the ☰ menu open Settings and About.
Expected on Settings: one row per vault, the active one badged, the token shown
as `sk-…f3a2`, and a green dot per reachable vault. Break one vault's token in
`config.json` and reload: that row goes red with the server's message, and the
others still report.

- [ ] **Step 5: Run the suite**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add public/settings.php public/about.php public/style.css
git commit -m "feat: add settings and about pages"
```

---

### Task 7: Document the vault configuration

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Rewrite the README's Setup and Configuration sections**

Replace the Setup body and the whole Configuration table:

```markdown
## Setup

    cp .env.example .env
    cp config.json.example config.json

Put your esky vaults in `config.json` — one entry per memory server, each with
its MCP endpoint and bearer token. Then:

    docker compose build
    docker compose run --rm app composer install
    docker compose up -d

The app is served on `http://localhost:8080` by default; change `HTTP_APP_PORT`
in `.env` to move it.

## Configuration

Vaults live in `config.json` in the project root, which is git-ignored because
it holds bearer tokens:

```json
{
  "vaults": [
    {
      "name": "personal",
      "title": "Personal",
      "url": "http://host:8011/mcp/personal",
      "token": "…"
    }
  ]
}
```

| Field | Purpose |
|---|---|
| `name` | Slug held in the session and used in the switch links. Lowercase letters, digits, `-` and `_` |
| `title` | The label the navbar and settings page show |
| `url` | esky MCP endpoint, e.g. `http://host:8011/mcp/personal` |
| `token` | Bearer token, without the `Bearer ` prefix |
| `apiUrl` | Optional. REST base, e.g. `http://host:8011`; derived from `url` when unset |
| `profile` | Optional. Profile to read metrics for; derived from `url` when unset |

The first vault in the list is the default. Switch with the menu at the top
right; the choice is held in the session, so it does not appear in any url.
The settings page lists what is configured and probes each vault.

`.env` holds only host-level settings:

| Variable | Purpose |
|---|---|
| `HTTP_APP_PORT` | Host port to publish, default `8080` |
| `UID` / `GID` | Container user ids, match your host user so bind-mounted files stay editable |
```

Also update the Layout section's `src/` line to name the new classes:

```
    src/        Config, Vaults, Session, SseParser, ResponseDecoder, Client,
                Api, Health, Chart, Markdown, Page, Layout
    public/     index.php (list and search), view.php (detail),
                metrics.php (charts), settings.php, about.php, vault.php
```

and the line about the derived profile ("…derived from `ESKY_URL`, so an
`ESKY_URL` without a…") to say `url` in `config.json` instead.

- [ ] **Step 2: Update CLAUDE.md**

Replace the `Config::fromEnvironment()` paragraph in the Architecture section:

```markdown
`Vaults::load()` reads `config.json` from the project root — a git-ignored file
holding one entry per esky vault — and hands out a `Config` per vault, so
`Client` and `Api` still take a single `Config` and know nothing about vaults.
Vaults are configured in a file rather than through the app because the app has
no users and no authentication: a form storing bearer tokens would be writable
by anyone who could reach the port. `Session` is the only file that touches
`$_SESSION`; it holds the active vault's name and nothing else, which keeps
`Vaults` testable under CLI. A missing or malformed file throws
`EskyException`, which the page scripts catch and hand to `Layout::error()`.

`Layout::navbar()` resolves the vaults itself rather than taking them as an
argument, so a page cannot render the navbar and leave the vault unnamed;
`navbarFor()` takes them explicitly so the markup can be tested without a file
on disk. `vault.php` writes the session and redirects — the chosen vault never
appears in a url, and `Layout::backTarget()` whitelists where a switch may
return to.

`Health::check()` reduces each vault to a row for the settings page, masking
the token with `Page::mask()` so no caller can render one whole by accident.
Its probe is injectable for the same reason `Api`'s transport is — the suite
may not open a socket. `Client::ping()` is the probe itself: the handshake
alone, no `tools/call`.
```

Also update the Commands section: `bin/smoke.php` now takes an optional vault
name (`php bin/smoke.php work`) and reads `config.json`, not `ESKY_TOKEN`.

- [ ] **Step 3: Run the suite one last time**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: PASS, every file.

- [ ] **Step 4: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: describe the vault configuration"
```

---

## Notes on deviations from the spec

Two things changed while planning; the spec has been amended to match.

1. **Session handling moved out of `Vaults`.** The spec had
   `Vaults::current()` calling `session_start()` itself. Starting a session
   inside the loader makes it untestable under CLI and couples configuration
   loading to request state. A separate `Session` class owns `$_SESSION`, and
   `current()` takes the name as an argument.

2. **No HTTP test that `config.json` is unexposed.** The spec asked for a
   serving test asserting on the response body. That test would have to open a
   socket, which the suite forbids. `VaultsTest` instead asserts the file
   resolves outside `public/`, which is the property that actually keeps it
   unserved; the body-versus-status check stays a manual step.

# Forget and Search Stats Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Forget button (CSRF-protected, with a confirm page) that calls esky's `memory_forget`, and a compact stats strip above search results.

**Architecture:** `Client` gains an injectable transport (so it can be tested offline, as `Api` is) and a `forget()` method. A new `Csrf` class keeps its token through `Session`. `public/forget.php` is a GET confirm page and a POST action. `Stats` is a pure class that summarises a result list into badge labels.

**Tech Stack:** PHP 8.4, PHPUnit, Bootstrap 5.3 (vendored), FrankenPHP in Docker.

**Spec:** `docs/superpowers/specs/2026-10-03-forget-and-search-stats-design.md`

## Global Constraints

- PHP 8.4, `declare(strict_types=1)` in every file, `final` classes, constructor property promotion with `readonly`.
- Comments explain *why*, not what.
- The PHPUnit suite is offline-only: no test may open a socket.
- Everything runs in the container: `docker compose run --rm app vendor/bin/phpunit`.
- `Session` is the only file that touches `$_SESSION`.
- A GET must never change state; only the POST forgets.
- The reason is optional, trimmed, capped at 500 characters.
- Score is shown as a min–max range, never as a percentage.
- Commits are Conventional Commits, one logical layer per commit, ending with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- `_notes/` is git-ignored and must never be committed.
- Leave the uncommitted change in `docker-compose.yml` alone; never `git add -A`.

## Review Focus

- A GET to `forget.php?uid=…` must not forget anything (checked by hand in Task 4).
- A forged or stale uid on POST must be refused, not forwarded to esky (Task 4, by hand).
- A reason containing HTML, or longer than 500 multibyte characters, must be trimmed and escaped, never break the page (Task 3 test for the cap; escaping goes through `Page::e`).
- A session cookie holding a non-string CSRF token must fail verification, not throw (Task 2 test).
- Search records with no `score`, no `layer`, or `tags` that is not an array must not break the strip (Task 5 tests).
- esky answering a forget with plain text, or with `isError`, must be handled as success or a shown error respectively (Task 1 tests).

---

### Task 1: `Client::forget()` and an injectable transport

**Files:**
- Modify: `src/Client.php`
- Modify: `src/ResponseDecoder.php`
- Create: `tests/ClientTest.php`
- Modify: `tests/ResponseDecoderTest.php` (append a test)

**Interfaces:**
- Produces: `Client::__construct(Config $config, int $timeout = 30, ?\Closure $transport = null)`; the transport has the signature `(array $payload, ?string $sessionId): array{body: string, sessionId: ?string}`.
- Produces: `Client::forget(string $uid, ?string $reason): void`; throws `EskyException` on a JSON-RPC error or an `isError` result.
- Produces: `ResponseDecoder::acknowledge(array $message): void`.

- [ ] **Step 1: Check who constructs `Client`**

Run: `grep -rn "new Client(" src public bin tests`
Expected: every call passes only a `Config` (or a `Config` and a timeout). If one passes a third positional argument, fix it before continuing.

- [ ] **Step 2: Write the failing tests**

Create `tests/ClientTest.php`:

```php
<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Client;
use Esky\Config;
use Esky\EskyException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private function client(string $toolBody): Client
    {
        $this->sent = [];

        return new Client(
            new Config('personal', 'Personal', 'http://esky.test/mcp/personal', 'tok'),
            transport: function (array $payload, ?string $sessionId) use ($toolBody): array {
                $this->sent[] = $payload;
                $method = $payload['method'] ?? '';

                if ($method === 'initialize') {
                    return ['body' => '', 'sessionId' => 'sess-1'];
                }
                if ($method === 'tools/call') {
                    self::assertSame('sess-1', $sessionId);

                    return ['body' => "event: message\ndata: " . $toolBody . "\n\n", 'sessionId' => null];
                }

                return ['body' => '', 'sessionId' => null];
            }
        );
    }

    private function toolCall(): array
    {
        foreach ($this->sent as $payload) {
            if (($payload['method'] ?? '') === 'tools/call') {
                return $payload['params'];
            }
        }
        self::fail('No tools/call was sent.');
    }

    public function testForgetSendsTheUidAndReason(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"content":[]}}');

        $client->forget('abc123', 'out of date');

        self::assertSame(
            ['name' => 'memory_forget', 'arguments' => ['uid' => 'abc123', 'reason' => 'out of date']],
            $this->toolCall()
        );
    }

    public function testForgetWithoutAReasonSendsNull(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"content":[]}}');

        $client->forget('abc123', null);

        self::assertNull($this->toolCall()['arguments']['reason']);
    }

    /* The forget response shape is not documented; plain text must not be
       mistaken for a malformed record list. */
    public function testForgetAcceptsAPlainTextAcknowledgement(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"Forgotten."}]}}');

        $client->forget('abc123', null);

        $this->addToAssertionCount(1);
    }

    public function testForgetSurfacesAToolError(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"isError":true,"content":[{"type":"text","text":"no such memory"}]}}');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessage('no such memory');

        $client->forget('abc123', null);
    }

    public function testForgetSurfacesAJsonRpcError(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"error":{"code":-32602,"message":"bad uid"}}');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessage('bad uid');

        $client->forget('abc123', null);
    }

    public function testRecentStillDecodesRecordsThroughTheTransport(): void
    {
        $inner = json_encode([['uid' => 'a', 'updated_at' => '2026-01-01T00:00:00+00:00']]);
        $client = $this->client(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['content' => [['type' => 'text', 'text' => $inner]]],
        ]));

        self::assertSame('a', $client->recent(5)[0]['uid']);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose run --rm app vendor/bin/phpunit tests/ClientTest.php`
Expected: FAIL — `Unknown named parameter $transport`.

- [ ] **Step 4: Add `ResponseDecoder::acknowledge()`**

In `src/ResponseDecoder.php`, replace the `isset($message['error'])` block at the top of `records()` with a call to a shared private method, and add the new public method:

```php
    /** @return list<array<string, mixed>> */
    public static function records(array $message): array
    {
        self::throwIfError($message);

        if (!isset($message['result']) || !is_array($message['result'])) {
            throw new EskyException('Esky response contained no result');
        }
        // ... the rest of records() is unchanged
    }

    /**
     * For a tool whose reply is a confirmation rather than records: only a
     * failure is worth reading, and the text of a success is ignored.
     */
    public static function acknowledge(array $message): void
    {
        self::throwIfError($message);

        $result = $message['result'] ?? null;
        if (!is_array($result)) {
            throw new EskyException('Esky response contained no result');
        }

        if (($result['isError'] ?? false) === true) {
            $text = $result['content'][0]['text'] ?? null;
            throw new EskyException('Esky refused the request: ' . (is_string($text) ? $text : 'unknown error'));
        }
    }

    private static function throwIfError(array $message): void
    {
        if (isset($message['error'])) {
            $text = $message['error']['message'] ?? 'unknown error';
            $code = $message['error']['code'] ?? 0;
            throw new EskyException(sprintf('Esky returned an error (%s): %s', $code, $text));
        }
    }
```

- [ ] **Step 5: Make the transport injectable and add `forget()`**

In `src/Client.php`:

Constructor and property:

```php
    /** @var \Closure(array, ?string): array{body: string, sessionId: ?string} */
    private readonly \Closure $transport;

    public function __construct(
        private readonly Config $config,
        private readonly int $timeout = 30,
        ?\Closure $transport = null,
    ) {
        // Injectable so the suite can exercise the protocol without a socket,
        // the same way Api's transport is.
        $this->transport = $transport ?? $this->curl(...);
    }
```

Add the public method after `ping()`:

```php
    /**
     * Retires a memory. esky keeps it, so this hides rather than destroys;
     * the reason is recorded server-side.
     */
    public function forget(string $uid, ?string $reason): void
    {
        ResponseDecoder::acknowledge($this->request('memory_forget', ['uid' => $uid, 'reason' => $reason]));
    }
```

Split `call()` so the message can be read without decoding it as records:

```php
    /** @return list<array<string, mixed>> */
    private function call(string $tool, array $arguments): array
    {
        return ResponseDecoder::records($this->request($tool, $arguments));
    }

    /** @return array<string, mixed> the first SSE message of the reply */
    private function request(string $tool, array $arguments): array
    {
        $this->handshake();

        $response = $this->post([
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        $messages = SseParser::messages($response['body']);
        if ($messages === []) {
            throw new EskyException('Esky returned no parsable message for ' . $tool);
        }

        return $messages[0];
    }
```

Turn the old `post()` into the default transport, and make `post()` delegate:

```php
    /** @return array{body: string, sessionId: ?string} */
    private function post(array $payload): array
    {
        return ($this->transport)($payload, $this->sessionId);
    }

    /** @return array{body: string, sessionId: ?string} */
    private function curl(array $payload, ?string $sessionId): array
    {
        // body is the previous post() body, with $this->sessionId replaced by $sessionId
    }
```

Move the existing curl body of `post()` into `curl()` unchanged except for that one substitution.

- [ ] **Step 6: Append a decoder test**

In `tests/ResponseDecoderTest.php`, add inside the class:

```php
    public function testAcknowledgeIgnoresTheTextOfASuccess(): void
    {
        ResponseDecoder::acknowledge(['result' => ['content' => [['type' => 'text', 'text' => 'not json']]]]);

        $this->addToAssertionCount(1);
    }
```

If the file does not already `use Esky\ResponseDecoder;`, add it.

- [ ] **Step 7: Run the whole suite**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: all tests pass, including the six new `ClientTest` cases.

- [ ] **Step 8: Commit**

```bash
git add src/Client.php src/ResponseDecoder.php tests/ClientTest.php tests/ResponseDecoderTest.php
git commit -m "feat: add Client::forget and an injectable transport

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `Csrf` and the session token

**Files:**
- Modify: `src/Session.php`
- Create: `src/Csrf.php`
- Create: `tests/CsrfTest.php`
- Modify: `tests/SessionTest.php` (append a test)

**Interfaces:**
- Produces: `Session::csrfToken(): ?string`, `Session::setCsrfToken(string $token): void`.
- Produces: `Csrf::token(): string` (creates one on first use, stable within a session), `Csrf::verify(mixed $submitted): bool`.

- [ ] **Step 1: Write the failing tests**

Create `tests/CsrfTest.php`:

```php
<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testATokenIsStableWithinASession(): void
    {
        self::assertSame(Csrf::token(), Csrf::token());
        self::assertSame(64, strlen(Csrf::token()));
    }

    public function testTheIssuedTokenVerifies(): void
    {
        self::assertTrue(Csrf::verify(Csrf::token()));
    }

    public function testWrongEmptyAndMissingValuesAreRejected(): void
    {
        Csrf::token();

        self::assertFalse(Csrf::verify('nope'));
        self::assertFalse(Csrf::verify(''));
        self::assertFalse(Csrf::verify(null));
        self::assertFalse(Csrf::verify(['x']));
    }

    /* A form can be posted before the visitor was ever issued a token, e.g.
       with a stale cookie; empty must not equal empty. */
    public function testNothingVerifiesBeforeATokenWasIssued(): void
    {
        self::assertFalse(Csrf::verify(''));
        self::assertFalse(Csrf::verify('anything'));
    }

    public function testATamperedNonStringSessionValueFailsClosed(): void
    {
        $_SESSION['esky_csrf'] = ['abc'];

        self::assertFalse(Csrf::verify('abc'));
    }
}
```

Append to `tests/SessionTest.php` inside the class:

```php
    public function testTheCsrfTokenIsRememberedAndNonStringsAreIgnored(): void
    {
        self::assertNull(Session::csrfToken());

        Session::setCsrfToken('t');
        self::assertSame('t', Session::csrfToken());

        $_SESSION['esky_csrf'] = ['t'];
        self::assertNull(Session::csrfToken());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose run --rm app vendor/bin/phpunit tests/CsrfTest.php tests/SessionTest.php`
Expected: FAIL — `Class "Esky\Csrf" not found` / undefined `Session::csrfToken`.

- [ ] **Step 3: Add the session accessors**

In `src/Session.php`, add a constant and two methods (keep `vault()`/`setVault()` as they are):

```php
    private const CSRF_KEY = 'esky_csrf';

    public static function csrfToken(): ?string
    {
        self::start();
        $value = $_SESSION[self::CSRF_KEY] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function setCsrfToken(string $token): void
    {
        self::start();
        $_SESSION[self::CSRF_KEY] = $token;
    }
```

Update the class docblock's first line to: `The active vault name and the CSRF token, held in the session and nowhere else.`

- [ ] **Step 4: Write `Csrf`**

Create `src/Csrf.php`:

```php
<?php
declare(strict_types=1);

namespace Esky;

/**
 * One random token per session, embedded in the forget form and checked on
 * POST. The app has no login, so this is what stops another page from
 * submitting the form on a visitor's behalf.
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::csrfToken();
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            Session::setCsrfToken($token);
        }

        return $token;
    }

    /** Takes mixed because the value comes straight from $_POST. */
    public static function verify(mixed $submitted): bool
    {
        $expected = Session::csrfToken();

        return $expected !== null
            && is_string($submitted)
            && $submitted !== ''
            && hash_equals($expected, $submitted);
    }
}
```

- [ ] **Step 5: Run the suite**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Csrf.php src/Session.php tests/CsrfTest.php tests/SessionTest.php
git commit -m "feat: add a session-backed CSRF token

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Shared helpers — `Page::find`, `Page::reason`, error status

**Files:**
- Modify: `src/Page.php`
- Modify: `src/Layout.php` (`error()` only)
- Modify: `public/view.php` (use `Page::find`)
- Create: `tests/PageForgetTest.php`

**Interfaces:**
- Produces: `Page::find(array $records, string $uid): ?array`.
- Produces: `Page::reason(string $raw, int $max = 500): ?string` — trimmed, capped in characters, `null` when empty.
- Produces: `Layout::error(string $message, int $status = 500): never`.

- [ ] **Step 1: Write the failing tests**

Create `tests/PageForgetTest.php`:

```php
<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Page;
use PHPUnit\Framework\TestCase;

final class PageForgetTest extends TestCase
{
    public function testFindReturnsTheRecordWithThatUid(): void
    {
        $records = [['uid' => 'a', 'n' => 1], ['uid' => 'b', 'n' => 2]];

        self::assertSame(['uid' => 'b', 'n' => 2], Page::find($records, 'b'));
    }

    public function testFindReturnsNullForAnUnknownUid(): void
    {
        self::assertNull(Page::find([['uid' => 'a']], 'zzz'));
        self::assertNull(Page::find([], 'a'));
    }

    public function testFindIgnoresRecordsWithoutAUid(): void
    {
        self::assertNull(Page::find([['title' => 'x']], ''));
    }

    public function testReasonIsTrimmed(): void
    {
        self::assertSame('stale', Page::reason("  stale \n"));
    }

    public function testAnEmptyReasonIsNull(): void
    {
        self::assertNull(Page::reason(''));
        self::assertNull(Page::reason("   \n\t"));
    }

    public function testReasonIsCappedInCharactersNotBytes(): void
    {
        $reason = Page::reason(str_repeat('é', 600));

        self::assertSame(500, mb_strlen((string) $reason));
    }

    /* Escaping is the page's job, not the helper's: the text must come back
       untouched so Page::e sees the real characters. */
    public function testReasonDoesNotAlterMarkup(): void
    {
        self::assertSame('<b>x</b>', Page::reason('<b>x</b>'));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose run --rm app vendor/bin/phpunit tests/PageForgetTest.php`
Expected: FAIL — `Call to undefined method Esky\Page::find()`.

- [ ] **Step 3: Add the helpers to `Page`**

In `src/Page.php`, add before `mask()`:

```php
    /**
     * esky has no get-by-uid tool, so a record is located by filtering a list
     * the caller already pulled.
     *
     * @param list<array<string, mixed>> $records
     */
    public static function find(array $records, string $uid): ?array
    {
        foreach ($records as $record) {
            if (($record['uid'] ?? null) === $uid) {
                return $record;
            }
        }

        return null;
    }

    /** The optional note sent with a forget: trimmed, capped, null when blank. */
    public static function reason(string $raw, int $max = 500): ?string
    {
        $text = trim($raw);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
```

- [ ] **Step 4: Let `Layout::error` take a status**

In `src/Layout.php`, change the signature and first line:

```php
    public static function error(string $message, int $status = 500): never
    {
        http_response_code($status);
```

The rest is unchanged.

- [ ] **Step 5: Use `Page::find` in `view.php`**

In `public/view.php`, delete the `$find` closure and its docblock, and replace its two uses:

```php
    if ($query !== '') {
        $memory = Page::find($client->search($query, 500), $uid);
    }
    if ($memory === null) {
        $memory = Page::find($client->recent(500), $uid);
    }
```

Keep the explanation of why a list is filtered: it now lives in `Page::find`'s docblock, so no comment is needed in `view.php`.

- [ ] **Step 6: Run the suite and check the page still renders**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: all pass.

Run: `docker compose up -d && curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:8080/view.php?uid=nope'`
Expected: `404` (the not-found page, unchanged behaviour).

- [ ] **Step 7: Commit**

```bash
git add src/Page.php src/Layout.php public/view.php tests/PageForgetTest.php
git commit -m "refactor: share the uid lookup and add a forget reason helper

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The Forget page, button, notice and `CLAUDE.md`

**Files:**
- Create: `public/forget.php`
- Modify: `public/view.php` (button)
- Modify: `public/index.php` (notice)
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: `Client::forget`, `Csrf::token`, `Csrf::verify`, `Page::find`, `Page::reason`, `Layout::error($message, $status)`.

No automated test: the logic is thin glue over tested units, and page scripts are not unit-testable in this codebase. Verification is by hand and is part of this task.

- [ ] **Step 1: Write `public/forget.php`**

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Esky\Client;
use Esky\Csrf;
use Esky\EskyException;
use Esky\Layout;
use Esky\Page;
use Esky\Session;
use Esky\Vaults;

/**
 * The only place the app writes. GET shows a confirm page and changes nothing;
 * the POST behind it forgets one memory. esky keeps a forgotten memory, so
 * this hides rather than destroys.
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    Layout::error('That method is not allowed here.', 405);
}

$uid = trim((string) (($method === 'POST' ? $_POST['uid'] ?? '' : $_GET['uid'] ?? '')));
if ($uid === '') {
    Layout::error('No memory id was given.', 400);
}

if ($method === 'POST' && !Csrf::verify($_POST['csrf'] ?? null)) {
    Layout::error('The form expired or was not valid. Go back and try again.', 403);
}

try {
    $vault = Vaults::load(dirname(__DIR__))->current(Session::vault());
    $client = new Client($vault);

    // Re-checked on POST too: a forged uid is refused here, not sent to esky.
    $memory = Page::find($client->recent(500), $uid);
    if ($memory === null) {
        Layout::error('No memory with that id was found.', 404);
    }

    if ($method === 'POST') {
        $client->forget($uid, Page::reason((string) ($_POST['reason'] ?? '')));
        header('Location: /index.php?forgotten=1', true, 303);
        exit;
    }
} catch (EskyException $e) {
    Layout::error($e->getMessage());
}

$back = '/view.php?uid=' . rawurlencode($uid);
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<?= Layout::head('Esky — forget a memory') ?>
<body>
<?= Layout::navbar('/index.php') ?>
<main class="container pb-5">
    <h1>Forget this memory?</h1>
    <p class="text-body-secondary small">
        <?= Page::e(Page::heading($memory)) ?> &middot; <?= Page::e((string) ($memory['kind'] ?? '')) ?>
    </p>
    <p><?= Page::e(Page::preview((string) ($memory['text'] ?? ''), 300)) ?></p>
    <p class="small">It will stop appearing in searches. esky keeps it, so it can be restored on the server.</p>

    <form method="post" action="/forget.php" class="d-grid gap-3" style="max-width: 32rem">
        <input type="hidden" name="uid" value="<?= Page::e($uid) ?>">
        <input type="hidden" name="csrf" value="<?= Page::e(Csrf::token()) ?>">
        <label class="form-label mb-0">
            Reason (optional)
            <textarea class="form-control mt-1" name="reason" rows="2" maxlength="500"></textarea>
        </label>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-danger">Forget</button>
            <a class="btn btn-outline-secondary" href="<?= Page::e($back) ?>">Cancel</a>
        </div>
    </form>
</main>
</body>
</html>
```

- [ ] **Step 2: Add the button to `public/view.php`**

Directly after the `<p class="text-body-secondary small">…kind… updated …</p>` line inside the `else` branch:

```php
    <?php if (empty($memory['retired_at'])): ?>
        <a class="btn btn-sm btn-outline-danger" href="/forget.php?uid=<?= Page::e(rawurlencode((string) $memory['uid'])) ?>">Forget</a>
    <?php endif; ?>
```

- [ ] **Step 3: Add the notice to `public/index.php`**

Directly after `<h1>Memories</h1>`:

```php
    <?php if (isset($_GET['forgotten'])): ?>
        <div class="alert alert-success" role="alert">Memory forgotten. It no longer appears in searches.</div>
    <?php endif; ?>
```

- [ ] **Step 4: Update `CLAUDE.md`**

Replace the bullet
`- Only \`memory_recent\` and \`memory_search\` are called. The app never writes, updates or retires a memory; keep it that way.`
with
`- \`memory_recent\` and \`memory_search\` read; the only write is \`memory_forget\`, called from \`public/forget.php\` behind a CSRF token and a confirm page. The app never writes or updates a memory; keep it that way.`

In the Architecture section, change "`Session` is the only file that touches `$_SESSION`; it holds the active vault's name and nothing else" to "it holds the active vault's name and the CSRF token, nothing else". After the `Layout` paragraph add one short paragraph naming `Csrf` (one token per session, checked on the forget POST) and `Stats` (summarises a search's results into the strip above the list; pure, no I/O). Also change "Three request paths" to "Four request paths" and add `public/forget.php` (confirm + forget) to the list. Do not touch the `_notes/` rule.

- [ ] **Step 5: Verify by hand**

This step forgets a real memory in a real vault, so use a throwaway. Write one with the esky `memory_write` tool (title `forget-test`, text `delete me`), note its uid, then:

```bash
docker compose up -d
# GET shows the form and changes nothing:
curl -s 'http://localhost:8080/forget.php?uid=<uid>' | grep -c 'Forget this memory'      # expect 1
curl -s 'http://localhost:8080/view.php?uid=<uid>' | grep -c 'forget-test'               # expect >=1 (still there)
# POST without a token is refused:
curl -s -o /dev/null -w '%{http_code}\n' -X POST -d 'uid=<uid>' http://localhost:8080/forget.php   # expect 403
# Wrong method:
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE 'http://localhost:8080/forget.php?uid=<uid>'    # expect 405
# Missing uid:
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/forget.php                           # expect 400
# Unknown uid:
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:8080/forget.php?uid=nope'                # expect 404
```

Then in a browser: open `http://localhost:8080/view.php?uid=<uid>`, click Forget, enter a reason, confirm. Expect a redirect to the list with the green notice. Confirm the memory is gone from `memory_recent`. Then reload the confirm URL directly: expect the 404 page, since it is no longer listed.

If the forget fails with an esky error, or esky answers in a shape `forget` misreads, stop and report the raw response rather than guessing.

- [ ] **Step 6: Run the suite**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add public/forget.php public/view.php public/index.php CLAUDE.md
git commit -m "feat: add a Forget button with a confirm page

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `Stats` and the strip on the results page

**Files:**
- Create: `src/Stats.php`
- Create: `tests/StatsTest.php`
- Modify: `public/index.php`

**Interfaces:**
- Produces: `Stats::summarise(array $records): array{count: int, scoreMin: ?float, scoreMax: ?float, kinds: array<string,int>, layers: array<string,int>, tags: array<string,int>, oldest: ?string, newest: ?string}` — `kinds`, `layers` and `tags` are ordered by count descending then name ascending; `tags` holds at most 5.
- Produces: `Stats::labels(array $summary): list<string>` — plain text, one per badge, unescaped; a part with no data yields no label.

- [ ] **Step 1: Write the failing tests**

Create `tests/StatsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function testEmptyInputSummarisesToNothing(): void
    {
        $s = Stats::summarise([]);

        self::assertSame(0, $s['count']);
        self::assertNull($s['scoreMin']);
        self::assertNull($s['oldest']);
        self::assertSame([], Stats::labels($s));
    }

    public function testCountsKindsLayersAndScoreRange(): void
    {
        $s = Stats::summarise([
            ['kind' => 'decision', 'layer' => 'facts', 'score' => 0.03, 'tags' => ['a'], 'updated_at' => '2026-09-19T03:40:27+00:00'],
            ['kind' => 'decision', 'layer' => 'facts', 'score' => 0.01, 'tags' => ['a', 'b'], 'updated_at' => '2026-09-20T03:40:27+00:00'],
            ['kind' => 'research', 'layer' => 'facts', 'score' => 0.02, 'tags' => [], 'updated_at' => '2026-09-18T03:40:27+00:00'],
        ]);

        self::assertSame(3, $s['count']);
        self::assertSame(0.01, $s['scoreMin']);
        self::assertSame(0.03, $s['scoreMax']);
        self::assertSame(['decision' => 2, 'research' => 1], $s['kinds']);
        self::assertSame(['facts' => 3], $s['layers']);
        self::assertSame(['a' => 2, 'b' => 1], $s['tags']);
        self::assertSame('2026-09-18T03:40:27+00:00', $s['oldest']);
        self::assertSame('2026-09-20T03:40:27+00:00', $s['newest']);
    }

    public function testTagsAreCutToTheTopFiveWithTiesBrokenByName(): void
    {
        $records = [];
        foreach (['f', 'e', 'd', 'c', 'b', 'a'] as $tag) {
            $records[] = ['tags' => [$tag]];
        }

        $s = Stats::summarise($records);

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_keys($s['tags']));
    }

    /* Older servers send no score or layer, and a record's tags can be
       null; none of that may break the page. */
    public function testRecordsMissingOptionalFieldsAreTolerated(): void
    {
        $s = Stats::summarise([
            ['kind' => 'note', 'tags' => null],
            ['score' => 'high', 'layer' => '', 'tags' => 'oops', 'updated_at' => 'not a date'],
        ]);

        self::assertSame(2, $s['count']);
        self::assertNull($s['scoreMin']);
        self::assertSame([], $s['layers']);
        self::assertSame([], $s['tags']);
        self::assertNull($s['oldest']);
        self::assertSame(['note' => 1], $s['kinds']);
    }

    public function testDatesAreOrderedByInstantNotByString(): void
    {
        $s = Stats::summarise([
            ['updated_at' => '2026-09-19T23:00:00-05:00'],  // = 2026-09-20 04:00 UTC
            ['updated_at' => '2026-09-20T01:00:00+00:00'],
        ]);

        self::assertSame('2026-09-20T01:00:00+00:00', $s['oldest']);
        self::assertSame('2026-09-19T23:00:00-05:00', $s['newest']);
    }

    public function testLabelsShowARangeNeverAPercentage(): void
    {
        $labels = Stats::labels(Stats::summarise([
            ['kind' => 'decision', 'layer' => 'facts', 'score' => 0.0328, 'tags' => ['esky'], 'updated_at' => '2026-09-19T03:40:27+00:00'],
            ['kind' => 'research', 'layer' => 'facts', 'score' => 0.0161, 'tags' => ['esky'], 'updated_at' => '2026-09-20T03:40:27+00:00'],
        ]));

        self::assertContains('score 0.016–0.033', $labels);
        self::assertContains('kind: decision 1 · research 1', $labels);
        self::assertContains('layer: facts 2', $labels);
        self::assertContains('tags: esky 2', $labels);
        foreach ($labels as $label) {
            self::assertStringNotContainsString('%', $label);
        }
    }

    public function testASingleScoreIsNotShownAsARange(): void
    {
        $labels = Stats::labels(Stats::summarise([['score' => 0.0328]]));

        self::assertContains('score 0.033', $labels);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose run --rm app vendor/bin/phpunit tests/StatsTest.php`
Expected: FAIL — `Class "Esky\Stats" not found`.

- [ ] **Step 3: Write `Stats`**

Create `src/Stats.php`:

```php
<?php
declare(strict_types=1);

namespace Esky;

/**
 * Summarises one search's results for the strip above the list. Pure: it reads
 * only the records it is handed, so it needs no server and no session.
 *
 * Every field except kind may be absent (older servers send no score or
 * layer), so each part is skipped rather than assumed.
 */
final class Stats
{
    private const TOP_TAGS = 5;

    /**
     * @param list<array<string, mixed>> $records
     * @return array{count: int, scoreMin: ?float, scoreMax: ?float, kinds: array<string, int>, layers: array<string, int>, tags: array<string, int>, oldest: ?string, newest: ?string}
     */
    public static function summarise(array $records): array
    {
        $kinds = [];
        $layers = [];
        $tags = [];
        $scores = [];
        $instants = [];

        foreach ($records as $record) {
            self::tally($kinds, $record['kind'] ?? null);
            self::tally($layers, $record['layer'] ?? null);

            $recordTags = $record['tags'] ?? null;
            foreach (is_array($recordTags) ? $recordTags : [] as $tag) {
                self::tally($tags, $tag);
            }

            $score = $record['score'] ?? null;
            if (is_int($score) || is_float($score)) {
                $scores[] = (float) $score;
            }

            $stamp = $record['updated_at'] ?? null;
            if (is_string($stamp) && $stamp !== '') {
                try {
                    $instants[$stamp] = (new \DateTimeImmutable($stamp))->getTimestamp();
                } catch (\Exception) {
                    // An unreadable date is left out of the range, not guessed at.
                }
            }
        }

        asort($instants);
        $stamps = array_keys($instants);

        return [
            'count' => count($records),
            'scoreMin' => $scores === [] ? null : min($scores),
            'scoreMax' => $scores === [] ? null : max($scores),
            'kinds' => self::ranked($kinds),
            'layers' => self::ranked($layers),
            'tags' => array_slice(self::ranked($tags), 0, self::TOP_TAGS, true),
            'oldest' => $stamps === [] ? null : $stamps[0],
            'newest' => $stamps === [] ? null : $stamps[count($stamps) - 1],
        ];
    }

    /**
     * Plain-text badge labels, unescaped: the caller escapes on output.
     *
     * @param array{count: int, scoreMin: ?float, scoreMax: ?float, kinds: array<string, int>, layers: array<string, int>, tags: array<string, int>, oldest: ?string, newest: ?string} $summary
     * @return list<string>
     */
    public static function labels(array $summary): array
    {
        $labels = [];

        if ($summary['scoreMin'] !== null && $summary['scoreMax'] !== null) {
            $min = sprintf('%.3f', $summary['scoreMin']);
            $max = sprintf('%.3f', $summary['scoreMax']);
            // The score ranks results against each other; it is not a
            // similarity, so it is shown as a range and never as a percentage.
            $labels[] = $min === $max ? "score {$min}" : "score {$min}–{$max}";
        }

        foreach (['kind' => 'kinds', 'layer' => 'layers', 'tags' => 'tags'] as $name => $key) {
            if ($summary[$key] !== []) {
                $labels[] = $name . ': ' . self::counts($summary[$key]);
            }
        }

        if ($summary['oldest'] !== null && $summary['newest'] !== null) {
            $from = substr(Page::stamp($summary['oldest']), 0, 10);
            $to = substr(Page::stamp($summary['newest']), 0, 10);
            $labels[] = $from === $to ? $from : "{$from} → {$to}";
        }

        return $labels;
    }

    /** @param array<string, int> $counts */
    private static function tally(array &$counts, mixed $value): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    /**
     * Most frequent first, name ascending on a tie, so the order is stable
     * from one request to the next.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function ranked(array $counts): array
    {
        $keys = array_keys($counts);
        usort($keys, static fn ($a, $b): int => $counts[$b] <=> $counts[$a] ?: strcmp((string) $a, (string) $b));

        $ranked = [];
        foreach ($keys as $key) {
            $ranked[$key] = $counts[$key];
        }

        return $ranked;
    }

    /** @param array<string, int> $counts */
    private static function counts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $name => $n) {
            $parts[] = $name . ' ' . $n;
        }

        return implode(' · ', $parts);
    }
}
```

- [ ] **Step 4: Run the Stats tests**

Run: `docker compose run --rm app vendor/bin/phpunit tests/StatsTest.php`
Expected: PASS. If `testTagsAreCutToTheTopFiveWithTiesBrokenByName` or a numeric-looking tag misbehaves, the cause is PHP turning numeric-string array keys into ints; `ranked()` already casts for `strcmp`, and labels cast through string concatenation, so fix the code, not the test.

- [ ] **Step 5: Render the strip in `public/index.php`**

Add `use Esky\Stats;` to the imports. Directly after the closing `</p>` of the count paragraph (before the "Nothing to show" block):

```php
    <?php $labels = ($query !== '' && $records !== []) ? Stats::labels(Stats::summarise($records)) : []; ?>
    <?php if ($labels !== []): ?>
        <p class="d-flex flex-wrap gap-1 mb-0">
        <?php foreach ($labels as $label): ?>
            <span class="badge rounded-pill tag"><?= Page::e($label) ?></span>
        <?php endforeach; ?>
        </p>
    <?php endif; ?>
```

- [ ] **Step 6: Verify by hand and run the suite**

Run: `docker compose run --rm app vendor/bin/phpunit`
Expected: all pass.

Run: `curl -s 'http://localhost:8080/index.php?q=esky' | grep -o 'badge rounded-pill tag">score[^<]*'`
Expected: a `score 0.0xx–0.0xx` badge. Also open the same URL in a browser and confirm the strip reads cleanly, and that the list with no query (`/index.php`) shows no strip.

- [ ] **Step 7: Update `CLAUDE.md` and commit**

Confirm the `Stats` paragraph added in Task 4 matches what was built; adjust if not.

```bash
git add src/Stats.php tests/StatsTest.php public/index.php CLAUDE.md
git commit -m "feat: show a stats strip above search results

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-review notes

- **Spec coverage:** `Client::forget` and empty-content handling → Task 1. `Csrf` and the `Session` accessors → Task 2. `forget.php` (GET confirm, POST action, 405/403, uid re-check, 303 redirect with notice) → Task 4. Button hidden when `retired_at` is set → Task 4. 500-character reason cap → Task 3. `CLAUDE.md` rule change → Task 4. `Stats::summarise`, strip only on a query with results, score as a range, tolerance of missing fields → Task 5.
- **Deviations from the spec:** the page-level GET-does-not-change-state test is a by-hand check (Task 4, Step 5), since page scripts cannot be unit-tested here. `Layout::error` gains a `$status` parameter, which the spec's 403/405 needed.
- **Open risk:** the real shape of esky's `memory_forget` reply is unverified; `acknowledge()` tolerates any non-error shape, and Task 4 Step 5 exercises it against the live server.

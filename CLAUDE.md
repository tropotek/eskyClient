# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

Everything runs in the container; PHP is not expected on the host.

    docker compose build
    docker compose run --rm app composer install
    docker compose up -d                                  # serves http://localhost:8080

    docker compose run --rm app vendor/bin/phpunit                        # all unit tests
    docker compose run --rm app vendor/bin/phpunit tests/PageTest.php     # one file
    docker compose run --rm app vendor/bin/phpunit --filter testStampShowsTheDateAndA24HourTime

    docker compose run --rm app php bin/smoke.php         # live check, first vault
    docker compose run --rm app php bin/smoke.php work    # live check, a named vault

`bin/smoke.php` (live connectivity probe) hits the real server and needs a
working vault in `config.json`; the PHPUnit suite is offline-only and must stay
that way — no test may open a socket.

## Architecture

A read-only web UI over the esky memory server. Three request paths, no router,
no framework: `public/index.php` (list + search), `public/view.php` (single
memory) and `public/metrics.php` (charts). PSR-4 `Esky\` → `src/`.

**Two transports, deliberately.** The memory pages speak MCP over HTTP
(`Client`); `metrics.php` reads the REST surface (`Api`), because the aggregates
it charts are not MCP tools — they are for a human reviewing the store, and every
tool description costs context in every agent session. `Config` derives the REST
base and the profile name from the vault's MCP url so the two are configured
once; the optional `apiUrl` / `profile` fields in `config.json` override that.

Bootstrap 5.3 supplies the layout and components, in its dark mode, vendored at
`public/vendor/bootstrap.min.css`. No CDN: this is read on a LAN that need not
have a route to the internet. Bootstrap's JavaScript bundle is vendored
alongside the stylesheet, for the navbar's avatar dropdown — which holds the
vault list and the page links — and its responsive collapse. That is the only
thing here that uses it. The detail
page's tabs remain hidden radio buttons and the vault tooltip remains a native
`title` attribute; converting them is possible now but has not been done.
`style.css` keeps the palette and assigns it into Bootstrap's custom properties
under `[data-bs-theme="dark"]` — the palette is defined in one place only.

`Chart` renders inline SVG server-side, for the same offline reason, and hover
labels are native `<title>` elements. Series colours are `--series-1…` in
`style.css`, assigned in fixed order so a series keeps its colour across charts —
never cycled, and never assigned by rank.

The transport is a layered decode, and each layer has its own unit test:

1. `Client` (`src/Client.php`) — POSTs JSON-RPC. It performs an
   `initialize` + `notifications/initialized` handshake **once per instance**,
   capturing the `Mcp-Session-Id` response header, which every later call must
   echo back. A new `Client` per request means a new handshake, so construct one
   and reuse it within a request.
2. `SseParser` — the server answers in Server-Sent Events framing; only `data:`
   lines carry payload, everything else is discarded.
3. `ResponseDecoder` — tool results are **double encoded**: the records live in
   `result.content[0].text` as a JSON *string*, not as JSON. It also converts a
   JSON-RPC `error` member into `EskyException`.

`Client::sorted()` re-sorts every result by `updated_at` descending, because the
server does not guarantee order.

`Vaults::load()` reads `config.json` from the project root — a git-ignored file
holding one entry per esky vault — and hands out a `Config` per vault, so
`Client` and `Api` still take a single `Config` and know nothing about vaults.
Vaults are configured in a file rather than through the app because the app has
no users and no authentication: a form storing bearer tokens would be writable
by anyone who could reach the port. `Session` is the only file that touches
`$_SESSION`; it holds the active vault's name and nothing else, which keeps
`Vaults` testable under CLI. A missing or malformed file throws
`EskyException`, which the page scripts catch and hand to `Layout::error()` (a
self-contained 500 page — it `exit`s, so nothing after it runs).

`Page` holds the helpers that format a record (`e()` for escaping, `preview()`,
`heading()`, `stamp()`) and `mask()` for a token. `Layout` holds the chrome
every page wears — `head()`, `navbar()` and `error()`. `Layout::navbar()`
resolves the vaults itself rather than taking them as an argument, so a page
cannot render the navbar and leave the vault unnamed; `navbarFor()` takes them
explicitly so the markup can be tested against a configuration held in memory.
A configuration that will not load simply names no vault, which is what the
error page needs. `vault.php` writes the session and redirects — the chosen
vault never appears in a url, and `Layout::backTarget()` whitelists where a
switch may return to.

`Health::check()` reduces each vault to a row for the settings page, masking the
token with `Page::mask()` so no caller can render one whole by accident. Its
probe is injectable for the same reason `Api`'s transport is — the suite may not
open a socket. `Client::ping()` is the probe itself: the handshake alone, no
`tools/call`.

`Markdown::toHtml()` uses GitHub-Flavored CommonMark with `html_input => escape` — memory text is untrusted, so raw HTML in a memory
must never be passed through.

## Constraints of the esky API

- Only `memory_recent` and `memory_search` are called. The app never writes,
  updates or retires a memory; keep it that way.
- `memory_search` is **semantic, not literal** — a nonsense query still returns
  its nearest matches, so an empty result is not a reliable "no match" signal.
- There is **no get-by-uid tool**, and a uid-shaped search returns nothing. So
  `view.php` finds a memory by pulling a large list (`search(q, 500)`, then
  `recent(500)`) and filtering client-side. This is deliberate and only works
  because the store is small.

## Serving

Only `public/` is web-exposed, via FrankenPHP. Paths outside it fall through to
`index.php` rather than 404ing — when checking that a file is not exposed,
assert on the response body, not the status code.

## Repository conventions

- PHP 8.4, `declare(strict_types=1)` in every file, `final` classes, constructor
  property promotion with `readonly`.
- Comments explain *why* (an API quirk, a deliberate limitation), not what.
- Commits are Conventional Commits, one logical layer per commit.
- `_notes/` is git-ignored and holds design/plan docs plus live credentials —
  never commit it or copy its contents into tracked files.

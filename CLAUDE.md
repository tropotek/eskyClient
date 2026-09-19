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

    docker compose run --rm app php bin/smoke.php         # live check against the esky server

`bin/smoke.php` (live connectivity probe) hits the real server and needs a valid
`ESKY_TOKEN`; the PHPUnit suite is offline-only and must stay that way — no test
may open a socket.

## Architecture

A read-only web UI over the esky memory server, spoken to as MCP over HTTP.
Two request paths, no router, no framework: `public/index.php` (list + search)
and `public/view.php` (single memory). PSR-4 `Esky\` → `src/`.

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

`Config::fromEnvironment()` reads `ESKY_URL` / `ESKY_TOKEN` from the environment
first, then falls back to `.env` in the project root. Missing values throw
`EskyException`, which both page scripts catch and hand to `Page::error()` (a
self-contained 500 page — it `exit`s, so nothing after it runs).

`Page` holds the shared view helpers (`e()` for escaping, `preview()`,
`heading()`, `stamp()`). `Markdown::toHtml()` uses GitHub-Flavored CommonMark
with `html_input => escape` — memory text is untrusted, so raw HTML in a memory
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

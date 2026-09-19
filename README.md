# esky client

A read-only web browser for the esky memory server. Lists memories newest first,
searches them, shows a memory as both raw Markdown and rendered HTML, and charts
what the store holds and what has been asked of it.

## Requirements

Docker and Docker Compose. There is no need for PHP on the host — everything runs
in the container.

## Setup

    cp .env.example .env

Set `ESKY_TOKEN` in `.env` to your esky bearer token, and `ESKY_URL` to the
server's MCP endpoint. Then:

    docker compose build
    docker compose run --rm app composer install
    docker compose up -d

The app is served on `http://localhost:8080` by default; change `HTTP_APP_PORT`
in `.env` to move it.

## Configuration

| Variable | Purpose |
|---|---|
| `ESKY_URL` | esky MCP endpoint, e.g. `http://host:8011/mcp/personal` |
| `ESKY_TOKEN` | Bearer token, without the `Bearer ` prefix |
| `HTTP_APP_PORT` | Host port to publish, default `8080` |
| `ESKY_API_URL` | Optional. REST base, e.g. `http://host:8011`; derived from `ESKY_URL` when unset |
| `ESKY_PROFILE` | Optional. Profile to read metrics for; derived from `ESKY_URL` when unset |
| `UID` / `GID` | Container user ids, match your host user so bind-mounted files stay editable |

## Layout

    src/        Config, SseParser, ResponseDecoder, Client, Api, Chart, Markdown, Page
    public/     index.php (list and search), view.php (detail),
                metrics.php (charts), style.css
    bin/        smoke.php, a live check against the server
    tests/      PHPUnit unit tests

Only `public/` is served over HTTP. Paths outside it fall through to
`index.php` rather than returning 404, so check response bodies rather than
status codes when verifying that a file is not exposed.

## Development

Run the unit tests:

    docker compose run --rm app vendor/bin/phpunit

Check connectivity to the live server:

    docker compose run --rm app php bin/smoke.php

## Metrics

`/metrics.php` charts a 7, 30 or 90 day window: searches per day against the ones
that came back empty, how much each search matched, the questions asked most
often and whether they were answered, the memories that answered them, and the
store's own growth, retirement, kind mix and tags.

It reads esky's REST surface (`/api/{profile}/queries/summary` and
`/api/{profile}/stats`) rather than MCP, with the same bearer token. The base URL
and profile are derived from `ESKY_URL`, so an `ESKY_URL` without a
`/mcp/{profile}` path needs `ESKY_PROFILE` set. The server must be new enough to
serve those two endpoints.

Charts are server-rendered inline SVG — no JavaScript and no CDN, so the page
works on a LAN with no route to the internet.

## Notes on the esky API

- Responses use Server-Sent Events framing; only `data:` lines carry payload.
- Tool calls require an `initialize` / `notifications/initialized` handshake and
  an `Mcp-Session-Id` header.
- Tool results are double encoded: `result.content[0].text` is a JSON string.
- `memory_search` is semantic, not literal — a nonsense query still returns its
  nearest matches. There is no get-by-uid tool, so the detail page filters a list.

## Scope

The app only calls `memory_recent` and `memory_search`. It never writes to,
updates or retires a memory.

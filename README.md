# esky client

A read-only web browser for the esky memory server. Lists memories newest first,
searches them, and shows a memory as both raw Markdown and rendered HTML.

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
| `UID` / `GID` | Container user ids, match your host user so bind-mounted files stay editable |

## Layout

    src/        Config, SseParser, ResponseDecoder, Client, Markdown, Page
    public/     index.php (list and search), view.php (detail), style.css
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

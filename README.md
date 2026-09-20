# esky client

A read-only web browser for the esky memory server. Lists memories newest first,
searches them, shows a memory as both raw Markdown and rendered HTML, and charts
what the store holds and what has been asked of it.

## The server

This is a viewer, not a store. It needs a running
[**esky**](https://github.com/tropotek/esky) — the self-hosted MCP memory
server it reads from, where agents write the memories in the first place and
where profiles, bearer tokens and the query log live. Set that up first; its
README covers installing the server, creating a profile and issuing the token
this app needs.

## Requirements

Docker and Docker Compose. There is no need for PHP on the host — everything runs
in the container.

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
`/settings.php` lists what is configured and probes each vault.

`.env` holds only host-level settings:

| Variable | Purpose |
|---|---|
| `HTTP_APP_PORT` | Host port to publish, default `8080` |
| `UID` / `GID` | Container user ids, match your host user so bind-mounted files stay editable |

## Layout

    src/        Config, Vaults, Session, SseParser, ResponseDecoder, Client,
                Api, Health, Chart, Markdown, Page, Layout
    public/     index.php (list and search), view.php (detail),
                metrics.php (charts), settings.php, about.php, vault.php,
                style.css, img/user.png, vendor/bootstrap.min.css,
                vendor/bootstrap.bundle.min.js
    bin/        smoke.php, a live check against the server
    tests/      PHPUnit unit tests

Only `public/` is served over HTTP. Paths outside it fall through to
`index.php` rather than returning 404, so check response bodies rather than
status codes when verifying that a file is not exposed.

## Development

Run the unit tests:

    docker compose run --rm app vendor/bin/phpunit

Check connectivity to the live server:

    docker compose run --rm app php bin/smoke.php          # the first vault
    docker compose run --rm app php bin/smoke.php work     # a named vault

## Metrics

`/metrics.php` charts a 7, 30 or 90 day window: searches per day against the ones
that came back empty, how much each search matched, the questions asked most
often and whether they were answered, the memories that answered them, and the
store's own growth, retirement, kind mix and tags.

It reads esky's REST surface (`/api/{profile}/queries/summary` and
`/api/{profile}/stats`) rather than MCP, with the same bearer token. The base URL
and profile are derived from the vault's `url`, so a `url` without a
`/mcp/{profile}` path needs `profile` set on that vault. The server must be new enough to
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

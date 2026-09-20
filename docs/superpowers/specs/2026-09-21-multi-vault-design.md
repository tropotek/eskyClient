# Multi-vault support

Date: 2026-09-21
Status: approved, not yet implemented

## Problem

The client reads one memory store. `Config::fromEnvironment()` takes `ESKY_URL`
and `ESKY_TOKEN` from the environment, and every page builds its single
`Client` or `Api` from that. Reading a second vault — a work store alongside
the personal one — means editing `.env` and restarting.

## Goal

Configure several esky services at once, switch between them from the navbar,
and see which of them are reachable. The app stays read-only against every
vault: `memory_recent` and `memory_search` only, as before.

## Non-goals

- No database. Two or three records of `{title, url, token}` do not earn a
  schema, a migration path or a new dependency.
- No web-editable settings. Vaults are edited on the host, in a file. The app
  has no users and no authentication; a form that stores bearer tokens would be
  a write surface open to anyone who can reach the port.
- No cross-vault search. Each page reads one vault, the selected one.

## Configuration

A git-ignored `config.json` in the project root, with a tracked
`config.json.example` beside it:

```json
{
  "vaults": [
    {
      "name": "personal",
      "title": "Personal",
      "url": "http://host:8099/mcp/personal",
      "token": "…"
    },
    {
      "name": "work",
      "title": "Work",
      "url": "http://host:8099/mcp/work",
      "token": "…"
    }
  ]
}
```

- `name` — the slug held in the session and used in links. Required, unique,
  `[a-z0-9_-]+`.
- `title` — the label the navbar shows. Required.
- `url`, `token` — as the current `ESKY_URL` / `ESKY_TOKEN`. Required.
- `apiUrl`, `profile` — optional per-vault overrides, matching today's
  `ESKY_API_URL` / `ESKY_PROFILE`. Omitted, they are derived from `url` exactly
  as `Config` derives them now.

`ESKY_URL` and `ESKY_TOKEN` are removed from `.env` and are no longer read.
One source of vault configuration, so there are no precedence rules to explain.
`.env` keeps the host-level settings (`HTTP_APP_PORT`, `UID`, `GID`).

`config.json` is added to `.gitignore`. It sits outside `public/`, so it is not
served; a test asserts this against the response *body*, since paths outside
`public/` fall through to `index.php` rather than 404ing.

## Components

### `Vaults` (new, `src/Vaults.php`)

Loads and validates `config.json` and hands out `Config` objects.

- `Vaults::load(string $projectDir): self` — reads and parses the file. A
  missing file, malformed JSON, an empty `vaults` array, a duplicate `name` or
  a missing required field each throw `EskyException` with a message naming the
  problem. The page scripts already catch `EskyException` and pass it to
  `Layout::error()`.
- `all(): list<Config>` — in file order.
- `get(string $name): ?Config`.
- `first(): Config`.
- `current(): Config` — resolves the session's choice; see below.

### `Config` (changed)

Gains readonly `name` and `title`. `fromEnvironment()` is removed; `Vaults`
constructs `Config` instances directly. The `apiBase` and `profile` derivation
is unchanged, so `Client` and `Api` are untouched — both still take a `Config`
and know nothing about vaults.

`Layout::navbar()` currently resolves the vault itself through
`Config::fromEnvironment()`. It now resolves it through `Vaults`, keeping the
same property: a page cannot render the navbar and leave the vault unnamed, and
a configuration that will not load simply names no vault.

### Selection

`Vaults::current()` reads `$_SESSION['esky_vault']`, validates the name against
the loaded file, and falls back to the first vault when it is absent or no
longer configured. No vault name appears in any URL.

`public/vault.php` sets the choice:

```
/vault.php?to=work&back=/metrics.php
```

It validates `to` against the configured names, writes the session, and
redirects. `back` is whitelisted to `/index.php` and `/metrics.php`; anything
else, including an absent value, redirects to `/index.php`. `view.php` is
deliberately not in the whitelist — a uid belongs to one vault, so switching
from a detail page lands on the list.

### Health probe

`Client::ping(): void` performs the handshake only — `initialize` plus
`notifications/initialized`, no `tools/call` — and throws `EskyException` on
failure. `settings.php` constructs a `Client` per vault with a short timeout
(2s) and catches the exception, so one unreachable vault does not stall or
break the page.

The probe result is reduced to a plain array before rendering:

```php
['name' => 'work', 'ok' => false, 'message' => 'Could not reach Esky: …']
```

so the settings view is rendered from data and can be tested without a socket.

## Pages

### Navbar

Bootstrap's own JavaScript bundle is vendored to
`public/vendor/bootstrap.bundle.min.js`. This reverses the earlier decision to
ship no Bootstrap JavaScript. The reason for that decision — no route to the
internet on the LAN this is read on — is unaffected, because the bundle is
vendored exactly as the stylesheet is and no CDN is contacted. CLAUDE.md is
updated to record the reversal rather than left contradicting the code.

Two dropdowns:

- **Left**, after the brand: a ☰ toggle with *Settings* and *About*.
- **Right**, replacing today's vault text: the vault title as the toggle, and
  one item per configured vault linking to `/vault.php`, with a tick on the
  active one. The existing explanatory tooltip stays a native `title`
  attribute.

The detail page's radio-button tabs and the `title` tooltip are left alone.
Converting them to Bootstrap components is now possible but is separate work
and out of scope here.

### `public/settings.php` (new)

A read-only view of what is configured. One row per vault: title, name, MCP
URL, derived profile, masked token, and a reachable/unreachable badge from the
probe. Unreachable rows show the error message. A line names `config.json` as
the file to edit; there is no form.

Tokens are masked by a new `Page::mask()` — first three and last four
characters, the middle replaced by an ellipsis; a token shorter than twelve
characters is shown entirely as ellipsis rather than partly revealed.

### `public/about.php` (new)

What Esky is, the vaults configured, and a link to the esky server. Static
apart from the vault list.

## Testing

The suite stays offline; no test opens a socket.

- `VaultsTest` — parses a valid file; rejects a missing file, malformed JSON,
  an empty `vaults` array, a duplicate `name`, and each missing required field;
  `current()` falls back to the first vault for an absent or stale session
  name; `apiUrl` and `profile` overrides reach the `Config`.
- `PageTest` — `mask()` for a normal token, a short token, and an empty string.
- `LayoutTest` — the navbar renders both dropdowns, one item per vault, the
  tick on the active vault, and no vault name when the configuration will not
  load.
- A settings-view test renders the page's table from a fixed probe array,
  covering a reachable and an unreachable vault.
- A serving test asserts `config.json` is not exposed, checking the response
  body rather than the status code.

`bin/smoke.php` keeps its live probe and is updated to take a vault name,
defaulting to the first.

## Commits

One logical layer per commit, in this order:

1. `feat: load several vaults from config.json` — `Vaults`, `Config` changes,
   `config.json.example`, `.gitignore`, tests.
2. `feat: select the active vault from the navbar` — `vault.php`, session
   resolution, navbar dropdowns.
3. `build: vendor Bootstrap's JavaScript bundle` — the file, plus the CLAUDE.md
   note recording the reversal.
4. `feat: add settings and about pages` — `Client::ping()`, `Page::mask()`,
   both pages, tests.
5. `docs: describe the vault configuration` — CLAUDE.md and README.

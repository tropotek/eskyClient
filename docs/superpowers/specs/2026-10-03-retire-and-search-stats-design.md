# Retire a memory, and search stats — design

Date: 2026-10-03

## Intent

Let the owner retire a memory from the UI, and show a compact summary of a
search's results above the list. Both are for one person on a trusted LAN.

## Decisions already made

- The app has no auth. Anyone who can reach the port can retire memories. The
  owner accepts this for a LAN; protection is CSRF token plus a confirm step.
  Adding authentication is out of scope.
- Retiring uses esky's `memory_forget`, which hides a memory from searches but
  never destroys it, so it is recoverable on the server.
- Stats are a compact strip, not a panel with charts.

## 1. Retire

### Components

- `Client::forget(string $uid, ?string $reason): void` — calls `memory_forget`
  through the existing `call()`. A forget may return an empty `content` array;
  `forget` must treat that as success, and must be verified against the live
  server (`bin/smoke.php` is read-only, so this is a manual check).
- `Csrf` (`src/Csrf.php`) — `token(): string` creates one per session with
  `random_bytes`, `verify(?string): bool` compares with `hash_equals`. It
  stores the token via `Session`, which stays the only file touching
  `$_SESSION` (new method pair `csrfToken()` / `setCsrfToken()`).
- `public/retire.php`:
  - GET `?uid=` renders a confirm page: memory title, a reason field, the CSRF
    token, a Retire button and a Cancel link back to `view.php`. The memory is
    located the same way `view.php` does it.
  - POST validates the token, calls `forget`, and redirects 303 to
    `/index.php?retired=1`. `index.php` shows a one-line notice for it.
  - Any other method returns 405. A bad or missing token returns 403 through
    `Layout::error()`.
- `view.php` gains a "Retire" button linking to `retire.php?uid=`. It is hidden
  when the memory already has `retired_at`.

### Constraints

- A GET must never change state; only the POST retires.
- The reason is optional, trimmed, and capped at 500 characters.
- The uid in the POST body is re-checked against the vault's list so a forged
  uid for a nonexistent memory is rejected rather than forwarded.
- `CLAUDE.md`: the rule "the app never writes, updates or retires a memory"
  becomes "the only write is `memory_forget`, from `retire.php`, behind CSRF
  and a confirm page". Its `Session` description is updated to include the CSRF
  token.

## 2. Search stats

- `Stats::summarise(array $records): array` (`src/Stats.php`) — pure function,
  no I/O. Returns: `count`, `scoreMin`, `scoreMax`, `kinds` (kind => count),
  `layers` (layer => count), `tags` (top 5, tag => count), `oldest` and
  `newest` (`updated_at`).
- Rendered by `index.php` only when a query is present and results exist, as a
  single muted line of badges above the list.
- Score is shown as a min–max range. `score` looks like a reciprocal-rank-fusion
  value, which ranks results against each other and is not a similarity, so it
  is never presented as a percentage.
- Records lacking `score` or `layer` (older servers) simply omit that part of
  the strip.

## Testing

Offline PHPUnit only, no sockets:

- `ClientTest` (new) — `forget` builds the right `tools/call` and accepts an
  empty-content response. Uses an injected transport; if `Client` has no
  injection point, add one the way `Api` has.
- `CsrfTest` — token is stable within a session, `verify` rejects null, empty
  and wrong values.
- `StatsTest` — counts, score range, top-5 tag cut-off and ordering, records
  missing optional fields, empty input.
- A page-level test that `retire.php` refuses GET-driven state change is
  covered by keeping the logic in a testable class if one emerges; otherwise
  checked by hand against the running container.

## Out of scope

Authentication, un-retiring, bulk retire, editing, and any stats beyond the
strip.

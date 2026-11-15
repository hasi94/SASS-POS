---
name: pin-slices
description: How T-010's PIN is judged, hashed, verified and locked - the generated weak list, the one-statement lockout, the dummy digest, and what the sink harness scans
metadata:
  type: project
---

`Flexipos\Access\Support\PinPolicy` is the single home for the PIN rules, and its weak
set is **generated** from the rules (repeated digits, ascending runs and their
reverses) for every accepted length, not typed as the four PINs AC-47 names - four
literals would be right for four digits and silently empty for eight. The set is an
instance value with an `@internal withWeakPins()` seam, so "one list in one place" is
provable by moving it: take one entry out and exactly that PIN becomes acceptable.
Bounds and lockout figures come from `config/access.php`.

**Setting a PIN unlocks** (one statement writes digest, `pin_set_at`,
`pin_failed_attempts = 0`, `pin_locked_until = null`) and does **not** bump
`perm_version`; `SelfChange` is deliberately *not* called - a credential is not a
grant. `SetPinHandler` is in `AuditWriterTest`'s derived sweep as `pin_set`.

**`VerifyPinHandler`** returns one causeless refusal value; the cause lives only in
`access_log.detail.reason`. The hasher runs **once, before any branch**, against the
stored digest or `DUMMY_PIN_HASH` (a real Argon2id digest at *shipped* factors, so the
two paths cost the same in production). The lockout is a single
`UPDATE ... SET pin_locked_until = CASE ... , pin_failed_attempts = CASE ...`: MySQL
and MariaDB let later assignments see earlier ones, so `pin_locked_until` is assigned
first; SQLite evaluates both against the original row. Locked means
`now < pin_locked_until`, strictly.

**AC-48's harness** (`QaPinSinkCaptureTest`) runs the whole scenario twice: once with
an ordinary PIN to produce a baseline, then with a sentinel chosen as the first
eight-digit candidate absent from that baseline in every form (raw, JSON-escaped,
URL-encoded, **reversed**). It scans 45 tables / ~500 cells across both live schemas
and five text sinks; the request URL gets its own sink, because the deliberate probe
really does put the PIN there and the application cannot clean what nginx logged.

See [[access-http-surface-seams]], [[hasher-seam-and-assertion-reds]].

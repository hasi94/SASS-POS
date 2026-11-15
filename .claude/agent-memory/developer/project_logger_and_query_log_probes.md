---
name: logger-and-query-log-probes
description: How to observe the application log and the master query log in this suite, and the singleton ordering that silently empties the log probe.
metadata:
  type: project
---

Two probes T-009 added to `packages/auth/tests/Support/auth-fixture.php`, both
reusable:

- `authFixtureRecordingLogger()` binds an `AbstractLogger` subclass under
  `Psr\Log\LoggerInterface::class`, which Laravel aliases to `log` — so one
  `app()->instance()` replaces both constructor injections and the `Log` facade.
  It keeps level, message and context, so the same object answers "was it
  logged at error" and AC-26's "is a secret in there".
- `authFixtureWithMasterQueryLog(callable)` returns `[result, statements]` and
  disables/flushes the log in a `finally`. It deliberately returns the
  statements instead of answering a question, because "contains no X" is
  satisfied by a log that recorded nothing.

**The gotcha that cost a red run.** `SigningKeyring` is bound as a *singleton*.
A recording logger installed **after** anything has resolved the keyring is
handed to nobody, and the probe reports an empty log — identical to a failure
that was never logged. Install the recorder before the fixture that builds a
session, not just before the call under test.

**Why:** the application log is the only trace an operational signing failure
leaves (D-37/FP-201), so an empty-looking probe is the one shape that must never
be mistaken for success.

**How to apply:** when a probe replaces a container binding, check whether the
collaborator that reads it is a singleton, and bind first. Pair with
[[absence-is-a-third-state]].

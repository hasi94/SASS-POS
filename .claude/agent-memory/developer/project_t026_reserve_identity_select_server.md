---
name: t026-reserve-identity-select-server
description: T-026 step 9 (reserve_identity/select_server) design choices and two sharp edges hit while building them
metadata:
  type: project
---

Step 9 of T-026 (tenant provisioning) shipped `ReserveIdentity` and `SelectServer` under
`packages/platform/src/Provisioning/Steps/`. Two things worth remembering for steps 10-16,
which follow the same `ProvisioningStep` shape.

**A step tested by calling `->run()` directly (bypassing `ProvisioningRunner`) must journal its
own idempotency marker.** `ProvisioningRunner::run()` normally calls `markStepDone()` after a
successful `run()`, which is what persists the step's output into
`tenant_provisioning_steps.output`. A unit test that constructs the step and calls `run()`
itself never reaches that code, so if the step's own resume-safety check reads
`$journal->read()`, nothing is there on a second call unless the step calls `$journal->write()`
itself before returning. `SelectServer` initially skipped this and its "idempotent on resume"
test actually re-selected a different server on the second call — caught only because the test
changed capacity between the two calls. Every step from here on needs its own
`$journal->write()` for whatever makes it idempotent, not just a `$journal->read()` guard.

**AC-37's static test (tokenise the step file, assert `code` is written only from
`TenantCode::normalise()`)** only works cleanly if every literal `'code' => ...` site in the
file's text has that call as its direct RHS — a variable that was *itself* assigned from
`normalise()` earlier defeats a simple regex check on the array-literal text. Normalise is pure
and idempotent, so the fix was to never store the normalised code in the mutable `$intent`
array at all; every site that needs it calls `TenantCode::normalise($context->input['code'])`
inline, including the return-array merges. Cheap to call twice, and keeps the static check
honest instead of clever about dataflow. See [[feedback_a_mutation_must_move_the_observable]]-
style discipline: this test was run both against real code (green) and a deliberately
reintroduced `$context->input['code']` bypass (red) to confirm it actually catches something.

**AC-7's race (unique violation re-read to tell a `code` collision from a `sequence`
collision)** is proven with a `public static ?Closure $raceHook` on the step class, fired once
immediately before the insert and then cleared. The test's hook plans and runs a second,
independent `ReserveIdentity` invocation for the *same* code from inside the hook closure —
that second invocation commits for real (same step, same code, via the ordinary path), so the
outer attempt's insert then genuinely collides on `tenants.code`'s unique index and the
re-read finds a real winning row with a real owning run to name. Simpler than trying to
pre-insert a conflicting row by hand and get the column shapes exactly right.

`dirname(__DIR__, N)` miscounts are easy from a test three directories deeper than the
production file under `tests/` vs `src/` — count the actual levels (`Steps -> Provisioning ->
tests -> platform`, not `-> packages`) rather than guessing N by analogy to similar-looking
paths.

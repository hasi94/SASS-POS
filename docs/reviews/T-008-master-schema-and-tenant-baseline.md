# Review: T-008 Master schema, tenant baseline and the per-tenant migration runner

**Round 1 · 2026-10-15 · Verdict: APPROVE** (no Critical findings)

Reviewed `main..HEAD` (`e1c4602..acb97d3`) — **64 commits, 242 files, +28434/-128**. The reviewer read the
QA report (four rounds), the spec (68 criteria), the plan (19 sections), the decisions (D-1 to D-56) and
the production code of every slice, the tenancy changes, the engine-safety package, both migration trees
and the source-rule machinery.

**Gate re-run by the reviewer, not taken from the brief:** exit 0, **845 passed, 11 skipped, 4444
assertions**, no sentinel. Matches the lead's figure exactly. No engine connection opened, the hold
untouched, nothing written to the engine's volume.

**Counts re-measured rather than relayed:** 68 criteria, **52** carrying the engine marker. The lead's and
QA's numbers are correct.

> **The headline, plainly: the work is sound.** Sixty-four commits is a lot of volume and the volume is not
> padding — it is twelve defects found and closed, four design conflicts ruled on with the reasoning
> written where the next reader meets it, and a safety layer built after a real data-loss incident.

## Critical

None.

## Warnings

### R-W1 · A run row can report a start later than its finish

`RunTenantMigrationsHandler::markRunning()` writes `started_at` **unconditionally**. The queued path
*requires* a second invocation after the queue drains, and that invocation runs `markRunning()` before
`finish()`. Since `finish()` deliberately **preserves** `finished_at`, a drained queued run can end with
**`started_at` later than `finished_at`** — a row that lies about its own duration.

This is the same class of defect step 13f fixed for `finished_at`, and the same rule the job-level claim
statement already applies via `coalesce(started_at, …)`. **Two of the three sites got it; this one did
not.** A red input already exists in shape — the sibling of the 17-minute-clock case that guards
`finished_at`.

*Verified by the lead: the unconditional write and the coalescing sibling one file away.*

### R-W2 · The 503 body hands an unauthenticated client the remedy

The renderer returns the exception message verbatim. That message names **the tenant id and the
configuration key to set**. An unauthenticated client hitting a misconfigured deployment receives both.

No credential is exposed and the reasoning for 503-without-`Retry-After` is right — **the leak is the
operational detail, not the status code.** Same shape at lower severity for the behind-tenant renderer,
which returns the tenant id and both version numbers.

Fix: a fixed sentence in the body, the detail to the log. The test asserting the variable name in the body
**moves to the log rather than being deleted** — the point it pins is real, it is pinned on the wrong
channel.

*Verified by the lead.*

### R-W3 · "Extended exactly once" is documented, not enforced

The base exception is correctly un-finalised with its reason and the permitted subclass named, and the
catch-compatibility condition is satisfiable. But **the subclass itself is not `final`**, so a third member
of the family — which the plan says needs a ruling — can land without anything going red.

One word closes it, or a test counting the subclasses, which is the same "count the escape hatch" tooth
the status-policy census already uses.

## Suggestions

- **S-1** The lock-name docblock claims a bound the method does not enforce. Every id this schema can
  produce fits, and the failure direction is safe (over-locking, never two runners in one tenant) — so
  either assert the length or soften the sentence.
- **S-2** Eviction is discovered by `instanceof` in two places, both with the right reason for not asking
  the container. A **second** caching decorator would silently stop being evicted and the rule "every
  status write is followed by an eviction" would quietly become false. A one-method interface makes the
  *capability* the thing checked.
- **S-3** The widening factory is public production API. The plan accepts it as a reviewed-code-change
  residual and the shipped-binding guard-rail catches the case that matters; an `@internal` marker would
  make the residual visible at the call site rather than only in the plan.
- **S-4** The mapping source is operator-switchable to the configuration stand-in. It is blocked downstream
  in production, so this is defence-in-depth only — an explicit refusal would make the stand-in
  structurally unreachable rather than incidentally so.
- **S-5** The applier passes console output into a failure; it is sanitised downstream with the credential
  and a scheme sweep. Sound as shipped — worth a note **at the throw site**, because that is where a future
  author would add the connection string.

## The four checks the lead asked for

**1. Read-then-write pairs — both pinned, and one stronger than QA reported.** The fourth mask shape occurs
twice and both are closed. The claim statement shares one predicate method between its read and its
`UPDATE`, so the mutation removes the term from the granting statement; QA mutated it three ways. The
completion statement was recorded by QA as "read, not mutated" — it is **stronger than a pin**: the `where`
is pinned from the query log *and* a behavioural case supplies the input at which the re-check binds, a
second worker taking the job mid-migration. **No read-then-write pair in this branch rests on behaviour
alone.**

**2. Deferring the reachability check — right, for the plan's reason rather than the ratio.** The two real
targets do carry name-qualified wiring tests with their own controls and behavioural halves. The decisive
reason is the third: **a receiver-blind textual check whose flagship mutation only reddens because a method
name happens to be unique is a false guarantee. Shipping it would have been worse than the gap.**

**3. The deleted scan — the replacement proves more, not less.** Checked hardest, because deleting a proof
is where coverage is usually lost. The scan asserted "the planner holds no copy", by searching for one
spelling; QA evaded it with another. The replacement proves the same claim by a stronger method — move the
authority to a status no production code has heard of and watch both dependents follow, in both directions,
each with the shipped-authority control first. **A copy in any spelling, quoting, concatenation or constant
indirection is red, and the proof never reads the planner's source.** Verified: it covers the scan's claim
and strictly more.

**4. The reconciliation holds; the policy object is not a regression.** The separation of *channel* from
*number of readers* is correct and **enforced rather than argued**: the serving branch reads its constant
directly in the `match`, so no constructor argument can reach it — structural, not conventional; the
by-type guard-rail reddens on any class or interface that is not the purpose enum, with a non-empty-subject
assertion so it cannot pass vacuously; the shipped binding's lists and singleton identity are pinned; the
constructor is private and the forbidden channel genuinely does not exist. The file even states the
mutation it does **not** catch, and why that is correct.

## The residuals

- **42 of 52 engine criteria with no engine test** — correctly and repeatedly stated, including in the final
  verdict. A task-completion gap, not a code defect. **The reviewer checked all four QA rounds for a
  sentence that drifted and found none.**
- **Two criteria unverifiable even with an engine** — honest, and correctly *not* laundered into a pass by
  the wiring tests.
- **The queued run staying open** — accepted as designed; step 13f landed the two conditions that stop it
  being a run whose state lies, and the backlog row is now honest about what was false when it was written.
  R-W1 is the one loose thread here.
- **The un-finalised base exception** — three of four conditions satisfied; the fourth is R-W3.
- **The contract dependency inferred rather than declared** — right for this task: the tag has no input that
  would bind it, the false-refusal case is named, and after BUG-12 the printed remedy is an invocation that
  actually runs, round-tripped through the console.

## Done well

- **The safety layer is the best work in the branch.** Allow-list over deny-list with the measured reason
  (31 → 56 schemas in one day); two genuinely independent prefix rules, the second deliberately *not*
  reading the first's constant; enumeration in PHP with the wildcard expansion pinned as a fact about the
  engine; the sweep **deleted rather than bounded**, with the ceiling argument written out; and the
  containment test **scanning itself**, every positive control built by concatenation so the scanner cannot
  be used to hide what it looks for. The hold as a constant rather than a variable is the stronger answer,
  and the docblock explains why narrowing one path is not closing the class of path.
- **Order as a criterion, not a side effect** — the planner's five steps and the migration handler's five
  load-bearing orderings documented as *the* design, with the "a rolled-back insert still happened"
  argument that makes "zero job rows" provable.
- **Opposite questions kept as opposite rules** — the blocking set excluding exactly one status with an
  unrecognised status *blocking*, beside an allow-list for selection, and a test asserting the two differ,
  because unifying them is precisely how that defect returns.
- **Exactly-N-attempts as a property of shape** — two calls to one method, not a loop with a bound, so
  nothing drifts by editing a constant.
- **Tests that assert behaviour.** Every scan asserts its own scanned set is non-empty; every rule has a
  fixture it fires on and a negative control. **The reviewer found no test in its sample that merely runs
  code.** The deliberate self-criticism — *"a mutation this file does NOT catch, stated rather than left to
  be found"* — is named as the standard this repository should keep.
- **QA's verdict is the right shape and endorsed:** PASS for what could be verified, NOT VERIFIED as a task.
  Both halves true, for the stated reasons.

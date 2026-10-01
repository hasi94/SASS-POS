# T-004 decisions — gate speed and repository hygiene

Decisions made autonomously by `ba` while writing
`docs/specs/T-004-gate-speed-and-hygiene.md`, per the TEAM PROTOCOL (no blocking questions
to the human). D-1 to D-33 belong to T-001, T-002 and T-003 and are unchanged; this file
continues the numbering at D-34.

D-34 | Does the fast/full split exclude tests by Pest **group tag** or by **directory**
(moving the ~40 toolchain tests into a separate `tests/Toolchain`-style path)? | Group tag:
Pest's native `->group('toolchain')` (or the equivalent PHPUnit `@group` annotation),
applied to the existing test files in place. No test file is moved to a different
directory for this reason. | The backlog's own FP-155 order already says "tag the
child-process toolchain tests into a group" — the mechanism is effectively named. A group
tag is a one-line, reversible annotation per file/case with no path change, so it cannot
collide with the FP-156 renormalisation commit (which must touch only line endings) and
does not disturb `phpunit.xml`'s existing directory-based collection (T-002 AC-12/AC-13,
`HostTestSuiteCollectionTest`) or the file-path assumptions in `QaToolchainRegistryCoverageTest`
(T-002 AC-18). Moving ~40 files to a new directory is a larger, riskier diff for the same
outcome and offers no benefit a group tag does not already provide. Reversible: yes — remove
the tag.

D-35 | What is the full gate called? | `composer check` (unchanged). It continues to run
`lint`, `stan`, `deptrac`, `test` in sequence, with `test` continuing to mean the whole,
unfiltered Pest run. | `composer check` is already established across T-001/T-002/T-003 as
"the CI gate" (T-001's own non-functional requirements, T-002/T-003's "must not regress from
its current exit-0 state" clauses). Renaming it would touch every existing script, every
prior spec's non-functional requirements, and the TEAM PROTOCOL's own hook expectations for
no benefit; the backlog only asks for a fast command to be *split off*, not for the full
gate to be renamed. Reversible: yes.

D-36 | What is the new fast command called, and which command does the `[CODE]`/`[FIX]`
task-completion hook use? | Add one new Composer script, `test:fast` (Pest run with
`--exclude-group=toolchain` or the equivalent), for the inner development loop only. The
existing `test` script's meaning is unchanged (still the full, unfiltered run, still part of
`check`). The task-completion hook keeps calling `composer check` — it is never pointed at
`test:fast`. | Keeping `test`'s existing meaning intact is the safest, most reversible
option: no other script, decision, or prior spec that already says "`composer test`" (T-001
AC-16, T-002's and T-003's regression notes) has to be re-read to check whether it now means
something filtered. Adding one new, clearly-named script is a pure addition. Pointing the
completion hook at `composer check` is not a new decision so much as the backlog's own
explicit constraint restated ("the full gate must remain the thing that gates task
completion; only the inner development loop gets the fast command") — logged here so `dev`
and `architect` do not have to re-derive it. Reversible: yes — delete the script, nothing
else references it.

D-37 | Is "the child-process toolchain tests" (the group FP-155 item 1 tags) defined as
"every test that uses `Illuminate\Support\Facades\Process`", or something narrower? |
Narrower: a test belongs in the `toolchain` group only if its purpose is proving the
toolchain/generator mechanism itself works (linting, static analysis, boundary checking,
test collection, code generation, `config:cache`/`route:cache` guard behaviour) — not merely
"this test happens to spawn a child process." | A literal `Process::` grep would sweep in
`QaCredentialSurfaceSweepTest`'s "keeps the credential out of the dump() helper the
application actually installs" case, which spawns a child process to boot the real
application and inspect its `dump()` output — a security-surface proof, not a toolchain
proof. The backlog lists "never trim the credential-leak tests" as its own, separate,
emphasised item precisely because this trap is real; defining membership by purpose rather
than by mechanism is what keeps that instruction satisfiable without hand-listing every
current and future exception. Reversible: yes — the group's membership rule is a review-time
convention, not a structural constraint.

D-38 | Does FP-156's root `.gitattributes` addition and the resulting renormalisation land
as one commit, or two (rule first, renormalisation second)? | One commit: adding the root
`.gitattributes` rule and renormalising the working tree to match it happen together, as the
single commit the backlog describes ("it renormalises the working tree once, so it must be
its own commit, separate from everything else in this task"). | The backlog's own wording
treats "add the rule" and "renormalise" as one event ("it renormalises... so it must be its
own commit" — singular), and splitting them into two commits would leave an intermediate
commit where the rule exists but the tree does not yet match it, which is the exact
inconsistent state the fix is meant to eliminate. Reversible: yes — revert the one commit.

D-39 | Does `services/host/tests/Feature/HealthEndpointTest.php`'s "keeps the stock welcome
route inside the web group" test get deleted outright once FP-157 removes the welcome
route, or replaced with an equivalent probe? | Replaced, not deleted outright. The spec's
"Regression" section requires whoever implements this (`dev`, per file ownership) to
preserve what the test actually proves — that registering the two health routes outside the
`web` group does not replace whatever else `routes/web.php` declares (T-002 decision D-18)
— using a different probe now that the welcome route is gone. The exact replacement
mechanism (a dedicated placeholder route, a before/after route-count assertion, or
something else) is left to `dev`/`architect`. | Deleting the test outright would silently
drop the one thing standing between a future edit and the `then` callback in the health-route
registration code swallowing `routes/web.php`'s other routes — exactly the kind of silent
coverage loss this task's own brief calls its defining risk. A business-rule decision (what
must still be proven) rather than a mechanism decision (how), so it belongs here rather than
being left implicit. Reversible: yes — the replacement probe can be changed again later
without re-opening this decision.

## Correction round — architect's pre-planning measurements (2026-09-30)

Before writing the plan, `architect` measured three things against this spec and reported
them to the lead, who endorsed one proposed redesign and asked `ba` to correct the spec.
`architect` also independently re-checked D-34, D-35 and D-37 (group-tag mechanism, the
full gate's name, purpose-based toolchain-group membership) and confirmed all three hold
unchanged. D-40 and D-41 below record the two corrections that needed a decision; the third
(the `QaDocumentationAccuracyTest`/`QaCacheGuardBranchesTest` misattribution, and the
predicted "documented only" count dropping from 3 to 2) was a plain factual error with no
decision to make — it is corrected directly in the spec's "Current behaviour" and
"Regression" sections.

D-40 | AC-2 as first written ("full gate's test/assertion count equals baseline minus
exactly two, and by no other amount") forbids the task from adding the very regression
tests AC-3, AC-14 and AC-16 require, and forbids D-39's replacement probe from existing.
How should the "nothing vanishes silently" guarantee be restated so it does not also forbid
deliberate, named additions? | Adopted the architect's proposed restatement, endorsed by
the lead: prove the post-task test set **by name**, not by count — (the AC-1 baseline set,
captured as an itemised list of test names, not just a total) **minus** the two named
example tests **union** an explicit, named list of every test this task adds — checked by a
set difference against the AC-1 baseline report. | This is `architect`'s design, adopted
as-is rather than re-derived, because the lead already reviewed and endorsed it before
sending it to `ba`; re-deriving an equivalent mechanism independently would add nothing and
risks introducing a second, subtly different definition of the same guarantee. A named-set
difference is also strictly stronger evidence than a count (it catches "one test silently
removed, an unrelated one silently added" that a count comparison cannot), so it improves on
the original intent rather than merely fixing it. Reversible: yes — the baseline-capture
mechanism (AC-1) can be changed to a different enumeration method without reopening this
decision.

D-41 | The spec's first revision justified removing the closure-based `/` welcome route
(AC-13) and required `route:cache` to succeed (AC-14) as this task *fixing* a defect the
closure route caused. `architect` measured that premise false: `route:cache` already
succeeds today, closures included, and four closure-based routes exist once the host boots,
not one. How should AC-13/AC-14 and the surrounding scope be restated? | AC-13 (remove the
welcome route's closure) stands, but is now justified purely as scaffolding hygiene — the
same reason as the example tests and front-end build files — not as fixing route caching.
AC-14 is reframed from "route caching succeeds, the point of this row" to an **ongoing
regression guarantee**: an automated test pins that `route:cache` continues to succeed, so
this task's own scaffolding removal (or any later change) cannot silently break an
already-working command. The other three closure routes the architect found (framework-
registered, e.g. the `/up` health-check route) are explicitly **not** added to this task's
scope — they are not scaffolding this task owns, and nothing in this task's brief asked for
them to be touched. `README.md`/`config/servicehost.php`'s documentation, which still
asserts the false "fails outright" claim, is corrected by a new criterion, AC-16. |
Keeping AC-13 as a pure hygiene item (rather than deleting it) matches the backlog's
independent, standing reason for FP-157 ("retire the remaining stock framework
scaffolding") which does not depend on the route-caching premise at all. Reframing AC-14 as
a regression guarantee rather than a fix is the minimal correction that keeps the criterion
meaningful and testable without asserting something false about what this task does. Not
expanding scope to the other three closures avoids `ba` inventing a new deliverable nobody
asked for, in a task whose brief is explicit about staying infrastructure-only. Reversible:
yes — a later task can still choose to touch the framework-registered routes if a real need
arises.

## Second correction round — architect's plan review (2026-09-30)

`architect` resynced its plan against this spec and found the D-40 restatement of AC-2 still
collided with `ba`'s own D-39: a by-name set difference reads D-39's declared replacement of
`HealthEndpointTest`'s welcome-route test as one baseline disappearance plus one unrelated
appearance, which AC-2 as D-40 left it would flag as a violation of "nothing in the baseline
set disappears except the two named removals." One further item, a bare `AC-22` reference at
spec line ~405 that inherits its `T-003` prefix from two lines above, was also flagged as
easy to misread; corrected to `T-003 AC-22` directly in the spec, no decision needed.

D-42 | AC-2 (D-40's set-difference mechanism) has no category for a baseline test being
*replaced by name* rather than merely removed or merely added — exactly what D-39 requires
for `HealthEndpointTest`'s welcome-route probe. Does AC-2 gain a third category, or does
D-39's replacement get reclassified as a plain removal plus a plain addition? | AC-2 gains a
third, explicit category: a **declared rename pair** — a baseline test's old name retired and
a named successor put in its place, valid only where the plan or the implementing commit
states the pair and that the successor proves the same guarantee the original did. D-39's
`HealthEndpointTest` case is named as the (at minimum) example. Nothing is silently
reclassified as an ordinary removal or an unrelated addition. | Folding D-39's replacement
into "removal" would leave the baseline set missing a test with no explanation, which is the
exact silent-loss failure mode AC-2 exists to catch; folding it into "addition" alone would
leave the old, now-false test name looking as if it simply kept existing. A named category
that requires the pair to be declared, and requires the declaration to state *why* the new
test still proves what the old one did, is the minimum structure that lets a legitimate,
already-decided rename (D-39) coexist with a guarantee against undeclared disappearances.
Rejected: leaving the old test's description in place "to keep the diff tidy" — the lead
was explicit that this is not acceptable, since it would mean a test description naming a
route that no longer exists, which is exactly the false-documentation failure AC-16 exists to
delete, just relocated into a test file instead of `README.md`. Reversible: yes — the rename
category can be dropped again if no further tests need it.

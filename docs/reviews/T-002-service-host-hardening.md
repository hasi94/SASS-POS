# Code review — T-002 service host hardening

| | |
|---|---|
| Verdict | **APPROVE** (round 2; round 1 was CHANGES REQUESTED on one Critical) |
| Date | 2026-09-29 |
| Reviewer | `code-reviewer` (read-only) |
| Branch | `ai/T-002-service-host-hardening`, based on the T-001 tip |
| Gate, verified by the reviewer | `composer check` exit 0 — Pint passed, PHPStan level 6 no errors, Deptrac 0 violations, Pest **148 passed (749 assertions)**, 0 skipped |
| QA | run 1 PASS 19/19 with 3 defects raised, run 2 PASS, run 3 FAIL on one live criterion, final PASS |

T-002 closes the nine Major findings and several Minors from the T-001 review. The reviewer's verdict is that they are genuinely closed rather than touched.

---

## Round 1 — one Critical, since fixed

**R-1 | `SvcMakeDemoRegenerationTest` and `QaRegenerationGuardTest` | the regeneration check passed only by accident.**

It compared checksums of the committed demo service against a freshly generated one. The generator always writes LF. The committed tree is not guaranteed to: only `services/host/.gitattributes` exists, there is no root or `services/demo` equivalent, and `core.autocrlf` is true on the machine the project documents as the development environment. Both facts were confirmed independently by the lead.

It passed only because `services/demo` was written by the generator and had never been checked out from git. A fresh clone, or switching branches and back, would turn the whole gate red for someone who had changed nothing. That is the failure the automated check exists to prevent, reappearing as a false alarm.

Fixed by normalising line endings before hashing, in both owners' files.

## Round 2 — re-check

The reviewer scoped this to R-1 as agreed, plus a spot check of the three other items.

**The fix is proved in both directions, not asserted.** The suite now rewrites the demo service to CRLF to reproduce a fresh checkout, asserts the tree genuinely changed so the scenario is not a no-op, runs the real regeneration test against it in a child process, and then runs a negative control: raw hashing must report *exactly* the set of rewritten files as differences while normalised hashing reports the trees identical. That is what converts "it passes now" into "normalisation is what fixed it". Restoration is then verified byte for byte using raw hashing, which is correct there because it is a self-comparison.

**The rule generalised correctly across every hashing site.** Five helpers still hash raw bytes. The reviewer checked each and confirmed all five are strict before-and-after comparisons of the same tree by the same producer, where raw hashing is not merely safe but *required*: a test that silently rewrote line endings inside the host is exactly what those exist to catch. The stated rule, raw only for self-comparison and normalised whenever the two sides had different producers, holds with no gaps and is written into the code.

**Spot checks.** The generator's reminder now names both the source and test directories, with the parity check tightened to assert paths rather than words. The cache guard's inability to see `.env` is documented in three places with the operational instruction rather than just the fact. Provider resolution moved below the namespace check so a doubly-wrong manifest reports the namespace error first. The validation chain still has a single registration site, strictly below both trust gates.

One reclassification worth recording: the reminder finding was raised as Major but QA's widened check was genuinely red before the fix, which makes it a live acceptance-criterion failure that the original check was too loose to see.

---

## Carried forward

`FP-154` keeps its trigger condition, before the first non-first-party package loader, rather than a milestone date. `FP-155` is rescoped to act before the second service lands, group split first, no parallelism until the shared state these tests mutate is isolated. `FP-156` and `FP-157` are recorded.

**One refinement on FP-155, from measurement rather than opinion.** Two timings of the same suite differed substantially, 153 seconds cold against 71 warm, the difference being analysis cache warmth inside the child-process tests. The cold number is what continuous integration and any fresh machine pay, and is the real cost. Local timings will understate the problem while people run the gate repeatedly on a warm tree, so measure cold when this is picked up, and consider giving the child processes their own cache paths so the spread stops hiding the growth.

**Two things to revisit when the second real service exists.** The boundary rule's literal case is still only provable on a fixture, because the shipped configuration has one service layer and a service-to-service violation cannot be expressed against it; re-prove it directly at that point. And the coverage guard's fixture list is a deliberate maintenance point: a new test that generates a package must add its exact name or the guard goes red mid-run. That is the correct failure direction, and it is documented where someone will hit it.

## Closing assessment

Two things stood out beyond the individual fixes. Every guard added in T-002 has a negative control proving it can actually fail, including the fix to the reviewer's own finding, which both the developer and QA proved in both directions. And the team found two defects that no acceptance criterion reached, the smuggled second namespace and the abstract provider, and was straight about the fact that they were not criterion failures instead of retrofitting criteria around them.

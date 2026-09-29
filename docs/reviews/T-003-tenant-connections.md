# Code review — T-003 master and tenant connections

| | |
|---|---|
| Verdict | **APPROVE** (round 2; round 1 was CHANGES REQUESTED on one Critical) |
| Date | 2026-09-29 |
| Reviewer | `code-reviewer` (read-only) |
| Branch | `ai/T-003-tenant-connections`, based on the T-002 tip |
| Gate, verified by both reviewer and lead | exit 0 — Pint passed, PHPStan level 6 no errors, Deptrac 0 violations, Pest **279 passed, 1224 assertions**, none skipped. Baseline before T-003 was 148 |
| QA | run 1 FAIL (2 major), run 2 PASS, run 3 PASS after the reviewer's Critical |

T-003 converts the skeleton from a connection per module to one master connection plus one tenant connection resolved at runtime, following the v0.4 decision in `Refference/ARCH.md`. It is a security boundary, not a mechanism: a hole means one shop reading another shop's data.

---

## Round 1 — one Critical

**R-1 | the credential was still printed by the everyday debug helper, and the test proving otherwise measured the wrong object.**

The fix registered a redaction caster into a set of defaults. The framework builds its dumper during its own provider registration and captures a single cloner at that moment; a cloner copies those defaults at construction. The application's provider runs afterwards, so the registration mutated a list the live cloner had already read. Registration ordering was unwinnable.

The regression test built its own cloner, which is the one object the helper never touches, so it passed while the surface leaked. The lead's independent check was also a false negative: it captured the dump with output buffering, and the dumper writes to standard output, so it searched an empty string.

Three verification layers missed it for the same reason: each measured something other than the path production takes.

Also raised: teardown purged only the base connection name so a read or write variant kept a live connection to the previous shop's database (Major); a framework fact recorded as measured was contradicted by the source, in the safe direction (Major); a refusal message claimed no tenant was resolved when one was (Minor); and the development-only credential scheme matched the production environment by exact string, so a differently named production environment would have been permitted (Minor).

## Round 2 — all closed

**The fix chosen was the stronger option.** Both credential classes now hold no properties at all, with the value in a weak map keyed by the instance. Casting either object to an array yields nothing, so no inspector has anything to render but the redacted key. The guarantee no longer races provider registration and no longer depends on any hook being honoured. Cloning is refused, because a clone would otherwise coerce to an empty string and open a connection with a blank password. Proven load-bearing by returning the value to a property and watching the probe fail.

Two consequences: the export exposure previously accepted as residual is gone rather than merely unreachable, superseding that acceptance. And the credential map is a single static structure reachable by reflection, which the reviewer judged not a regression, since anyone able to run reflection in-process could already read the configuration or the environment, and the map is weak so it holds only what is live. Recorded for the next task.

**The rewritten test asserts three controls before it asserts anything about the credential**: that a handler is installed, that the capture is non-empty, and that an unprotected stand-in object's private value does come back. The last converts an absent needle from absence of evidence into evidence. QA found and discarded two further false-green shapes while building it, both green for reasons unrelated to the product, and recorded why in the test so they cannot be re-added as coverage.

**The teardown fix collided with a QA test** that asserted the unfixed behaviour as a precondition. The developer escalated rather than weakening the fix or editing another role's file. The lead ruled the fix stands; QA agreed and rewrote to the contract, which is stronger than either side started with.

**The false framework fact was corrected at every site and converted into an executing assertion**, and the defensive code it had justified was deleted once measurement showed it did nothing.

## Minor items, closed before commit

Three stale claims survived the fixes that disproved them: two in the developer's files including one in production source describing the load-bearing mechanism as something it is not, and one in QA's report. All were closed rather than deferred, because this pattern recurred five times in this task.

## Open, filed as follow-ups

The tenant marker is a public constant so the single-construction-point rule is a convention rather than an invariant (FP-161). Two escape hatches no connection-level guard can cover: a raw handle obtained from the connection, and a lazily evaluated result opened before a switch (FP-163). Consolidating the guard onto the connection factory binding, which would remove the doubled attachment, the de-duplication map and the test-only hole at once (FP-160).

## Gate time

The reviewer resolved the variance: 152 and 156 seconds cold against 91 warm, so the spread is cache warmth rather than noise and the cold number is what continuous integration pays. The tests that spawn child processes to run whole tools dominate it, not the tenancy tests. Its recommendation, unchanged: group those out of the inner loop, hoist the per-test database fixtures to per-file, never trim the credential tests, and do not parallelise until fixtures are namespaced per worker. FP-155 raised to the next infrastructure slot.

## Assessment

Every construction route was traced against the framework source and none escapes given a real dispatcher. Refusal ordering matches the acceptance criterion, so every refusal provably leaves the configuration byte-identical. Deny-by-default throughout: one permitted status, a driver allow-list applied twice, unknown credential schemes refused rather than falling back. The exception vocabulary always names the tenant and never the credential. Leaving a scope never throws, rolls back an open transaction and explains why rollback rather than purge is what clears the pending-transaction records, which is a subtle cross-tenant hazard caught in advance.

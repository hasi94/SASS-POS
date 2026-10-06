# Code review — T-005 rename services to modules

| | |
|---|---|
| Verdict | **CHANGES REQUESTED** (round 1) — three Criticals, all cheap, all one family |
| Date | 2026-10-02 |
| Reviewer | `code-reviewer` (read-only) |
| Branch | `ai/T-005-rename-modules`, based on `main`; five commits `99996d4`..`174061a` |
| Gate, measured by the reviewer independently | `composer check` exit 0 — **314 passed, 1414 assertions**, 136.21s. Matches QA and the lead |
| QA | run 1 PASS with BUG-1 and BUG-2, run 2 PASS after fixes, one new minor BUG-3 |

A rename is a diff where almost every line changed and almost nothing should have. Its defining risk is the inverse of T-004's: not that coverage silently shrank, but that something that was **not** a rename rode in disguised as one, or that a reference was rewritten where the old name was load-bearing, so the rename deleted an assertion instead of updating it.

The reviewer's summary: the rename itself is the best-executed large mechanical change on this project so far, and nothing in it is wrong. What is wrong is the **claim of completeness** attached to the BUG-1/BUG-2 fix, measured false with three live counterexamples, plus one missing exemption that would turn the gate red at this task's own release step.

`314 − 10 = 304` and `1414 − 56 = 1358`, so the rename accounts for zero change, as AC-2 requires.

---

## Verified by instrument rather than by stat line

- `CLAUDE.md` blob `b4577d9d7e…` identical on `main` and `HEAD` → **AC-25 holds**.
- `git diff --name-only -M main...HEAD -- Refference docs/specs docs/plans docs/qa docs/reviews docs/decisions` → **empty** → AC-8, AC-9 hold.
- `modules/host/config/services.php` blob `6a90eb8305…` identical across the move → the named trap survived untouched.
- Move commit `7775908`: **117 files, all `R100`, 0 insertions, 0 deletions**.
- `git log --follow` reaches `741307c` (T-001) on **all eight** files the combined `main...HEAD` diff reports as A+D. They fall below the 50% similarity default only in the *combined* view because they are content-heavy; the move commit records them as pure renames. This is exactly the failure the two-commit split was designed to prevent, and it works → AC-19, AC-20 hold.
- Positive control: `--follow` on `HostTestAnalysisEnforcementTest.php` shows only its five T-005 commits and nothing older.

**One thing the reviewer could not run:** being read-only it planted no probe, so R-1's red-gate consequence is derived from the probe's shipped heredoc content plus an assertion the existing test already makes about that exact content. It said so rather than implying it had run it.

---

## Critical findings

### R-1 — the probe registry is missing probes, and three planted paths are not ignored

The suite has **eight** `*ProbePath()` planters, not three. The registry in `tools/clean-probes.php` names three. Verified independently by the lead:

| planter | plants | registered | git-ignored |
|---|---|---|---|
| `hostStanProbePath()` | `host/tests/Feature/HostStanProbe.php` | yes | yes |
| `hostDeptracProbePath()` | `host/tests/Feature/HostDeptracProbe.php` | yes | yes |
| `hostDemoProbePath()` / `qaStanProbePath()` | `demo/tests/Feature/QaStanProbe.php` | yes | yes |
| **`deptracProbePath()`** | **`demo/tests/Feature/DeptracProbeTest.php`** | **no** | **NOT IGNORED** |
| **`hostSuiteProbePath()`** | **`host/tests/Integration/HostSuiteProbeTest.php`** | **no** | **NOT IGNORED** |
| **`qaCollectionProbePath()`** | **`host/tests/Integration/QaCollectionProbeTest.php`** | **no** | **NOT IGNORED** |
| `toolchainProbePath()` | `storage/app/toolchain-probe/ProbeTest.php` | no | yes (storage) — correctly out of scope |

`deptracProbePath()` is the one that bites hardest. `DeptracBoundaryEnforcementTest.php:86-101` plants a file declaring `namespace Flexipos\Module\Demo\Tests;` with `use App\Support\ModuleHost\ModuleRegistry;` — a `Module_Demo` class depending on `HostApp`. That same test then asserts at lines 105-109 that the shipped deptrac run exits non-zero naming `Module_Demo`, `HostApp` and `must not depend on`. **The test is itself the proof** that a surviving copy makes `composer deptrac` fail. Its cleanup is an `afterEach` plus a `finally` and no `beforeEach` — the exact shape QA raised as BUG-2.

Both failure modes just closed are therefore still open for this probe: a killed run leaves it and the next `composer check` aborts at `@deptrac`, step 3 of 5 (BUG-1); and `git add -A` commits a file carrying a deliberate boundary violation (BUG-2) — landing inside `modules/demo`, the one tree AC-17 compares byte for byte against a regeneration, which is the precise argument the developer wrote into the root `.gitignore` comment for the sibling file in the same directory.

Pre-existing from T-001/T-002, but so was BUG-2, which the developer accepted as in scope as a regression risk and fixed here.

### R-2 — the completeness guarantee is stated as closed and is not

Three places claim the registry is complete: the script's comment, the test's comment, and `174061a`'s commit message. All three are false. Both sides of the comparison are hand-kept literals in two files, and R-1's table shows three probes the suite already plants that neither list names. This is BUG-1's family exactly — a prose guarantee the code does not provide — and the one thing the developer's own newly recorded rule was written to prevent.

**Ruling on QA's BUG-3: close it now with the discovery half — but QA's proposed predicate does not work as written, and the corrected one is what finds R-1.**

QA proposed collecting literals matching `/['"][^'"]*Probe\.php['"]/`. Run as specified it returns **zero matches**, confirmed independently by the lead: the paths are assembled by `DIRECTORY_SEPARATOR` concatenation, and two of the planted files are named `*ProbeTest.php`, which the `Probe.php` anchor misses anyway. Shipped as specified it would pass vacuously — a dead guard, the same defect class it exists to fix.

The predicate that works is the **function shape**, which all planters share:

```
discovered = every function matching /function\s+\w*ProbePath\s*\(/ in *Test.php under
             modules/*/tests and packages/*/tests
assert:     every discovered path falling inside a PHPStan `paths` entry, a Deptrac
            `paths` entry, or a phpunit suite directory appears in `--list`
```

All planters are declared at collection time, so they can simply be called. `toolchainProbePath()` is excluded by the rule itself rather than by an exemption, which is the right shape.

**Why close now rather than defer to FP-172:** run against today's tree the rule goes red on three real paths. It is not a guard for a hypothetical future author — it is the instrument that finds the hole this branch still ships. QA's framing that BUG-3 needs someone to add a *new* probe before it bites understates it by exactly the amount that matters: it already bites. *A guard whose first run is red is worth having now; one whose first run is green is the thing you defer.*

### R-3 — `docs/releases/` is not exempt from the sweep, so this task's own release note turns the gate red

`qaVocabExemptPaths()` exempts `CLAUDE.md`, `Refference` and five of the six hand-off folders: `docs/decisions`, `docs/plans`, `docs/qa`, `docs/reviews`, `docs/specs`. **`docs/releases` is the sixth and is missing.** It exists and is tracked (`docs/releases/.gitkeep`), and it is swept.

A release note or PR description for T-005 must name `services/`, `svc:make`, `SERVICES` and `Flexipos\Service\` to describe what changed — every one a sweep token. The plan already commits to writing exactly that content there (plan R4, lines 189-190). **The gate is planned to be broken by the next role in this task.**

The exemption-liveness assertion is already satisfied, because the directory exists.

---

## Warnings

**W-1 — the token list cannot see four renamed test class names or three other old identifiers**, so AC-5's proof is narrower than it reads: the four renamed test classes, the singular `servicePath()` against the plural token, and `extra.flexipos.service` against a regex requiring the plural. Inherited from AC-4's own list, not a developer or QA error. The reviewer measured whether it matters: no survivor exists anywhere, and the four class names appear only in the developer's rename-recipe memory note where quoting the old names is the point. **AC-5 passes in fact.** Two more tokens would make the proof match the claim; a quoted-singular token is unsafe because `composer.lock` carries `"web service"` and `docs/database.md` carries `enum(user, terminal, service)`.

**W-2 — a conditional assertion makes the count move with collection**, on the one task whose gate metric is an unchanged count (QA's OBS-6). `if (function_exists('qaStanProbePath'))` yields 2 assertions in a full run and 1 standalone. The reviewer wants it fixed rather than noted, in a way that also removes the duplicated path literal R-2 is about: drop the second definition and the guard, and read the path out of the other file's source — the technique the sweep already uses for its witness records.

**W-3 — an `afterEach` deletes a whole directory, not a file.** `File::deleteDirectory(dirname(hostSuiteProbePath()))` removes `modules/host/tests/Integration` entirely after every case. The day a committed file lands there, a test run deletes it. Pre-existing, but FP-169 has just put that directory into PHPStan and Deptrac, so it now has more reason to hold real files. An FP row rather than a fix in this task.

---

## Suggestions

**S-1 — the `CLAUDE.md` hand-off structure was right; the gating is backwards.** Leaving the file byte-identical is correct and verified by blob SHA: a rename task must not rewrite the project's entry point behind its owner's back. But gating the correction on an acknowledgement that arrives *after* merge is a real hazard. Every agent is instructed to read `CLAUDE.md` first; between merge and acknowledgement it tells them to `cd services/host` nine times and its "Vocabulary note" paragraph positively asserts the rename has not happened. A stale comment misleads; a false instruction in the file that outranks everything else makes the next developer re-derive the old names. The plan already says the right thing (line 628: apply it in the same session the branch merges, not later) — only the enforcement is missing. Two changes: make step 8 a blocking release-task item and seek the acknowledgement **with** the PR rather than after it; and raise an FP row for a standing `QaDocumentationAccuracyTest` case over `CLAUDE.md` once the edit lands. Note the asymmetry that produced the gap: both READMEs have a standing accuracy assertion and `CLAUDE.md`, the most-read forward-looking document in the repository, has none. AC-25's byte-identity and an accuracy assertion are contradictory by construction *within this task*, which is why the assertion belongs to the task that applies the edit.

**S-2 — counterfactual statements about past tasks.** `ModuleMakeCommand.php:~131` now reads "T-002 put `modules/*/tests` into both registries"; T-002 put `services/*/tests`. Sanctioned by D-53 and harmless, but "(then `services/*/tests`)" keeps the archaeology honest at no cost.

**S-3 — OBS-7**, `probes:clean` guards `check` but not a direct `composer stan`; belongs with FP-172. If closed by prepending it to `stan` and `deptrac` too, `check` would run it three times (harmless, ~40ms) and the `$check[0]` assertion would need rewording.

**S-4 — `qaVocabFound()` reports only the first occurrence per label per file**, so "survives in N place(s)" under-reports. Fine as a go/no-go; `preg_match_all` would make the message actionable on the first run instead of the third.

**S-5 — the corpus includes untracked-and-not-ignored files**, so an untracked scratch note mentioning the old vocabulary turns `composer check` red. Deliberate, documented, and it already caught a real case — but it makes the gate depend on the working tree rather than the commit. Worth a line in the QA memory note.

**S-6 — one FP-171 nit introduced here:** `deptrac.yaml`'s new comment says "Measured: path only = 0 violations" with no date, where the sweep's docblock carries `measured 2026-10-02` and is the right model.

**S-7 — on `docs/backlog.md` line 36:** the reviewer checked for more of that family and found none introduced by this diff. The backlog diff touches only the FP-169 and FP-202 rows, vocabulary not status, as AC-26 requires. Line 36's stale measurements are pre-existing and untouched here.

---

## Done well

**The two-commit split is vindicated by measurement, not argument.** `7775908` is 117 files, all `R100`, zero insertions, zero deletions. Eight files fall below the rename-detection threshold in the combined view, and `--follow` reaches T-001 on every one of them anyway, because the path change carries no bytes. The reviewer called it the single most important engineering decision in the task.

**The lock was handled honestly under pressure.** `composer update --lock` failed because it re-resolves from a lock still pinning the old package name; the commit message says so, names the narrower fallback the plan pre-authorised, pastes the operation list, and confirms no third-party version moved. The reviewer verified the diff itself: content hash, the renamed block, and the removal of stale `connection: svc_demo` / `schema: pos_demo` keys T-003 had already dropped from the manifest. A declared, correct side effect that retires real pre-existing drift.

**Every named trap survived, and the best one survived by pattern rather than by exemption.** `config/services.php` is byte-identical *and* sits in the sweep's corpus with zero hits, which is strictly stronger than exempting it: the next person cannot widen a hole by editing a file nobody looks at.

**The `src/Services` comment** — "survived T-005's rename on purpose and must keep surviving… not a leftover for a later reader to finish the job on" — is the right defence against the follow-up mistake, and earns its line.

**The sweep's control 2 is a genuine improvement on the plan.** Proving the tokens against prose written *before* the token list existed, rather than against a synthetic string built from the same fragments, closes a tautology — and QA proved it necessary with mutation C3, where control 1 passed and the witness caught it.

**The allowance enumeration is correct where it counts.** The reviewer verified the load-bearing family independently: `git grep -niI 'svc'` across every tracked file outside the exempt set returns exactly 5 occurrences in 3 files. The three live assertions at `QaModuleConnectionRemovalTest.php:49,90,245` are intact and still assert the prefix is **absent**; renaming them would have deleted the assertion. QA's count of 3 files / 5 occurrences is right and the developer's "four files" was wrong. It also checked the inverse direction for inverted assertions — no `mod_` anywhere — and walked every negative assertion mentioning a new token. **On the question flagged as the best use of its time: the enumeration of load-bearing survivals is complete.** The gap is elsewhere, in the token list's reach (W-1) and the probe registry (R-1).

**The position assertion is the strongest form available**, and the reasoning in its comment ("being first is being before everything") is right. The redundant-looking `array_search('@stan', …) > 0` earns its place by also going red if `@stan` leaves the gate.

**`clean-probes.php` is the right shape for its job**: dependency-free, boots no framework, deliberately `tools/` and not `bin/` because the root ignore file has `**/bin/`, marked `--list` output because this machine emits a startup warning on stdout, and a STDERR report plus exit 1 when a probe cannot be removed rather than silent success. The only thing wrong with it is the three paths in its array, not the design.

**QA's report is the best on this project so far.** The planted-tree control run *before* the cleanup — PHPStan `Found 4 errors`, Deptrac `Violations 2`, then 314/1414 from the same untouched tree — is a positional proof rather than an assertion, and verifying the memory-file rename by blob identity rather than by a `| 0` stat is the right instinct. BUG-3 was correctly raised and correctly judged non-blocking against the acceptance criteria; the reviewer's disagreement is only with "it needs someone to add a new probe before it bites", and with the proposed predicate.

---

# Round 2 — re-check

| | |
|---|---|
| Verdict | **APPROVE** — zero Criticals |
| Date | 2026-10-02 |
| Scope | `git diff 174061a..HEAD` — the changed items only; the rename was approved on measurement in round 1 and not re-reviewed |
| Gate, measured by the reviewer | `composer check` exit 0 — Pest **314 passed, 1435 assertions**, 86.43s |
| Production source touched this round | **none**: `git diff --name-only 174061a..HEAD | grep -c "/src/"` → 0 |

R-1, R-2, R-3, W-1 and W-2 are all genuinely closed, each re-measured by the reviewer rather than taken from the reports. Four Warnings remain, all one family, all prose, none blocking — including two in the lead's own material and one in the reviewer's.

## The arithmetic reconciles further than anyone claimed

Per-class from the reviewer's own JUnit, the three classes that do not exist on `main` are `HostTestAnalysisCoverageTest` 1/12, `HostTestAnalysisEnforcementTest` 4/30 and `QaVocabularyRenameSweepTest` 5/35 = **10 cases / 77 assertions**. So `314 − 10 = 304` **and `1435 − 77 = 1358`** — both AC-1 dimensions land exactly on the baseline, and `1414 + 6 + 15 = 1435` confirms no pre-existing case moved. Split integrity re-verified: fast 257/980 and slow 57/455 union to the full run, intersection 0, **0 per-case assertion mismatches**.

## R-1 closed

Independent enumeration found **7** `*ProbePath()` declarations; six are in scope and the registry holds exactly those six, with the seventh (`storage/app`) excluded by rule. Measured both directions — a registry entry whose planter is deleted also goes red. All six are ignored, and in the right file for the right reason: the demo probes go in the **root** ignore file because `modules/demo` is the tree AC-17 compares byte for byte, so a `.gitignore` inside it would itself be the drift.

**"Nothing else plants into an analysed path" was checked by hand**, enumerating every `File::put`/`file_put_contents` under the test trees and resolving each target. Everything else writes to `storage/`, to generated fixture packages the walk skips, to mutate-and-restore on tracked files, or to a variant `phpunit-*.xml` that is neither analysed nor collected.

## R-2 closed, and better than the ruling asked for

The reader **evaluates** each discovered planter's target from source rather than trusting a name, and **throws** on a shape it cannot read, naming the function and both ways out. The failure direction is closed: an unreadable planter goes red, never green. The case carries a positive control on the reader *and* a positive/negative control on the scope rule, so "in scope" is a decision rather than a coincidence and the `storage/` exclusion is proven to be by rule. The `function_exists` loop became a **cross-check** of source-read against returned path, which the reviewer called a strictly better answer to W-2 than the one it proposed.

## R-3 closed, and W-1 — the reviewer was wrong and QA was right

All six hand-off folders are exempt, the liveness loop fails on a vanished path, and segment-not-prefix matching is pinned. On W-1 the reviewer confirmed on measurement that there are **five** old test class names, not the four it named, and that QA's refusal of a bare quoted-singular token was correct — finding a better counterexample than its own two, in ordinary prose in a file QA does not own.

## BUG-4 — the deferral ruling upheld, with a cost argument nobody had made

No `*ProbePath()` declaration exists outside a `*Test.php` file anywhere, so a widened walk's first run is green. The reviewer then added a measurement that strengthens the deferral independently: `Helpers.php` itself matches all six spawn tokens by design, so widening the walk would make `filesSpawningChildProcesses()` report it as a spawner and oblige T-004's registry to carry it. **The widening is not a two-word change; it reaches into another task's mechanism.** FP-175's trigger makes the deferral recoverable rather than forgotten.

It also endorsed keeping the two out-of-scope sibling comment corrections: fixing one copy of a false sentence and leaving two is the same defect with a smaller blast radius.

## Warnings (none blocks the merge)

- **W-1 — the lead's correction introduced a third wrong count.** The record figure was measured over four of the six folders the rule had just named, omitting `docs/decisions` and `docs/releases`. Measured at HEAD: **25 records across 31 tracked paths**, five each for T-001 to T-005, six `.gitkeep`. Not an arithmetic error but the same scope slip as BUG-4. **Closed by removing the count entirely** rather than refreshing it, since any absolute figure is wrong again at T-006; the by-folder rule and `qaVocabExemptPaths()` are the re-measurable form.
- **W-2 — QA's "so a seventh folder cannot be forgotten the same way"** is a guarantee a literal six-name pin cannot provide. The reviewer explicitly applied the BUG-4 standard rather than a stricter one: narrow the sentence, do not build the mechanism, because discovering "every `docs/` subfolder must be exempt" would be wrong and green on its first run.
- **W-3 — the gate-step ordinal is off by one** in `.gitignore:79` and a `beforeEach` comment: `check` is five steps with `@probes:clean` first, so deptrac is the **fourth**, and "third of five" sends a reader to `@stan`.
- **W-4 — the QA report understates its own result**, saying the assertion count "no longer lands on 1358" when it lands exactly. The weaker statement would have been inherited by the next reviewer.

## Suggestions

`docs/backlog.md`'s "about 104 seconds warm" was an undated absolute in prose — the same family as round 1's S-6 — now replaced with the measured range, run count and conditions. The T-005 status cell was stale at the time of review and is updated at DONE. Two nits on comment phrasing and a missing memoisation, neither a defect.

## The convention question — two rows, not one

Asked whether this task's recurring defect was FP-171 or a new one, the reviewer argued for **two rows**, because the trigger and the check differ. FP-171 is the **time** axis: true when written, decayed; trigger is a number; remedy is a date, a ratio or an assertion. The new one is the **extent** axis: false the moment it was written; trigger is a **scope word**; check is "which line makes this true, and what input makes it false", then run that input. One row collapses to "prose can be wrong", which is unactionable.

Its argument for making it a project convention rather than one role's memory is the distribution: **seven instances, four roles, zero self-caught** — four from the developer, one from the lead, one from QA, and one from the reviewer itself. The defect is invisible to its author by construction, so it is a cross-reading obligation. Filed as **FP-176**.

## Done well

The developer wrote the discovery assertion first and ran it red against the unfixed registry, naming exactly the three paths and no others, with QA reproducing it by restoring the three-entry script and verifying the restore by checksum — the sequence round 1 asked for and rarely gets done in that order. The scope rule is a rule rather than a list, reading PHPStan's, Deptrac's and phpunit's own configuration, and deliberately not subtracting phpunit's exclude list with the reason written down; the `storage/` probe needs no name anywhere, which is the test of the design. QA refused the reviewer's advice on measurement and was right twice, and caught its own vacuous pass by checking a count.

On the lead: breaking the gate, having it reported rather than silently fixed, fixing it by describing rather than widening the sweep, and then writing the incident into the row as the reason the row exists — the reviewer called that the protocol working in one small loop, worth more than the paragraph it produced.

## On the merge

The reviewer stated plainly that its APPROVE is a judgement on the code and **is not consent to merge**: `CLAUDE.md` is byte-identical at HEAD (blob `b4577d9d7e…`, verified) and the step-8 correction remains **unacknowledged by the human**. Per round 1's S-1 the hazard is unchanged — between merge and acknowledgement the file every agent reads first points `cd` into the old directory nine times and positively asserts the rename has not happened. Approved, and not merged, until that acknowledgement comes from the human.

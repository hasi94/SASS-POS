# Plan: T-007 Gate headroom and tenancy hardening

Spec: `docs/specs/T-007-gate-headroom-and-tenancy-hardening.md` (AC-1 to AC-45) · Decisions:
`docs/decisions/T-007-gate-headroom-and-tenancy-hardening.md` (D-1 to D-13) · Covers **FP-172**,
**FP-160** and **FP-161**, which gate FP-203 · Branch `ai/T-007-gate-headroom-and-tenancy-hardening`
from the T-006 tip (`cef6a17`), because `main` still lacks T-005 and T-006.

Everything numbered below was measured on this working tree on **2026-10-06** by `architect`, with the
command beside it, and is marked **MEASURED**. Where a measurement contradicts the brief, the spec or
the backlog, the contradiction is named rather than quietly corrected. Nothing here was run through
`composer check`: per the brief I ran single-purpose probes in the scratchpad and read the installed
framework source. Framework version in the lock file: **laravel/framework v12.69.2**, PHP 8.2.12.

**Second pass, 2026-10-07, after steps 0 and 1 landed** (`56d26ad`, `a560024`): the gate's own timings are
now `dev`'s, not mine, and every one of them carries a D-12 state label. R8 to R11 are the rulings that
came out of them; they supersede the two provisional figures step 1 carried (the limit's input and AC-45's
limit) and they retire one estimate of mine that turned out to be unmeasurable this way (R11). Read R8 to
R11 before step 1b or step 5. Nothing in this pass was run through `composer check` either — `qa` holds the
gate, and two concurrent runs delete each other's probe files.

---

## Approach summary

Four code steps, one per concern, each independently green and each its own commit — plus **step 1b**, a
small amendment to step 1 that D-12 and R9 made necessary after it landed.

**FP-172 goes first** because every later step pays for it: a developer agent runs the gate per step,
the first of those runs is cold, and the condition that produced the false red on 2026-10-06 is still
in force (`composer config process-timeout` → **300**, MEASURED). The fix is a project-owned timer:
`modules/host/tools/gate-step.php` wraps the Pest invocation of the `test` script, computes its own
budget from the limit actually in force, kills the child before Composer can, prints a fixed sentinel
line, and exits **3**. Composer's limit is raised at the same time so the project's timer is the
binding one rather than merely a smaller ceiling, and every completed run ends with one line stating
elapsed seconds, the limit and the ratio.

**FP-160 goes second**: `db.factory` is decorated, and the connection extension, the
`ConnectionEstablished` listener, the static `WeakMap` and the test-only hole all disappear with it.
MEASURED in the installed framework: every construction route in the spec funnels into
`ConnectionFactory::make()`, so one decoration covers all of them with no event dependency.

**FP-161 goes third and depends on the second**: the stanza authenticator is checked in exactly one
place, and that place has to exist before the invariant is written, or the check gets written twice
and then moved — the T-006 failure mode the lead named. The authenticator is **not** a secret value;
it is a per-`enter()` random token bound, through a private record on a scoped authority object, to a
digest of the whole stanza. Copying the stanza and changing the database breaks the binding, which is
what AC-14 asks for and what neither the backlog's remedy nor the brief's sentinel hypothesis
delivers (R1, with the measurement).

**No document is affected** (R4). `docs/database.md` §4.4 and `docs/architecture.md` stay byte-identical.

## Alternatives rejected

- **`pcntl_alarm()` for the step timer.** Rejected, MEASURED: `php -m` on this machine lists no
  `pcntl` (Windows), and `max_execution_time` does not apply to CLI.
- **A dependency-free `proc_open()` wrapper**, matching `tools/clean-probes.php`'s no-dependency rule.
  Rejected: MEASURED, `Symfony\Component\Process` kills the whole process tree on Windows
  (`taskkill /F /T /PID`, Process.php:1526), which a hand-rolled `proc_open` does not, and Pest spawns
  children of its own. `symfony/process` v7.4.19 is a **production** dependency of the framework
  (`require` of laravel/framework), so this adds no dependency.
- **One runner process for the whole gate** (`check` becomes a single PHP entry point with per-step
  budgets). Rejected: Composer's limit would then apply to the sum instead of per step, and the runner
  would have to re-implement Composer's script resolution or duplicate five command strings — a second
  authority for each command (FP-181).
- **Wrapping `lint`, `stan` and `deptrac` too.** Rejected for this task: AC-12 requires those three
  scripts unchanged. The residual (a kill of one of them still shows only Composer's message) is
  written down in R7 as a follow-up candidate, not silently accepted.
- **Raising the timeout only, with no sentinel.** Rejected by AC-44 and by the incident itself: the
  false red was *unreadable*, not merely early.
- **A sentinel object in the stanza, held in a private `WeakMap`** (the brief's hypothesis). Rejected
  with a measurement: it **accepts** the AC-14 forgery. See R1.
- **A random token per scope, compared instead of the constant** (the backlog's FP-161 remedy).
  Rejected for the same measured reason, and because a per-container-scope token would not rotate
  between two `enter()` calls in one process, failing AC-19.
- **An HMAC over the stanza with a per-scope key held on the scoped object.** Equivalent security to
  the chosen design, rejected as the more complex of two equals: it needs key material and key
  lifetime rules, and it cannot revoke a disclosed digest, whereas dropping a record can.

---

## Rulings the lead asked for

### R1. Q2 — AC-14 stands as written. The authenticator binds to the stanza's content, not to a secret value

**The requirement does not change. The mechanism does.** Both candidate mechanisms on the table fail
AC-14, and they fail it for the same reason: they try to make the authenticator's *value*
unobtainable, when the thing AC-14 forbids is a *valid authenticator beside a changed database*.

MEASURED, `scratchpad/m6-authenticator.php` run on PHP 8.2.12 (both designs implemented side by side,
same stanza, same forger):

| Attempt | Sentinel object in a private `WeakMap` (the brief's hypothesis) | Per-`enter()` token bound to a whole-stanza digest (chosen) |
|---|---|---|
| Legitimate stanza | verifies as `tenant-a` | verifies as `tenant-a` |
| **AC-14**: copy every value, change `database` | **verifies as `tenant-a`** — accepted | `NULL` — refused |
| AC-14 variant: inject a `read` split pointing elsewhere | not covered by the mechanism | `NULL` — refused |
| **AC-13**: forger mints its own authenticator | `NULL` — refused | `NULL` — refused |
| Stanza retained after the scope ended | `NULL` once the entry is unset | `NULL` — refused |
| Same tenant entered twice: value differs (AC-19) | no (identity is stable) | yes |

So the brief's hypothesis is refuted on the one criterion it was proposed for. The reason is not a PHP
subtlety: the authority of a sentinel object is its **identity**, and an array copy carries the same
instance. `config()` is readable in scope, the forger copies the array, the sentinel comes along.

**Correction to my own hypothesis as stated in the brief.** I expected the sentinel to fail on
revocation — "the retained dynamic configuration keeps the instance alive, so the `WeakMap` entry can
never be cleared". MEASURED: that is wrong. `unset($map[$sentinel])` removes the entry regardless of
who holds the instance, so a sentinel design *can* revoke. It fails on content binding instead.

**And the two hazards the brief told me to test it against are not where it dies, so they are recorded
as measured non-issues rather than left implied:**

- *`DB::build()` retains configuration a `purge()` cannot clear.* Confirmed on the installed version,
  MEASURED: `DatabaseManager::configuration()` reads
  `$this->dynamicConnectionConfigurations[$name] ?? Arr::get($connections, $name)` (DatabaseManager.php:218)
  and `purge()` only unsets `$this->connections[$name]` (:299). So a retained stanza is immortal and is
  *preferred* over `config()`. Both designs survive it, because both record the tenant id beside the
  authenticator and the per-query guard still compares against the live context. The chosen design is
  strictly better here: the record is dropped on scope exit, so a rebuild from retained configuration
  is refused by the authenticator itself and not only by the tenant-id comparison.
- *A dumper merges `__debugInfo()` beside real properties.* Irrelevant to either design, and
  deliberately so: under the chosen design **the authenticator's disclosure is harmless**. A token
  authenticates exactly one stanza — the legitimate one — so printing it, logging it or dumping it
  gains a forger nothing. That is why AC-16 is cheap to satisfy and why `config:show` may keep printing
  the stanza.

**The mechanism.** A new scoped `TenantConnectionAuthority`:

- `authorise(array $stanza, string $tenantId): array` — drops any previous record, mints
  `bin2hex(random_bytes(16))`, returns the stanza with that token under one key, and stores
  `[$token => ['tenant' => $tenantId, 'digest' => digest($stanza)]]` in a **private** property.
- `verify(array $stanza): ?string` — the token must be a key of that private array **and**
  `hash_equals()` must match a digest recomputed from the stanza it was just handed. Returns the
  recorded tenant id, or `null`.
- `revoke(): void` — empties the records. Called from `Tenancy::resetTenantConnection()`.
- `digest()` is canonical over the **whole stanza except the authenticator key**: `ksort`, recursive
  into arrays, objects encoded as `class#spl_object_id` and **never stringified** (so the credential's
  plaintext is never copied into a digest input and `ConnectionPassword::__serialize()`'s refusal is
  never triggered), then `hash('sha256', …)`.

Why whole-stanza rather than a whitelist of redirecting keys: MEASURED, `ConfigurationUrlParser`
expands a `url` key into host/database **before** the factory sees the stanza
(`DatabaseManager::configuration()` → `parseConfiguration()`), and `ConnectionFactory::make()` branches
to a read/write connection when `isset($config['read'])`. A whitelist would have to track every key the
framework can be redirected by; the whole stanza needs no list and goes stale for nobody.

What closes which forger (D-6): **F1** cannot mint a token that is in the private array. **F2** can copy
the token but not keep the digest valid while changing anything that decides which database is opened.
**F3** (reflection into the live scoped object) is not defended, by decision, and is written beside the
mechanism as AC-22 requires.

### R2. Q3 — the sentinel is printable. Composer does not kill before project code runs; the step owns its own timer

Feasible on Windows and, by construction, on POSIX. MEASURED end to end in the scratchpad with the real
`composer.phar` and the real `symfony/process`:

| Probe | Result |
|---|---|
| Composer kills a spawned script at the limit and prints its own message | yes; exit status **1**, indistinguishable from a test failure (confirms D-3's premise) |
| Child output written before the kill survives and is visible | **yes** — ticks 0 to 5 appeared before the kill; the pipe is streamed, so a sentinel printed by project code is readable |
| Detection latency with `process-timeout: 2` | the child ran ~3.0 s before Composer killed it: Composer is **late**, never early |
| Composer exports the limit to the child's environment | **no** — only `COMPOSER_BINARY` and `COMPOSER_DEV_MODE`. The wrapper must resolve the limit itself |
| `COMPOSER_PROCESS_TIMEOUT` is visible to the child when set on the command line | yes (ordinary environment inheritance) |
| Project timer inside `COMPOSER_PROCESS_TIMEOUT=2`: budget 1 s | sentinel printed at **elapsed 1.52 s**, wrapper exit 3, total wall 1.74 s standalone / inside Composer the step ended at 1.52 s, well before Composer's own limit |
| A composite script (`check`'s shape) after a step exits 3 | the following step does **not** run, and **exit code 3 propagates verbatim** to the shell |

So the shape the lead expected is the shape that works, with one measured addition: the exit code is
free (R3). The wrapper's budget must be below the limit in force, with a reserve that covers its own
boot plus Symfony's timeout-detection latency (MEASURED ≈ 0.6 s at a 0.25 s output cadence):

```
reserve = max(2, ceil(limit * 0.02))
budget  = max(1, limit - reserve)
```

A ratio with a floor, not an absolute (FP-171). At `limit = 2` the budget is 1 s, which is the case
MEASURED above; at `limit = 900` it is 882 s.

**One measured problem with AC-4 as worded, for `ba` — since CLOSED by `ba` as D-10, AC-4 reworded and
AC-45 added (`648145b`).** AC-4 said "the gate run with `COMPOSER_PROCESS_TIMEOUT=2`". With a 2 s
limit, Composer kills **`lint`** first — `check` runs `probes:clean`, `lint`, `stan`, `deptrac`, `test`,
each its own process, each with the same limit — and the test step never starts, so no sentinel is
printed and the criterion fails for a reason that has nothing to do with the sentinel. Two recipes are
therefore given to `qa` in the test strategy: the per-step form (`COMPOSER_PROCESS_TIMEOUT=2 composer test`,
seconds, deterministic) is now **AC-4**, and the whole-gate form (a limit derived from qa's own AC-1
numbers so that only the test step exceeds it) is now **AC-45**, whose limit R10 derives. The per-step form
is the one that always works; the window AC-45 needs is confirmed to exist on this machine by `dev`'s
cache-cold figures.

### R3. Q4 — the kill gets a distinct exit code **as well as** the sentinel, because the code turns out to be free

MEASURED: Composer propagates a script's exit status verbatim through a composite script
(`"composite": ["@first", "@killed", "@after"]` returned **3** to the shell and skipped `@after`). So
"an exit code would force a wrapper around every invocation" — the cost D-3 weighed — does not apply
here; the wrapper already exists, and the code costs one `exit(3)`.

Decision: **exit 3 on a kill by the project's own timer**, and the sentinel line stays the primary
discriminator exactly as D-3 requires. This does not contradict D-3, which requires the status to be
non-zero and permits it to equal a failure's status rather than requiring it. Ambiguity residual: if
Pest itself ever exits 3, the wrapper passes that 3 through **without** the sentinel, so the pair
(status, sentinel) is unambiguous and the sentinel alone is sufficient. Reviewer may overrule the code;
removing it is a one-line revert and the sentinel-based criteria stay green.

### R4. Q1 — no document is affected. `docs/database.md` §4.4 and `docs/architecture.md` are untouched

MEASURED, repeating `ba`'s search with the mechanism names this plan introduces: neither document
mentions the marker, the constant, `ConnectionEstablished`, the connection extension, the guard levels,
`beforeExecuting` or a choke point. The single overlap is **`docs/database.md` §4.4, line 262**, which
states that the resolver "calls `config()->set('database.connections.tenant', …)` + `DB::purge('tenant')`".

That sentence stays true, word for word:

- the only write to `database.connections.tenant` is still one `config()->set()` inside `Tenancy`, with
  the whole stanza replaced wholesale — the authority **returns** an array and never touches `config()`,
  which is a hard constraint of this design because `QaTenantIsolationProofTest`'s "exactly one
  production write" sweep asserts exactly one matching production file and names `Tenancy.php`;
- the teardown is still placeholder-`set()` plus `purge()`, unchanged by FP-160 and FP-161;
- the stanza gains one key, and §4.4 enumerates no stanza keys — the `…` in that sentence covers it.

So per AC-38 the plan says: **no document affected**, and `qa` repeats the search from the spec's
"Current behaviour" section as its evidence.

*Observation for the lead, not an edit and not mine to make:* §4.4 names the class
`TenantConnectionResolver`, which does not exist — the code calls it `Tenancy`. Pre-existing drift of the
FP-186 family, untouched by this task, worth a backlog row rather than a silent fix inside T-007.

### R5. Ordering is load-bearing: FP-172, then FP-160, then FP-161

- **FP-172 first** for a practical reason that has already cost this project a day: the limit that
  produced the false red is still 300 s (MEASURED), `dev` must run the gate at every step, and the first
  of those runs is cold. Fixing the gate before using it four times is the cheapest ordering available.
- **FP-160 before FP-161.** The invariant depends on the choke point. If the authenticator landed first,
  its check would have to be written into the connection extension **and** the `ConnectionEstablished`
  listener, and then moved when the choke point replaced both — two copies of a security check, briefly,
  and then a migration of one. That is precisely the shape the lead flagged from T-006: a guard written
  under one invariant becoming wrong when a later change moves which thing is authoritative.
- **A deliberate consequence, and the proof that the two rows are not one row:** AC-13 and AC-14 stay
  **red after FP-160 lands** and go green only in the FP-161 step. FP-160 consolidates *where* the check
  happens; it does not change *what* is checked, so an F1 forger who computes the tenant id still passes.
  `dev` must paste both results (red after step 3, green after step 4) into the step-4 commit message.

### R6. The new invariant is stronger than today's, and it breaks three existing assumptions. Named here, not discovered by `dev`

Today the stanza can be edited after `enter()` and the guard only checks one key of it. After FP-161
**the stanza is immutable once authorised: any post-hoc mutation of any key is a refusal.** That is the
point of the content binding, and it has consequences that must be in the plan rather than in a QA bug
report:

1. **`QaTenantIsolationProofTest.php`, the control at line 228** ("still reaches the driver when a tenant
   IS resolved") mutates `database.connections.tenant.database` to an unopenable path inside a scope and
   asserts the refusal is **not** a `TenancyException`, proving the guard let it through to the driver.
   Under the new invariant that mutation is itself the refusal, so the control must reach the driver
   another way: point the fixture **mapping** at the unopenable path *before* entering, then assert the
   refusal is `TenantConnectionFailed` and is **not** `TenantConnectionMismatch`/`NoTenantResolved` — the
   discrimination the control exists for ("the authenticator did not stop it, the driver did") survives
   intact. This edit is not in AC-41's allowed list and is pre-justified here so `qa` can report it as
   required rather than as a surprise.
2. **`packages/kernel/tests/Support/tenancy-fixture.php`, `tenancyComparableStanza()`** must substitute
   the authenticator by type, exactly as it already substitutes `password` by type and for the same
   stated reason: a per-`enter()` token makes two stanzas for the same tenant unequal under `toBe()`, and
   `TenancyTenantSwitchTest:84-100` compares them. AC-19 is asserted separately against the raw stanza, so
   nothing is lost. Also not in AC-41's list; pre-justified here.
3. **`QaCredentialSurfaceSweepTest.php`, lines 378-381** asserts that `config:show` printed the real
   stanza by looking for `flexipos_tenant` **and the tenant id `qa-cred-a`**. The tenant id is no longer in
   the stanza, so this positive control goes red whatever the key is called. It must prove the stanza was
   printed by a value that is still there and still not a secret — the username `qa_cred_app` — keeping
   the `ConnectionPassword::class` and no-credential assertions exactly as they are. Allowed by AC-41 (it
   reads the literal), and the reason for the edit is the missing tenant id, not the key name.

### R7. Residuals, written down because the sentence above is only true with them

Each belongs in the comment AC-22 requires, or in the QA report, as marked.

- **F3 is not defended** (D-6): reflection into the live scoped authority, or rebuilding the container,
  mints a valid authenticator. AC-13 and AC-14 are the proof of F1 and F2 and are named in the comment.
- **The mapping source is trusted.** `TenantDatabaseRepository` is built from `config('tenancy.databases')`;
  code that can rewrite that key before the singleton resolves can map any tenant id to any database. Not
  a forgery of the stanza and not in this task's threat model — FP-203 replaces the in-memory repository
  with the master-database reader — but it is the real floor under the isolation guarantee and belongs in
  the comment.
- **A genuine stanza can be rebuilt under a different connection name** (`DB::build(['name' => 'other', …])`
  with the stanza as-is). It reaches only the forger's own tenant, it is guarded per query like any other,
  and it escapes only the name-based teardown. FP-163 territory; comment only.
- **A third party registering a name-keyed extension for the tenant connection would bypass the choke
  point** (MEASURED: `DatabaseManager::makeConnection()` consults `$this->extensions[$name]` *before*
  the factory, DatabaseManager.php:195). Closed cheaply by a standing test asserting no extension is
  registered under the tenant connection name — included in step 3.
- **`spl_object_id` reuse** in the digest's object encoding requires the original object to be freed;
  `config()` holds a strong reference to the `ConnectionPassword` for the life of the record, so an id
  cannot be recycled while the record is live. Comment only.
- **A kill of `lint`, `stan` or `deptrac` still shows only Composer's message**, because AC-12 requires
  those scripts unchanged. The wrapper is written as a generic step runner, so applying it to them later
  is a one-line change per script. QA report, and a backlog candidate for the lead.
- **On POSIX, `Process::stop()` signals the direct child only** (Windows uses `taskkill /F /T`, MEASURED),
  so a Pest grandchild could briefly outlive a kill. `probes:clean` first in `check` is what makes that
  harmless, and AC-8 is the test.

---

## Rulings after step 1 — `dev`'s measurements, 2026-10-07

Four rulings: R8 and R9 on the two deviations `dev` declared that needed one (R9 also accepts the two that
did not), R10 on the AC-45 limit `ba` and the lead challenged, R11 on what `dev`'s spread measurement means
for every number in this plan. Each figure below is `dev`'s measurement with its D-12 state label, and every
piece of arithmetic in these four sections was **executed** on 2026-10-07 before it was written down — on
T-006 three arithmetic claims changed the moment somebody ran them.

### R8. The limit stays **900**. The rule is unchanged; D-12 moved its input

`dev` kept the provisional 900 against a rule that it read as yielding 600, and 900 is right — but for a
reason the plan can now derive rather than plead.

Step 1's rule is `limit ≥ (headroom-factor × S) / 0.98`, rounded up to a 300 s multiple, minimum 600. Its
input is S **as AC-2 defines S**, and D-12 redefined that after I wrote the rule: with no machine-cold
per-process run observed, AC-2's S is the cache-cold slowest step **times 3**. So the input is
`3 × 106 = 318` [state: CACHE-COLD × the D-12 factor], not 106:

- `(2 × 318) / 0.98 = 648.98` → next 300 s multiple → **900**. Only the input moved.
- **600 fails AC-2 outright**, not on taste: at 600 the limit itself is below `2 × S = 636`, and so is the
  budget it enforces (588).
- The choice does not rest on `ba`'s rounding of 2.8 up to 3. 600's budget clears `2 × 106 × f` only for
  `f ≤ 2.774`, so 600 fails at the unrounded 2.8 as well.
- 900's budget (882) clears `2 × S` for any single process up to **441 s**, so a machine-cold run that `qa`
  does observe is unlikely to move the limit again.

`dev`'s own justification — the session's first `composer check` at 240.87 s [state: MACHINE-COLD] against
87.34 s [state: WARM] — is what `ba` turned into D-12's factor, so it is now recorded in the spec rather
than argued in a manifest. It stays a **single observation of the whole gate**, not of one process (R11),
which is why the factor and not the figure is the input.

What this requires of the manifest: the key `cold-slowest-step-seconds` is now ambiguous — D-12 has two
colds — and the standing assertion built on it (`budget ≥ factor × cold` = 882 ≥ 212) is **weaker than
AC-2 now is** (882 ≥ 636). Step 1b fixes both. Until it lands, AC-2 is satisfied by the manifest's number
but not asserted by its test.

### R9. `source=` goes on every line the wrapper prints

Ruling: **add it.** One field, and one rule for where it goes: `source=` is the **last field before the
prose** on every line the wrapper prints. `FLEXIPOS-GATE-TIMEOUT` already satisfies that and does not
change; `FLEXIPOS-GATE-MARGIN` appends it after `ratio=`; `FLEXIPOS-GATE-LOW-HEADROOM` takes it after
`ratio=`, ahead of `below the required …`.

Two reasons, and the second is the stronger:

1. It moves `source=manifest` from covered-by-reading to covered-by-running, for one assertion inside a
   case that already spawns a child (`reads the limit from the manifest when none is forced`).
2. **The low-headroom line gives manifest-specific advice without saying whether the manifest was in
   force.** It prints `raise config.process-timeout` on a run whose limit came from the environment — which
   is precisely the run `dev`'s AC-10 case produces (`COMPOSER_PROCESS_TIMEOUT=8`). Naming the source is
   what makes the advice checkable by the reader being advised, and a reader on CI is the one who most needs
   to know which of the two they are looking at.

**Correction to the framing I was handed:** this does **not** make both labels assertable. The wrapper reads
`__DIR__.'/../composer.json'` (`gate-step.php:176`) and that path is fixed, so no test can make
`config.process-timeout` absent while the manifest carries it — and the manifest case asserts that it does.
`source=default` stays covered by reading, on whichever lines carry the field. **One label moves, not two.**
`dev`'s residual paragraph is right about `default` and should keep saying so; what it must stop saying is
that `manifest` is in the same position.

Accepted without change, both of `dev`'s other deviations: the **plain hyphen** in place of my em dash (the
better call — nothing in the gate's output now depends on a non-ASCII byte on a Windows console), and the
**exit-2 malformed-invocation case** beyond my list, with its correct-invocation control. Add that case to
the test strategy; the wrapper's exit space is 0-or-the-child's-status, 3 on a kill, 2 on a misuse, and all
four are now asserted.

### R10. AC-45's limit is derived from a ratio, and **53 is withdrawn**

My recipe 4 said `ceil(1.2 × A)` and so named 53. It sits 1.21x above a stan sample nobody has repeated.
Replaced by the geometric mean of the window's two ends:

```
A     = longest CACHE-COLD wall time of lint, stan, deptrac   (worst of three samples)
T     = CACHE-COLD wall time of the test step
limit = round(sqrt(A × T))
```

That is the limit with **equal relative headroom on both sides** (`limit / A = T / limit`). On `dev`'s
figures, `round(sqrt(43.86 × 105.46)) = 68`: 1.55x above stan and 1.55x below the test step.

Why a mean of the ends rather than a factor on one of them: it is **valid against the numbers it is derived
from whenever a window exists at all**. `A < sqrt(A × T) < T` for every `A < T`, and `budget < limit` for
every limit above 1, so the recipe can never produce a limit **below the measured A** or a budget **above
the measured T** — the two ways a chosen limit is wrong on its own figures. `1.2 × A` exceeds T once
`A > T / 1.2`, and `0.8 × T` falls under A once `A > 0.8 T`; both ends can therefore yield an invalid limit
from valid numbers, and the mean cannot. What the recipe cannot do is protect against a step running slower
on the day than the worst sample it was derived from; that is the case AC-45's invalid-run clause covers,
and it is why `qa` reports both margins.

`qa` takes the slowest analysis step **CACHE-COLD three times** and uses the worst as A, because 43.86 is
one sample of the one step nobody has repeated (R11), and reports both margins in seconds —
`limit − A` and `T − budget`. At stan +30 % (57.02) the recipe gives 78 and stays valid; at A = 100 against
T = 105.46 it gives 103 with margins of 3.00 s and 5.46 s, thin because the window is thin, which is the
case AC-45's "invalid run, not a product failure" clause exists for.

### R11. Measurement discipline: one timing is not evidence, and one of my estimates was never measurable this way

`dev` measured the gate's warm wall time five times consecutively: 85.59, 117.83, 91.44, 120.10, 95.98 s
[state: WARM, 2026-10-07] — a range of 34.51 s and a standard deviation of 14.11 s on a mean near 102. So
**no difference of two single timings smaller than about 35 s is evidence of anything on this machine**, and
that rule applies to anything this task claims:

- The wrapper's own cost, 0.65 s measured as a consecutive bare-against-wrapped pair, is an order of
  magnitude below the spread. `dev` drew the right conclusion: **no performance claim about this change in
  either direction**, and none is needed — FP-172 is headroom and visibility, not speed.
- **My ≈1.9 s per-invocation Composer boot figure stays an ESTIMATE, and the five-vs-one gap does not
  confirm it.** Five separate warm invocations summed 96.58 s against one `check` at 87.34 s, a gap of
  9.24 s over four extra boots (≈2.31 s each) — but the gap is a difference of two single timings inside a
  ±17 s band, which is the same objection `dev` correctly raised against its own 0.65 s. The figure is also
  not needed: recipe 1 takes S as the **largest per-step wall time**, each of which includes that step's own
  boot, so S is if anything conservative and nothing in the limit's derivation depends on the boot cost at
  all. `composer --version` (0.21 s) is not a proxy for it either, as `dev` found; do not substitute that
  number.
- **Single samples, all of them, and each labelled as such where it is used:** stan cache-cold 43.86 s,
  test step cache-cold 105.46 s, the machine-cold gate run 240.87 s. R10 asks `qa` to repeat the first;
  D-12 turns the third into a factor rather than an input.

---

## Measured surface — what the code does today

MEASURED by reading the installed framework and the current sources.

| Fact | Where |
|---|---|
| Every construction route funnels into `ConnectionFactory::make()`: `connection()` → `makeConnection()` → `$this->factory->make()`; `connectUsing()` → `$this->factory->make()`; `build()` → `connectUsing()` | DatabaseManager.php:93, 117, 147, 185, 203 |
| The factory is **injected into the manager at construction** (`__construct($app, ConnectionFactory $factory)`), so the decoration must be registered before `db` is resolved, and the decorator must be a subclass of `ConnectionFactory` | DatabaseManager.php:75 |
| A name-keyed extension is consulted **before** the factory | DatabaseManager.php:195 |
| `configuration()` prefers `$dynamicConnectionConfigurations[$name]` over `config()`, and `purge()` never clears it | DatabaseManager.php:218, 299 |
| `ConfigurationUrlParser::parseConfiguration()` returns the stanza unchanged when there is no `url` key, and pulls/expands `url` when there is | Support/ConfigurationUrlParser.php |
| `ConnectionFactory::make()` adds `prefix` and `name` **inside** `make()`, after an override's check would have run, and branches to read/write on `isset($config['read'])` | Connectors/ConnectionFactory.php:42, 60 |
| The per-query hooks live in `Connection::$beforeExecutingCallbacks` and `Connection::$beforeStartingTransaction` | Connection.php:197, 204 |
| `'db.factory'` is a plain singleton closure, so `extend()` composes | DatabaseServiceProvider.php |
| **The decoration really reaches the manager on this application**: extending `db.factory` and then booting the host, `DatabaseManager`'s injected `$factory` is the decorator (`decoration landed: true`) | `scratchpad/m7-factory-extend.php`, real `bootstrap/app.php`, MEASURED today |
| The marker is written once and read at three places; tests read it at five | `Tenancy.php:51,327,364`; `TenancyServiceProvider.php:143,242`; `TenancyResolutionTest:56`, `TenancyTenantSwitchTest:103`, `TenancyCredentialLeakTest:275`, `QaTenantIsolationProofTest:198`, `QaCredentialSurfaceSweepTest:241,381` |
| `composer config process-timeout` in `modules/host` **at the T-006 tip** | **300** (no key in the manifest; Composer's default). Re-run by `dev` at the tip on 2026-10-07: **300**, confirmed |
| Analysis caches the cold definition must remove | `modules/host/storage/framework/cache/phpstan/` (directory) and `storage/framework/cache/.deptrac.cache` (named in the `deptrac` script). No PHPUnit result cache is configured and no `.phpunit.cache` exists; `bootstrap/cache/config.php` is cleared by the `test` script itself |
| `tools/` is inside the PHPStan paths (level 6) and the Pint targets, and is **not** in the deptrac paths | `phpstan.neon.dist`, the `lint` script, `deptrac.yaml` |
| `ToolchainGroupCoverageTest` asserts `test` and `check` carry no `--group`/`--exclude-group`, and that `test:fast`/`test:slow` do | ToolchainGroupCoverageTest.php:44-68 |
| A new test file that spawns a child process **must** be listed in `toolchainGroupFiles()`, or the registry sweep fails | `modules/host/tests/Helpers.php:56`, ToolchainGroupCoverageTest layer 2 |
| `QaTenantIsolationProofTest` asserts **exactly one** production file writes `database.connections.*`, and names `Tenancy.php` | QaTenantIsolationProofTest.php:463 |
| `QaTenantIsolationProofTest` reflects on `TenancyServiceProvider::$guardedConnections`, so removing the map turns that test into a `ReflectionException` | QaTenantIsolationProofTest.php:665 |

## Files to change / create

Owner in brackets. `dev` owns production sources and the non-`Qa*` tests; `qa` owns `Qa*` test files,
fixtures and `docs/qa/` — the repository's existing convention, kept because step 3 and step 4 would
otherwise be blocked by red tests `dev` may not touch.

**FP-172**

- `modules/host/tools/gate-step.php` — **new** [dev]. The project's own timer: resolves the limit in
  force, kills the child at its budget, prints the sentinel, prints the margin line last, exits 3 on a
  kill. Contract in step 1. **Touched again in step 1b**: `source=` on all three lines, and the residual
  paragraph corrected to claim `default` alone (R9).
- `modules/host/composer.json` — [dev]. `config.process-timeout`; `extra.flexipos.gate` (the one
  authority for the cache-cold measurement, its date, the machine-cold factor and the headroom factor); the
  Pest entry of `scripts.test` wrapped; `scripts-descriptions.test` extended to point at that authority.
  `check`, `lint`, `stan`, `deptrac`, `test:fast`, `test:slow` and `dev` are untouched (AC-3, AC-12).
  **Touched again in step 1b**: the gate key set becomes state-explicit (R8).
- `modules/host/tests/Feature/GateStepTimerTest.php` — **new** [dev]. Wrapper behaviour, the spawned
  children plus the manifest assertions. **Touched again in step 1b**: the `source=` assertions and AC-2's
  own arithmetic.
- `modules/host/tests/Helpers.php` — [dev], one line: register the file above in `toolchainGroupFiles()`.
  Mechanically required; report it under AC-41.

**FP-160**

- `packages/kernel/src/Tenancy/TenantConnectionFactory.php` — **new** [dev]. The choke point.
- `packages/kernel/src/Tenancy/TenancyServiceProvider.php` — [dev]. Register the authority and the
  decoration; delete the extension, the listener, `$guardedConnections`, `guardEveryQuery` (moved) and
  the now-unused imports; rewrite the class docblock's level story.
- `packages/kernel/tests/Feature/TenantConnectionChokePointTest.php` — **new** [dev].
- `packages/kernel/tests/Unit/TenancyFrameworkContractTest.php` — [dev]. One new case: the framework
  contract the choke point depends on, in the file's existing "if this fails, the framework changed"
  idiom.

**FP-161**

- `packages/kernel/src/Tenancy/TenantConnectionAuthority.php` — **new** [dev]. Mint, verify, revoke,
  canonical digest, and the AC-22 residual comment.
- `packages/kernel/src/Tenancy/Tenancy.php` — [dev]. Authorise the stanza on write, revoke on reset,
  delete `TENANT_MARKER`, replace `configuredTenantId()` with a context read, update the docblock.
- `packages/kernel/src/Tenancy/Exceptions/TenantConnectionMismatch.php` — [dev]. `forConfiguration()`
  becomes `forUnauthorisedConfiguration(string $tenantId)`.
- `packages/kernel/tests/Feature/TenantStanzaForgeryTest.php` — **new** [dev].
- `packages/kernel/tests/Feature/TenancyResolutionTest.php`, `TenancyTenantSwitchTest.php`,
  `TenancyCredentialLeakTest.php` — [dev]. The three marker-reading assertions (AC-41 allowed).

**qa-owned**

- `packages/kernel/tests/Support/tenancy-fixture.php` — [qa]. `tenancyComparableStanza()` tolerance (R6.2).
- `packages/kernel/tests/Feature/QaTenantIsolationProofTest.php` — [qa]. The retention control (AC-26),
  the AC-27 replacement, the rewritten driver-reach control (R6.1), the marker-reading stanza at line 198,
  the AC-23 table and AC-30.
- `packages/kernel/tests/Feature/QaCredentialSurfaceSweepTest.php` — [qa]. Lines 237-241 and 378-381 (R6.3).
- `docs/qa/T-007-gate-headroom-and-tenancy-hardening.md` — **new** [qa].

**Not touched:** `docs/database.md`, `docs/architecture.md`, `CLAUDE.md`, `docs/backlog.md`,
`Refference/`, `composer.lock`, any migration, any schema, any CI file (D-5).

## Data / API changes

No schema, no migration, no endpoint, no master or tenant table.

- **The tenant connection stanza** loses the key holding the tenant id and gains one key holding a
  per-`enter()` token: `flexipos_tenant_authenticator`, a private constant of
  `TenantConnectionAuthority`. No other class names that key; the factory asks the authority whether a
  stanza carries one. The tenant id is no longer in the stanza at all — the authority's private record
  holds it. `docs/database.md` §4.4 enumerates no stanza keys, so nothing published changes (R4).
- **`Tenancy::TENANT_MARKER` is deleted** (AC-20). Nothing outside `packages/kernel` referenced it
  (MEASURED).
- **`TenantConnectionMismatch::forConfiguration(string, ?string)` → `forUnauthorisedConfiguration(string)`**.
  The forger-supplied value is no longer echoed into a message (there is no trustworthy configured tenant
  to name), and the sentence `Tenancy is the only code allowed to write that configuration` is kept
  verbatim so the existing assertion keeps its meaning.
- **`Tenancy`'s constructor gains `TenantConnectionAuthority`**, bound `scoped` beside `TenantContext`
  and `Tenancy` itself. Nothing outside the kernel constructs `Tenancy` (MEASURED).
- **Configuration**: `modules/host/composer.json` gains `config.process-timeout: 900` (R8) and
  `extra.flexipos.gate` with `cache-cold-slowest-step-seconds`, `machine-cold-slowest-step-seconds`,
  `machine-cold-factor` and its provenance string, `headroom-factor`, `measured-on`, `measured-by` and
  `remeasure-with` — the state-explicit key set of step 1b; step 1 shipped the single `cold-…` key that
  D-12 then made ambiguous. MEASURED: nothing reads the host manifest's `extra` (the module registry reads
  only module manifests, and the host's own `extra` is asserted by no test), so the key is free to add.
  It is the **single authority** for those numbers (FP-181), the wrapper and the ratio test both read it,
  and it carries its own date and state (FP-171, AC-11, AC-42, D-12).

---

## Implementation steps

Owner in brackets. **One commit per step** — on this project a developer agent lost all uncommitted work
three times before that became the rule. Every step states its gate expectation; a step marked
**RED FIRST** must show the new test failing *before* the production change and the failure must be
pasted into the commit message, because a guard whose first run is green is deferred, not shipped (AC-40).

- [x] **Step 0 — Baseline, nothing changed [dev]. DONE 2026-10-07; figures below are `dev`'s.**
      In `modules/host`: `composer config process-timeout`; `composer check` warm, recording the exit status
      and Pest's `Tests:`/`Assertions:` line for AC-34; then time the five steps individually
      (`composer probes:clean`, `composer lint`, `composer stan`, `composer deptrac`, `composer test`)
      to give `qa` a per-step profile and the window it needs for AC-45. Keep the numbers in the
      scratchpad, out of the repository, and hand them to `qa`.

      **What it returned** — `process-timeout` **300**, no key in the manifest, as MEASURED by `architect`.
      Baseline gate: **exit 0, 87.34 s** [state: WARM], **314 passed / 1435 assertions** — the AC-34 and
      AC-2-at-the-tip baseline. Per step, in `check`'s order:

      | step | WARM | CACHE-COLD |
      |---|---|---|
      | `probes:clean` | 1.70 | 1.26 |
      | `lint` | 3.91 | 1.92 |
      | `stan` | 3.33 | **43.86** |
      | `deptrac` | 2.05 | 2.15 |
      | `test` | **85.59** | **105.46** |

      All seconds, all 2026-10-07, cache-cold per recipe 1's definition and measured under a raised limit so
      that the then-current 300 could not kill the cold test step and turn the measurement into the very
      false red this task exists to prevent. Cache-cold S = **106** (the test step, rounded up). Also
      observed, once: the session's **first** gate run at **240.87 s** [state: MACHINE-COLD] — the figure
      `ba` turned into D-12's factor of 3. **Read R11 before using any of these in an argument**, and note
      that my ≈1.9 s Composer-boot figure is retired to an estimate there. *Gate: green. No commit.*

- [x] **Step 1 — FP-172: the gate's own timer and a sentinel on a kill [dev]. DONE 2026-10-07, `56d26ad`
      and `a560024`.** Shipped as specified below, with the four deviations ruled on in R8 and R9: the limit
      stays 900 (R8), `source=` is to be added to every line (R9, step 1b), the plain hyphen and the extra
      exit-2 case are accepted as improvements (R9). AC-45's limit of 53 named below is **withdrawn** —
      see R10.
      *Covers AC-2, AC-3, AC-4 (**RED FIRST**), AC-5 (wrapper half), AC-6, AC-9, AC-10, AC-11, AC-12,
      AC-42, AC-44.*
      Write `GateStepTimerTest.php` first and run it: with no `tools/gate-step.php` on disk the kill
      case cannot produce the sentinel, which is AC-7's statement about the T-006 tip made executable.
      Then build it:

      `modules/host/tools/gate-step.php`, invoked as `php tools/gate-step.php <label> -- <command…>`:
      - `declare(strict_types=1)`, requires `../vendor/autoload.php` and nothing else, no framework boot;
      - limit resolution, in order, with the chosen source named in the output so nobody has to guess:
        `COMPOSER_PROCESS_TIMEOUT` when it is a positive integer → `config.process-timeout` from the
        `composer.json` beside it → `300` (Composer's documented default);
      - `reserve = max(2, ceil(limit * 0.02))`, `budget = max(1, limit - reserve)` (R2, with the
        measured justification in the file header);
      - runs `[PHP_BINARY, ...$command]` from the host directory with `Process` timeout `budget`,
        streaming stdout and stderr through unchanged so Pest's output is unaltered;
      - the three lines, in their final form — **`source=` is the last field before the prose on every one
        of them** (R9), and the separator is a plain hyphen rather than an em dash so that nothing in the
        gate's output depends on a non-ASCII byte on a Windows console (`dev`'s call, accepted in R9):
      - on `ProcessTimedOutException`: `stop(1.0, 9)`, then **one** line and `exit(3)`:
        `FLEXIPOS-GATE-TIMEOUT step=<label> at=<ISO-8601> elapsed=<n>s budget=<n>s limit=<n>s source=<env|manifest|default> - the gate ended this step on its own timer before the process limit was reached; NO TEST RESULT WAS PRODUCED.`
      - on completion: when `limit / elapsed < headroom-factor`, print
        `FLEXIPOS-GATE-LOW-HEADROOM step=<label> ratio=<n> source=<env|manifest|default> below the required <n>x (D-1); raise config.process-timeout - see extra.flexipos.gate in modules/host/composer.json.`
        then **always last** (AC-9):
        `FLEXIPOS-GATE-MARGIN step=<label> at=<ISO-8601> elapsed=<n>s limit=<n>s ratio=<n> source=<env|manifest|default>`
        and exit with the child's own status;
      - the sentinel token appears on no other path, which is what makes AC-5 and AC-6 true by
        construction rather than by assertion;
      - every duration in the file header is a ratio or carries its date and the command that
        re-measures it (AC-11).

      `modules/host/composer.json`:
      - `scripts.test` becomes `["@php artisan config:clear --ansi", "@php tools/gate-step.php test -- vendor/bin/pest"]`.
        `config:clear` stays its own sub-second process, outside the measured step;
      - `config.process-timeout`: **900**, chosen by the rule
        `limit ≥ (headroom-factor × S) / 0.98`, rounded up to a 300 s multiple, minimum 600 — the
        0.98 is the budget reserve, so the **budget** clears 2 × S and not merely the limit. S is AC-2's S,
        which D-12 now defines as the machine-cold figure when one is observed and the cache-cold slowest
        step **times 3** when none is: `(2 × 3 × 106) / 0.98 = 648.98` → **900**, and 600 would fail AC-2
        outright. **Ruled in R8**, which also says why the manifest must name which cold it holds (step 1b).
        `qa` re-measures after the final code change and moves the limit if the ratio test fails (AC-43).
        The incident's figures are motivation, never evidence;
      - `extra.flexipos.gate`: `cold-slowest-step-seconds`, `measured-on`, `measured-by`,
        `remeasure-with` (the exact command), `headroom-factor: 2`. **Superseded by step 1b**: D-12 split
        "cold" into two states after this step landed, so the key set and the assertion built on it both
        change there;
      - `scripts-descriptions.test`: say that the step owns its own timer, that a kill prints
        `FLEXIPOS-GATE-TIMEOUT` and exits 3, and that the limit's justification lives in
        `extra.flexipos.gate` — one authority, every other mention pointing at it (FP-181).

      `GateStepTimerTest.php` (new file, **must** be added to `toolchainGroupFiles()` in the same commit —
      it spawns children, and the registry sweep fails otherwise): a child outlasting the budget →
      sentinel present, exit 3, the line naming the step, the limit and "no test result"; a fast child
      exiting 0 → margin line last, no sentinel, exit 0; a child exiting 1 → margin line, no sentinel,
      exit 1; a child slower than half the limit but inside the budget → low-headroom warning then the
      margin line last, exit 0; and the manifest assertions: `config.process-timeout` is a positive
      integer `≥ headroom-factor × S` (**step 1b.3** replaces the single cold key with D-12's S and adds
      the same assertion on the budget), no `check`/`test`/`test:fast`/`test:slow`
      entry and no `config` key disables the timeout, `extra.flexipos.gate.measured-on` parses as a date,
      `check` still lists its five steps in order, `test:fast`/`test:slow` still carry their group flags.
      Each spawned case forces its own tiny limit through the child's environment, so the whole file runs
      in seconds; the slow, whole-gate forms of AC-4 to AC-8 and AC-45 are `qa`'s.
      *Gate: green.* Commit `T-007: FP-172 give the test step its own timer and a sentinel on a kill`.

- [ ] **Step 1b — Name the source on every line, and name which cold the manifest holds [dev].**
      *Covers AC-2 (standing assertion), AC-9, AC-11, AC-42. Rulings R8 and R9. Touches only
      `modules/host/tools/gate-step.php`, `modules/host/composer.json` and
      `modules/host/tests/Feature/GateStepTimerTest.php`, none of which steps 2 to 4 touch, so it can land
      beside them — but it must land **before** step 5, because `qa` reads the manifest for AC-2 and AC-43.*

      Three small changes, each with its assertion:

      1. **`source=` on the margin and low-headroom lines** (R9), in the final form given in step 1 above:
         last field before the prose. Then assert it where the branch is already exercised —
         `source=manifest` in the case that forces no limit, `source=env` on the margin line of a case that
         forces one. Both directions are then covered by running. `source=default` stays covered by reading:
         the wrapper's manifest path is fixed (`gate-step.php:176`), so no test can make
         `config.process-timeout` absent. **Correct the wrapper's residual paragraph accordingly** — it
         currently groups `manifest` with `default`, and after this change only `default` belongs there.
      2. **The manifest's gate key set becomes state-explicit** (R8, D-12). `cold-slowest-step-seconds`
         → `cache-cold-slowest-step-seconds: 106`; add `machine-cold-slowest-step-seconds: null` (no
         per-process machine-cold figure has been observed — the 240.87 s run is a whole-gate figure, R11)
         and `machine-cold-factor: 3` with a sibling string naming where that 3 comes from (D-12, rounded up
         from 240.87 s [state: MACHINE-COLD] against 87.34 s [state: WARM], 2026-10-07, `dev`), so the
         borrowed number carries its provenance as AC-11 requires. `headroom-factor`, `measured-on`,
         `measured-by` and `remeasure-with` keep their names and values.
      3. **The standing assertion becomes AC-2** rather than something weaker than it. In
         `GateStepTimerTest`'s manifest case, compute
         `S = machine-cold-slowest-step-seconds ?? (machine-cold-factor × cache-cold-slowest-step-seconds)`
         — D-12's sentence, executable — and assert `limit ≥ headroom-factor × S` **and**
         `budget ≥ headroom-factor × S`, keeping the existing `budget < limit`, `limit % 300 == 0` and
         `limit ≥ 600` assertions. At the shipped values that is `882 ≥ 636`, where the assertion today
         demands only `882 ≥ 212`. The `??` has a live branch and a dead one: the fallback is what runs
         while nobody has measured a per-process machine-cold figure, and the observed branch is covered by
         reading until someone does — say so beside the assertion rather than implying both are tested.
      Also: the `scripts-descriptions.test` sentence says "cold measurement"; make it "cache-cold" so the
      description uses D-12's vocabulary. It still carries no duration, which its own assertion checks.
      *Gate: green, counts = step 1's (no new case; three cases gain assertions).*
      Commit `T-007: FP-172 name the limit's source on every line, and which cold the manifest holds`.

- [ ] **Step 2 — Two test edits that must land before the mechanisms move [qa].**
      *Covers AC-26; unblocks steps 3 and 4.*
      Both are green at the T-006 tip **and** after the whole change, which is why they can land first —
      and both are deliberately not red-first criteria: AC-26 is the positive control that replaces a test
      pinning the thing being removed (D-9).
      1. `QaTenantIsolationProofTest.php:665` — replace the `ReflectionProperty(TenancyServiceProvider::class,
         'guardedConnections')` test with AC-26's retention control: twenty-five switches, each querying, a
         `WeakReference` to a connection from an earlier switch released after `gc_collect_cycles()`.
         Without this edit, step 3's removal of the map turns a `Qa*` file into a `ReflectionException` and
         `dev` would have to edit a file it does not own to get through the hook.
      2. `packages/kernel/tests/Support/tenancy-fixture.php` — `tenancyComparableStanza()` substitutes the
         authenticator key by type when it is present, exactly as it already does for `password` and for
         the same reason (R6.2). Written so it is a no-op today (the key does not exist yet), so this
         commit is green before and after step 4.
      *Gate: green, counts = baseline.* Commit `T-007: FP-160 replace the map-pinning test with a retention control`.
      If `qa` is unavailable, `dev` makes both edits inside step 3 and reports them line by line under
      AC-41 — but message `qa` first; this is `qa`'s file.

- [ ] **Step 3 — FP-160: one construction choke point [dev].**
      *Covers AC-21 (both refusal classes), AC-23 (dev half), AC-24, AC-25, AC-27 (**RED FIRST**), AC-28,
      AC-29 and AC-31 (retained), plus the two installation invariants.*
      The marker's semantics are **unchanged** in this step — the stanza still carries the tenant id — so
      the commit is one concern, and AC-13/AC-14 stay red at the end of it by design (R5).

      `TenantConnectionFactory` (new): `final class TenantConnectionFactory extends ConnectionFactory`,
      constructed with the application and **the factory it replaces**, overriding
      `make(#[SensitiveParameter] array $config, $name = null)`:
      - delegate straight to the inner factory for any connection that is neither the tenant connection
        name nor a stanza carrying the tenancy key — the second half of that test is what stops
        `make($legitimateStanza, null)` producing an unguarded tenant connection, and it costs one array
        key lookup (R7). In this step "the tenancy key" is still the marker; in step 4 the factory asks
        the authority `carries($stanza)` instead, so the key name lives in exactly one class;
      - placeholder driver, or no tenant resolved → `NoTenantResolved::forConnection($name)`;
      - a stanza the authenticator does not vouch for, while a tenant **is** resolved →
        `TenantConnectionMismatch` (AC-21). In this step that is still "the marker is absent or names
        another tenant"; step 4 swaps the condition and nothing else;
      - the driver whitelist, moved verbatim from the extension;
      - `return self::guardEveryQuery($this->inner->make($config, $name), …)` — `guardEveryQuery` moves
        here from the provider, with the `WeakMap` de-duplication deleted, because one choke point means
        one attachment (AC-24, AC-25);
      - `#[SensitiveParameter]` on `$config`, as the extension has today (the stanza holds the resolved
        credential and this closure raises the refusals).
      Delegating to the inner factory rather than calling `parent::make()` keeps any other extender of
      `db.factory` composed instead of silently discarded; extending `ConnectionFactory` is still required,
      because `DatabaseManager::__construct` type-hints it (MEASURED).

      `TenancyServiceProvider`: `$this->app->extend('db.factory', …)` in `register()`, **before** anything
      can resolve `db`; delete `registerTenantConnectionGuard`, `registerTenantConnectionEstablishedGuard`,
      `$guardedConnections`, `guardEveryQuery`, the `callAfterResolving('db')` hook and the `WeakMap` and
      `ConnectionEstablished` imports (AC-25, AC-28); rewrite the class docblock so the level story names
      the factory decoration as level 2 and says what was removed and why.

      `TenantConnectionChokePointTest.php` (new): the seven routes of the spec's list as a table —
      `DB::connection('tenant')`, an Eloquent model on the tenant connection, `DB::build()` with the tenant
      name, `DB::connectUsing()`, `app('db.factory')->make($stanza, 'tenant')`, reconnect after `purge`, and
      a read/write-split stanza — each run with no tenant, with a stanza Tenancy did not write, and with a
      legitimate stanza (AC-23); the hook count via reflection on `beforeExecutingCallbacks` and
      `beforeStartingTransaction`, exactly one of each (AC-24); `ReflectionProperty(…, 'guardedConnections')`
      throws `ReflectionException`, plus a source search of `packages/kernel/src/Tenancy/` finding no
      `WeakMap` holding a `Connection` and no `ConnectionEstablished` reference (AC-25, AC-28);
      **AC-27 red first** — `Event::fake()` with a connection built through `DB::build()`/`connectUsing()`
      and queried after a switch throws `TenantConnectionMismatch`; the AC-23 table repeated under
      `Event::fake()` with identical results (AC-28); and the two installation invariants — the resolved
      `DatabaseManager`'s injected factory **is** ours, and no name-keyed extension is registered under the
      tenant connection name (R7). Each guard row has the paired positive control AC-40 requires.

      `TenancyFrameworkContractTest.php`: one case in the file's existing idiom — `DatabaseManager` still
      takes a `ConnectionFactory` by constructor injection, `makeConnection()` and `connectUsing()` still
      call `$this->factory->make()`, and `configuration()` still prefers `dynamicConnectionConfigurations`.
      If the framework moves any of those, the choke point claim is false and this names it in one read
      instead of leaving a silent hole.
      *Gate: green. AC-13 and AC-14 are expected to be **red** here — record that in the commit message.*
      Commit `T-007: FP-160 one construction choke point for the tenant connection`.

- [ ] **Step 4 — FP-161: an authenticator bound to the stanza [dev].**
      *Covers AC-13 (**RED FIRST**), AC-14 (**RED FIRST**), AC-15, AC-16, AC-17, AC-18, AC-19, AC-20,
      AC-21, AC-22, AC-30, AC-39.*
      Write `TenantStanzaForgeryTest.php` first; AC-13 and AC-14 must fail against the step-3 tip with the
      **intended** exception absent and a tenant-B row actually returned, and that output goes in the
      commit message. Then:

      `TenantConnectionAuthority` (new, `scoped`): `authorise()`, `verify()`, `revoke()` and the private
      canonical `digest()` exactly as specified in R1. Hard constraints: it never calls `config()` (the
      single-write sweep), it stores only two strings per record and never the stanza (no credential object
      retained past the scope, AC-26/AC-30), it never logs or interpolates a token, and the comment beside
      it states the F3 and mapping-source residuals and names AC-13/AC-14 as the proof of F1 and F2 (AC-22).

      `Tenancy`: `writeTenantConnection()` builds the stanza without the tenant id and writes
      `$this->authority->authorise($stanza, $database->tenantId)` through the one existing
      `config()->set()`; `resetTenantConnection()` calls `revoke()` **after** the purge loop and the
      placeholder write, so the rollback of an open transaction still runs against a live connection
      (AC-31); `configuredTenantId()` becomes a context read with the existing `'unknown'` fallback — the
      log line keeps saying `tenant-a`, which `TenancyCredentialLeakTest` asserts; `TENANT_MARKER` deleted;
      docblock updated to describe the authenticator and to keep the `set()`-plus-`purge()` paragraph
      §4.4 depends on.

      `TenantConnectionFactory`: the one condition changes — `verify()` returning `null` is the refusal,
      and the tenant id for the per-query guard comes from the authority's record rather than from the
      stanza.

      `TenantConnectionMismatch`: `forConfiguration()` → `forUnauthorisedConfiguration(string $tenantId)`,
      keeping the "only code allowed to write that configuration" sentence and carrying no forger value
      and no credential (AC-30).

      `TenantStanzaForgeryTest.php` (new): AC-13 (F1 — the published source plus `TenantContext::tenantId()`,
      no valid authenticator); AC-14 (F2 — copy **every** value including the token and change `database`,
      then as separate rows `host`, `username`, and an injected `read` split); both run against every route
      in step 3's table (AC-15); AC-16's surface search over `TenantContext`, `Tenancy::current()`, every
      refusal message and every log record the guard writes; AC-19 — the same tenant entered in two scopes
      produces different tokens and the first scope's stanza is refused in the second; AC-21's two classes;
      AC-39's control — the identical set-up with the stanza untouched succeeds and returns tenant A's row,
      and every forgery test names the exception class and stage it expects.

      The three AC-41-allowed edits: `TenancyResolutionTest:56` and `TenancyTenantSwitchTest:103` stop
      asserting the tenant id in the stanza and assert instead that the authority vouches for it and that
      the value is not the tenant id; `TenancyCredentialLeakTest:275` becomes the F1 forgery with the new
      message. **AC-19 is answered "yes, per `enter()`"**, so the spec's conditional clause applies as
      written and needs no substitute.
      *Gate: green.* Commit `T-007: FP-161 bind the tenant stanza to an authenticator Tenancy alone can mint`.

- [ ] **Step 5 — The proof suite and the measurements [qa].**
      *Covers AC-1, AC-2, AC-5 to AC-8 (whole-gate forms), AC-10, AC-23, AC-27, AC-28, AC-30, AC-32,
      AC-33, AC-34, AC-35, AC-37, AC-38, AC-39 to AC-45 — AC-45 included, and it is the one criterion
      whose limit `qa` derives rather than reads (recipe 4, R10).*
      `QaTenantIsolationProofTest.php`: the AC-23 table in qa's own words; the AC-27 replacement (the
      declining paragraph at line 627 becomes an assertion, its comment rewritten — the two assertions it
      already makes stay green throughout); the rewritten driver-reach control (R6.1); the line-198 stanza;
      AC-30's credential-free refusal for every row. `QaCredentialSurfaceSweepTest.php`: R6.3.
      AC-32's mutation runs in a scratch copy — the decoration removed, then the guard attach commented
      out — with AC-23, AC-27 and AC-28 failing in both. The cache-cold measurements with the slowest
      analysis step sampled three times, recipe 3 for AC-4, recipe 4 for AC-45, the AC-8 re-run and the
      AC-33 cache-cold `composer check` per the test strategy below. The report lists every
      edited test file line by line (AC-41), accounts for every digit followed by `s`/`sec`/`second` in the
      changed files (AC-42), tags every duration with its D-12 state and date, and repeats the §4.4 search
      (AC-38, R4).
      *Gate: green, cache-cold, sentinel absent, with the AC-9 line recorded.*
      Commit `T-007: QA proof suite and the cold gate measurement`.

- [ ] **Step 6 — Hand-off [lead].** `docs/backlog.md` rows FP-160, FP-161 and FP-172 are the lead's to mark
      after the reviewer approves (AC-36). Nothing in this task edits the backlog, `CLAUDE.md`,
      `docs/database.md` or `docs/architecture.md`.

---

## Test strategy

**Unit**
- `TenancyFrameworkContractTest` — the four framework facts the choke point rests on, in the existing
  "if this fails the framework changed, not us" idiom.
- `GateStepTimerTest`'s manifest cases — the limit and the budget both clear `headroom-factor × S` with S
  computed the way D-12 defines it (step 1b.3); the cache-cold measurement carries a parseable date and a
  re-measurement recipe; no script disables the timeout; the five steps and the two group flags are intact.
  This is how AC-2, AC-3, AC-11 and AC-42 become **standing assertions** instead of prose that decays
  (FP-171's own remedy).

**Feature / integration**
- `GateStepTimerTest`'s spawned cases — kill, pass, failure, a child exiting 3 of its own accord, low
  headroom, the limit-resolution control in both directions, and a malformed invocation exiting 2 with a
  correctly-invoked control beside it (`dev`'s addition, `a560024`, accepted in R9: the wrapper's exit space
  is 0-or-the-child's-status, 3 on a kill, 2 on a misuse, and all four are asserted). Seconds,
  deterministic, no whole-gate run. The file joins the `toolchain` group.
- `TenantConnectionChokePointTest` — seven routes × three states, twice (real dispatcher and `Event::fake()`),
  plus hook counts, absence proofs and the two installation invariants.
- `TenantStanzaForgeryTest` — F1 and F2 against every route, the surface search, token rotation, both
  refusal classes, and the legitimate control.
- `QaTenantIsolationProofTest` / `QaCredentialSurfaceSweepTest` — qa's independent table, the retention
  control, the credential surfaces.

**Measurement recipes for `qa` (every one re-runnable, every number dated and state-labelled per D-12)**

Read R11 first. Every figure below is a wall time on a machine whose gate has a measured ~30 % run-to-run
spread, so a recipe that rests on the difference of two single timings is not a measurement. Where a number
is used as an input to a limit, take it more than once and use the worst.

1. **Cache-cold definition (AC-1).** Delete `modules/host/storage/framework/cache/phpstan/` and
   `modules/host/storage/framework/cache/.deptrac.cache`; record the paths removed and confirm no
   PHPUnit result cache exists. Then run the five steps in `check`'s order, each timed
   (`date +%s%N` in Git Bash, `Measure-Command` in PowerShell), and record each wall time tagged
   `[state: CACHE-COLD]`, the exit status and the date. **Repeat the slowest of `lint`, `stan` and `deptrac`
   three times** (delete the caches again before each) — it is the input to recipe 4 and `dev`'s 43.86 s is
   one sample of a step nobody has repeated. The cache-cold slowest step is the input to AC-2's S; AC-2's S
   itself is that figure **times 3** unless `qa` observed a machine-cold run, which it reports per AC-1 and
   must not claim unseen. Write the cache-cold slowest step into
   `extra.flexipos.gate.cache-cold-slowest-step-seconds` with today's date and re-run the manifest
   assertion (AC-2, AC-43). Do **not** quote a per-invocation Composer boot cost: each per-step time already
   contains its own boot, which makes S conservative, and the figure itself is unmeasured (R11).
2. **AC-2's "(diff)" number at the T-006 tip.** `git stash` or a scratch worktree at `cef6a17`, then
   `composer config process-timeout` → **300** (MEASURED by `architect` 2026-10-06, re-run by `dev`
   2026-10-07, same value), against a cold step that exceeded it on 2026-10-06 → a ratio below 1. Report
   the number, not the reasoning.
3. **AC-4, per-step form (always works).** `COMPOSER_PROCESS_TIMEOUT=2 composer test` in `modules/host`:
   budget 1 s, the sentinel appears at ≈1.5 s, exit 3. MEASURED in the scratchpad with the real
   `composer.phar` before this plan was written.
4. **AC-45, the whole-gate form (CACHE-COLD).** `COMPOSER_PROCESS_TIMEOUT = round(sqrt(A × T))`, where A is
   the **worst of recipe 1's three samples** of the slowest analysis step and T is the cache-cold test step
   — the geometric mean of the window's ends, ruled in **R10**, which also says why it cannot produce an
   invalid limit while a window exists. My earlier `ceil(1.2 × A)` and the 53 it yielded are **withdrawn**:
   53 sits 1.21x above a single stan sample. On `dev`'s figures the recipe gives **68** (1.55x above stan,
   1.55x below the test step) — an illustration from `dev`'s numbers, not the limit; `qa` derives its own
   and states it with the figures and states it came from. Report both margins in seconds, `limit − A` and
   `T − budget`. Per AC-45, an analysis step that overruns makes the **run invalid** — re-pick the limit
   from that run's numbers and re-run, reporting the discarded run — not a product failure. If A ≥ T there
   is no window at all: record that and fall back to recipe 3, with the measured reason (R2: at a 2 s limit
   Composer kills `lint` and the test step never starts).
5. **AC-8.** Force the kill mid-suite — about half the test step's measured time — so probe-planting tests
   have run, then `composer check` with the normal limit and expect exit 0 with no surviving probe.
6. **AC-10.** `COMPOSER_PROCESS_TIMEOUT ≈ 1.5 × the test step's measured time` (≈129 s on `dev`'s warm
   figure): the step completes at ≈67 % of the limit, inside the 98 % budget, so the low-headroom warning
   fires and the status is 0. With the normal limit the warning must be absent. **Do not reuse recipe 3's
   limit of 2 here.** The warning needs `limit / factor < budget`, so with `reserve = max(2, …)` the window
   is empty at or below 4 and is only 0.50 s wide at 5 — hence the spec's "at least 5 s" floor (D-13).
   `dev`'s fast case uses 8 (budget 6, threshold 4, child 4.5 s, a 2.00 s window), which is the smallest
   value with a window wider than the measured detection latency; at the whole-step scale of this recipe the
   window is ≈61 s and the floor never binds.
7. **AC-33, AC-43.** One cache-cold whole `composer check` after the final code change: exit 0, sentinel
   absent, AC-9's line pasted into the report; the same for a machine-cold run if one was observed. AC-33
   never depends on a duration (D-12).

**Which tests prove which criteria**

| Criterion | Proof |
|---|---|
| AC-1, AC-2 (number), AC-33, AC-43 | qa's cache-cold runs, recipes 1 and 7, plus a machine-cold run if seen |
| AC-2, AC-3, AC-11, AC-12, AC-42 (standing) | `GateStepTimerTest` manifest cases, with step 1b.3's S |
| AC-4, AC-7, AC-44 | `GateStepTimerTest` kill case (fast) + recipe 3 |
| AC-45 | recipe 4, on the branch, CACHE-COLD, after AC-1 (pending until then, not failed) |
| AC-5, AC-6 | `GateStepTimerTest` failure and pass cases; sentinel absent by construction |
| AC-8 | recipe 5 |
| AC-9, AC-10 | `GateStepTimerTest` margin, low-headroom and exit-2 cases + recipe 6 |
| AC-13, AC-14, AC-15, AC-16, AC-19, AC-21, AC-39 | `TenantStanzaForgeryTest` |
| AC-17, AC-18, AC-30, AC-31 | `TenancyResolutionTest`, `TenancyTenantSwitchTest`, `TenancyCredentialLeakTest`, `QaCredentialSurfaceSweepTest`, qa's table |
| AC-20, AC-22 | source search plus AC-13 passing; the comment, by reading |
| AC-23, AC-24, AC-25, AC-27, AC-28 | `TenantConnectionChokePointTest` + qa's independent table |
| AC-26 | step 2's retention control |
| AC-29 | the retained T-003 purge test, unchanged |
| AC-32 | qa's mutation runs |
| AC-34, AC-35, AC-37, AC-38, AC-41 | the QA report against step 0's baseline |
| AC-36 | the lead, after approval |

**Criteria red at the T-006 tip, by step** — AC-4 in step 1, AC-27 in step 3, AC-13 and AC-14 in step 4,
and AC-13/AC-14 are **still red at the end of step 3**, which is the evidence that FP-160 did not
accidentally close FP-161.

## Risks & rollback

| Risk | Mitigation / detection | Rollback |
|---|---|---|
| Something resolves `db` before `TenancyServiceProvider::register()`, so the manager keeps the undecorated factory and the choke point is absent | Measured not to happen on today's boot (`decoration landed: true`, and that probe extended *later* than a provider would), and the installation invariant test in step 3 asserts it on every run — it fails loudly rather than leaking quietly | bind earlier in `register()`; worst case re-register `db` itself |
| A framework upgrade adds a key between `configuration()` and the factory, or renames `make()` | Fails **closed**: every tenant connection is refused and the whole tenancy suite goes red on the first `composer update`. `TenancyFrameworkContractTest`'s new case names the cause in one read | widen the digest's excluded keys, or pin the framework |
| A third party registers a name-keyed extension for the tenant connection and bypasses the factory | The standing test in step 3 | remove the extension; the test names it |
| `process-timeout: 900` lets a genuinely hung step hang for 15 minutes | Accepted (R8): the limit is what AC-2 requires once D-12's S is the input, and 600 fails that criterion outright. The wrapper's budget (882 s) is the binding limit for the test step, and the margin line reports the ratio on every completed run; `lint`, `stan` and `deptrac` are unwrapped, so a hang in one of those is the 15-minute case (R7's residual) | lower the limit **and** the factor or the state it is derived from, together; the manifest assertion tells you how far |
| AC-45's limit is derived from single samples on both sides, so a thin window reads as a product failure | Recipe 4's geometric mean cannot produce an invalid limit while a window exists (R10); `qa` samples the analysis side three times and reports both margins; AC-45 itself declares an analysis overrun an invalid run | widen the window by re-measuring, or fall back to recipe 3 and record why |
| Pest's own exit code 3 read as a kill | The sentinel is the discriminator (D-3); a child's 3 arrives with the margin line, not the sentinel | drop the distinct code (one line) |
| A POSIX kill leaves a Pest grandchild running | `probes:clean` first in `check`, proved by AC-8 | none needed; stated as a residual |
| The per-`enter()` token makes a stanza comparison in some future test non-deterministic | `tenancyComparableStanza()` substitutes it by type, the precedent the credential already set | the helper is one function |
| Colour or formatting of Pest's output changes because the wrapper sits between Composer and Pest | `dev` eyeballs one run; if colour is lost, add `--colors=always` to the wrapped command | one argument |
| Steps land out of order and the authenticator check is written twice | R5; the step order is a criterion of this plan, not a preference | revert step 4 alone: the choke point stands without it |

Each step is one commit, so `git revert` of step 4 restores the marker while keeping the choke point,
`git revert` of step 3 restores both hooks, and step 1 is independent of both.

## Questions for the human

None. Three things for the lead's queue rather than the human's, one of them already closed:

1. `docs/database.md` §4.4 names `TenantConnectionResolver`, a class that does not exist — the code calls
   it `Tenancy`. Pre-existing drift, untouched by this task (R4), worth a backlog row.
2. **Closed by `ba`** in `648145b` and `e148283`: AC-4 is now the per-step form and AC-45 the whole-gate form
   (D-10), which is what I asked for; AC-45's window is confirmed to exist on this machine by `dev`'s
   cache-cold figures and the limit is `qa`'s to derive (R10). Nothing outstanding for the human here.
3. A third thing for the lead's queue, raised by R9 and not mine to fix: `tools/gate-step.php` stamps
   `at=` with `gmdate()`, so the sentinel's timestamp is UTC while `measured-on`, the commit dates and a
   developer's clock on this machine are +05:30. It is valid ISO-8601 and unambiguous, which is why I am not
   asking for a change, but a reader correlating a sentinel with a commit is off by five and a half hours,
   and near midnight the sentinel's date and `measured-on`'s date disagree. One line in the wrapper's header
   saying "UTC" would settle it; the reviewer's call, not a defect.

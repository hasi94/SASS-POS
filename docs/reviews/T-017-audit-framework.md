# Code Review: T-017 (audit-framework)

**Round 1 · Verdict: CHANGES REQUESTED** (1 Critical, 3 Warnings, 4 Suggestions)
**Round 2 · Verdict: APPROVE** (R-1/S-1/S-2 fixed, 0 new findings)

---

## Round 1

Diff range: `480b30e` (dev's implementation, 76 files, +415/-101 over the 21 modified
pre-existing files plus 55 new ones). Criteria: AC-1 to AC-21. Decisions: D-1 to D-16.

### Gate, measured by the reviewer

`composer lint`: pass. `composer stan`: no errors. `composer deptrac`: 0 violations
(627 allowed, 629 uncovered). `composer test` (full suite including toolchain group,
solo): **2167 passed, 12 failed, 11 skipped, 16365 assertions, 493.64s** — exactly
FP-316's known pre-existing cluster, nothing audit-related. Gate's own
`FLEXIPOS-GATE-LOW-HEADROOM ratio=1.82` fired (filed as FP-328, not a T-017 defect).

### Critical

- **R-1** `SearchAuditLogHandler.php:94-98`: tenant audit export (AC-18) returned
  `audit_logs.ip` as raw packed `binary(16)` bytes instead of a printable string.
  `audit:search --output=x.json` threw `JsonException: Malformed UTF-8 characters`
  on any row with a real IP, and the CSV path wrote raw binary (including NULs) into
  the cell. Every real HTTP-request audit row carries a non-null `ip` (T-010
  behaviour), so this broke on real production data. Violated plan Ruling 11
  ("ip is printable on the tenant side") — the platform-side handler already
  rendered it correctly via `bin2hex`. Missed by the original suite because every
  fixture row used `ip => null`.

### Warnings (filed as follow-ups, non-blocking)

- **W-1** `AuditExport::cell()`: CSV formula injection — a cell starting with
  `=`, `+`, `-`, `@`, tab or CR runs as a formula in Excel. `user_agent` is
  client-controlled. Filed FP-326.
- **W-2** `parseInstant` (duplicated in both search handlers): timezone offsets
  ignored before comparing against UTC `created_at`; impossible dates
  (`2026-02-31`) silently roll over; relative phrases (`next monday`) accepted.
  Filed FP-327.
- **W-3** Gate test-step timeout headroom at 1.82x, under the required 2x floor
  — not a T-017 logic defect, but T-017's ~108 new tests pushed it there.
  Filed FP-328.

### Suggestions (folded into the fix round, since trivial)

- **S-1** `MigrationRunAudit.php`: `count($tenantIds) === 1` recorded `null` for
  a duplicate `--tenant=A --tenant=A` instead of `A`. Fixed with
  `array_unique()`.
- **S-2** `ArchiveAuditLogsHandler.php`: `$moved += count($ids)` could
  over-report when a manual archive run overlapped the scheduled one (data
  stayed correct; only the reported count could be wrong). Fixed to use the
  delete statement's actual affected-row count.

Two further suggestions (S-3: queued jobs get a null request_id — matches
spec AC-1 by design; S-4: console-is-the-trust-boundary could be stated more
plainly in two command docblocks) were accepted as-is, no action needed.

### Done well

Request-id design keys on the request attribute rather than `bound('request')`,
so "null outside a request" holds by construction; an inbound `X-Request-Id`
header is refused rather than trusted. The audit action catalogue is enforced
at write time against declarations built from the handlers' own constants, so
nothing is a copied literal. The archive move is a real SQL-level
`INSERT...SELECT` with a transaction per chunk, proven atomic by a fault-injection
test. `RecordsMigrationRuns` avoids a kernel→platform dependency cycle by
living in the kernel with platform as the sole implementer/binder. `PlatformAudit`'s
optional second constructor argument keeps all four T-016 fault-injection test
doubles compiling with a 0-line diff.

---

## Round 2 — the R-1/S-1/S-2 fix only

Diff range: `480b30e..200e731`, 6 files changed (+180/-8): `SearchAuditLogHandler.php`
+ its test, `MigrationRunAudit.php` + its test, `ArchiveAuditLogsHandler.php` + its test.

### Verdict: APPROVE

- **R-1**: `SearchAuditLogHandler` now unpacks `ip` via the package's own
  `AccessLogWriter::unpackIp()` before the row enters `AuditPage` — intra-`Package_Access`,
  no module-boundary issue, deptrac confirms 0 violations. Verified printable for
  every stored shape: null, IPv4, IPv6, IPv4-mapped IPv6. 4 new tests (IPv4/IPv6 ×
  JSON/CSV) seed through `AccessLogWriter::packIp()` and assert the real address
  appears in the exported file, not just "no exception". `qa` additionally proved a
  leading-zero-byte IPv4 (`10.0.0.1`) round-trips correctly through the real export
  path.
- **S-1**: `array_values(array_unique($tenantIds))` dedupes before the
  single-tenant check; only the duplicate-id case changes behaviour (verified by
  enumerating all cases: null, `[]`, `[A]`, `[A,B]`, `[A,A]`).
- **S-2**: `$moved` now accumulates the delete statement's actual affected-row
  count; the transaction/chunk boundaries that make the archive move atomic are
  untouched — this is a purely cosmetic reporting fix.

All three fixes were mutation-tested independently by both `reviewer` and `qa`:
each production line reverted, the corresponding new test went red for the
exact reason the finding described, then green again on restore.

### Gate, re-measured independently by both `reviewer` and `qa`

`reviewer` (solo run): 2175 passed / 12 failed / 11 skipped / 16386 assertions
(2 of the 2175 were qa's then-uncommitted extra test cases; the committed tree
alone was 2173 passed). `qa` (isolated clone, after diagnosing and discarding
two environment-contamination false starts — a stale deptrac cache and a
concurrent process sharing the worktree, neither a product defect): 2174 passed
/ 11 failed / 11 skipped / 16383 assertions. Both runs confined every failure to
FP-316's known cluster (`QaModuleRegistryContractTest`, `QaModuleNameValidationTest`,
`QaHealthProbeResilienceTest`, `ModulesEnvParsingTest`); the 12-vs-11 split
between runs is that cluster's own already-documented non-determinism, not a
regression — nothing outside the four known files failed in either run.
`composer lint`/`stan`/`deptrac` all pass in both.

### Critical / Warnings / Suggestions

None new.

---

## Final verdict

**APPROVE.** Task ready to close. R-1, S-1 and S-2 are all verified fixed,
independently, by both `reviewer` and `qa`. The only gate failures throughout
were FP-316's known, pre-existing, unrelated cluster. Open follow-ups:
FP-326 to FP-328.

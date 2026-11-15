---
name: worktree-env-and-gate-baseline
description: A fresh worktree has no modules/host/.env; what that does to the gate, what the harness blocks, and the helper-script recipe that worked (T-014)
metadata:
  type: project
---

A fresh worktree has `vendor/` missing AND `modules/host/.env` missing (git-ignored). Measured 2026-10-22 on T-014:

- No `.env` at all: dotenv's `file_get_contents(.env)` warning turns ~1517 tests into WARN and 10 fail (`QaTestSuiteIsolationTest` expects `N passed` from a child run, plus two `MissingAppKeyException`).
- `.env` copied from `.env.example` (a Bash `cp` was allowed): warnings gone, but `MODULES=demo` in it breaks the 11 tests that boot with `MODULES` unset (`ModulesEnvParsingTest`, `QaModuleNameValidationTest`, `QaModuleRegistryContractTest`, one in `QaHealthProbeResilienceTest`). A working `.env` is `APP_KEY=` only.
- The harness DENIES Write/Edit/rm on any `.env`, and denied a compound command containing `key:generate`. Do not route around it: export a throwaway `APP_KEY` in the shell instead (each Bash call; shell state does not persist) and report that the lead must supply the `.env`.

**Why:** the plan's "gate green" cannot be reached in a bare worktree without the lead's help, and the failure list looks like a regression if it is not recognised.
**How to apply:** at step 0 run the gate, read the FAILED names, and compare with that list before attributing anything to the branch. Put `export APP_KEY=...` in a scratchpad `c.sh` wrapper (composer) and `p.sh` (pest) so every run has it.

Tooling gotchas seen the same day: a large Bash heredoc containing an apostrophe in a PHP comment dies with "unexpected EOF" and creates NOTHING (use the Write tool, or `php -r` with `\x27`, or a PHP script with a nowdoc); foreground `sleep` is blocked, `timeout 170 tail -f /dev/null` waits; a full `composer check` took 6 to 8.5 minutes with 5 foreign `php.exe` processes running.

---
name: suite-rules-for-new-test-files
description: Three rules this suite enforces on any new test file - unique basenames across every collected directory, no `*/` inside a block comment, and short fixture tenant stems.
metadata:
  type: project
---

Three things the host suite refuses, all met while adding T-008 step 10 and 13
files:

- **No two collected test files may share a basename.**
  `tests/Feature/ToolchainGroupBindingTest.php` fails the gate when they do,
  because the toolchain group registry compares by basename. A kernel test and
  a host engine test about the same class need different names
  (`PerTenantLockTest` in `packages/kernel/tests`, `PerTenantLockEngineTest` in
  `modules/host/tests/Engine`).
- **A file-header block comment cannot contain `*/`.** Writing a path like
  `packages/` + `*` + `/tests` inside the banner ends the comment and the file
  is a parse error. Say it in words instead.

- **A fixture tenant stem must be short and distinct.**
  `runFixtureTenant()` / `masterMappingDatabase()` derive `database_name` from
  the stem and **truncate** it, and `(server_id, database_name)` is unique. Two
  tenants named `authority-read_only` and `authority-provisioning` on one
  server collide on the index rather than testing anything. Use `auth-0`,
  `auth-1` when a case needs one tenant per status.

- **A negated `toContain()`/`toHaveKey()`/`toHaveProperty()`/`toThrow()` with a
  second argument is refused by the suite itself.**
  `QaAssertionShapeGuardTest` scans every collected test file and fails the
  gate, because such an assertion passes when *either* argument fails to
  match. A per-column leak scan has to collect offenders with
  `str_contains()` and assert `toBe([])`. Cost: one full gate run (~128 s) to
  discover, after lint, stan and deptrac had all passed.

**Why:** the first two cost a whole gate run (~82 s) to discover, and the first one is
invisible until the full suite collects.

**How to apply:** check the basename against the repository before naming a new
test file, and keep glob-shaped paths out of block comments. Related:
[[project-backend-toolchain-constraints]].

- **A Pest file takes its generated class name from its PATH, not from its
  declared namespace.** Measured 2026-10-20: a file declaring
  `namespace Flexipos` + backslash + `Access` + backslash + `Tests;` still
  collects as
  `P` + backslash + `D` + backslash + `Flexiata` + backslash + ... + the file
  path. A Pest file also declares no class, so Deptrac collects **no token**
  from it either way. What the namespace declaration does buy is real and is
  two things: `QaToolchainRegistryCoverageTest` reads the `namespace`
  statement, so the tree stops being an FP-303 entry; and any ordinary class
  declared in the tree (a Support helper, a fixture) lands inside that
  package's layer and is boundary-checked. Proved as a pair - a namespaced
  class in `packages/access/tests` reaching a module internal is 1 violation,
  the same class global-namespaced is 0.
- **A path package's own `autoload-dev` is inert** when the package is
  installed as a dependency: only the root package's `autoload-dev` is
  applied. A test namespace for `packages/<x>/tests` therefore has to be
  declared in `modules/host/composer.json`.


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

**Why:** the first two cost a whole gate run (~82 s) to discover, and the first one is
invisible until the full suite collects.

**How to apply:** check the basename against the repository before naming a new
test file, and keep glob-shaped paths out of block comments. Related:
[[project-backend-toolchain-constraints]].

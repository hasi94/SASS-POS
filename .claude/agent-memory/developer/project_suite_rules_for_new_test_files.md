---
name: suite-rules-for-new-test-files
description: Two rules this suite enforces on any new test file - unique basenames across every collected directory, and no `*/` inside a block comment.
metadata:
  type: project
---

Two things the host suite refuses, both met while adding T-008 step 10 and 13
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

**Why:** both cost a whole gate run (~82 s) to discover, and the first one is
invisible until the full suite collects.

**How to apply:** check the basename against the repository before naming a new
test file, and keep glob-shaped paths out of block comments. Related:
[[project-backend-toolchain-constraints]].

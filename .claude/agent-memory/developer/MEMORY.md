# Developer memory index

- [Backend toolchain constraints](project_backend_toolchain_constraints.md) — Deptrac/Pint/Pest/Composer path-repo gotchas that already changed decisions once.
- [Module host boot contract](project_modulehost_boot_contract.md) — why modules boot via runtime PSR-4, not `composer require`; what a module must declare.
- [Tenancy connection invariants](project_tenancy_connection_invariants.md) — what leaks across a tenant switch, which guard catches it, and which DB APIs are forbidden.
- [Line endings in scripted edits](feedback_windows_line_endings_in_scripts.md) — text-mode Python writes rewrite whole files as CRLF here; use binary mode and verify.
- [Enumerate leak surfaces by running them](feedback_enumerate_leak_surfaces_by_running_them.md) — reasoning about which magic method each dumper honours produced a confident, wrong list.
- [Clean up probe files immediately](feedback_clean_up_probe_files_immediately.md) — two leftover scratch scripts made the gate report 46 unrelated failures.
- [A guard must not disturb what it measures](feedback_guard_must_not_disturb_what_it_measures.md) — four shapes of assertion that read as safeguards and assert nothing.
- [Never edit files during a test run](feedback_never_edit_files_during_a_test_run.md) — and why a tight retry loop cannot fix a Windows rmdir flake, however many runs come back green.
- [Cleanup must survive a killed process](feedback_cleanup_must_survive_a_killed_process.md) — a planted probe needs deleting in `beforeEach` too; a 300s timeout kill runs no teardown.
- [Repo-wide rename recipe](project_repo_wide_rename_recipe.md) — two commits, enumerate-then-classify tokens, the protect list, and the Composer path-package traps.

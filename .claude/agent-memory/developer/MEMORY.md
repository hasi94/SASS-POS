# Developer memory index

- [Backend toolchain constraints](project_backend_toolchain_constraints.md) — Deptrac/Pint/Pest/Composer path-repo gotchas that already changed decisions once.
- [Module host boot contract](project_modulehost_boot_contract.md) — why modules boot via runtime PSR-4, not `composer require`; what a module must declare.
- [Tenancy connection invariants](project_tenancy_connection_invariants.md) — the `db.factory` choke point, what leaks across a switch, and which DB APIs are forbidden.
- [Line endings in scripted edits](feedback_windows_line_endings_in_scripts.md) — text-mode Python writes rewrite whole files as CRLF here; use binary mode and verify.
- [Enumerate leak surfaces by running them](feedback_enumerate_leak_surfaces_by_running_them.md) — reasoning about which magic method each dumper honours produced a confident, wrong list.
- [Clean up probe files immediately](feedback_clean_up_probe_files_immediately.md) — two leftover scratch scripts made the gate report 46 unrelated failures.
- [A guard must not disturb what it measures](feedback_guard_must_not_disturb_what_it_measures.md) — nine shapes of assertion that read as safeguards and assert nothing.
- [Never edit files during a test run](feedback_never_edit_files_during_a_test_run.md) — and why a tight retry loop cannot fix a Windows rmdir flake, however many runs come back green.
- [Cleanup must survive a killed process](feedback_cleanup_must_survive_a_killed_process.md) — probe cleanup belongs first in `composer check`, not in a test hook: stan and deptrac run before any hook.
- [Repo-wide rename recipe](project_repo_wide_rename_recipe.md) — two commits, enumerate-then-classify tokens, the protect list, and the Composer path-package traps.
- [Defer in a comment, or file a row](feedback_defer_in_a_comment_or_file_a_row.md) — which residuals earn a row, declared-beats-silent deviation, and when "gate: green" hits another role's files.
- [Gate timing spread](project_gate_timing_spread.md) — range 34.51 s run-to-run and 2.8x session-cold, so no gap under ~35 s is evidence; cache-cold vs machine-cold.

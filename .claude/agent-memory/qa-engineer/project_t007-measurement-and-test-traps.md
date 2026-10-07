---
name: t007-measurement-and-test-traps
description: T-007 lessons - how to mutate production code without touching the tree, how to run an old tip against a new test, per-file fixture cost, Pest toBe message trap, PHPStan covering tests, and the build() wedge
metadata:
  type: project
---

- **Mutation without editing production.** Copy the one file to the scratchpad, edit the copy, and run
  `pest --bootstrap=<file>` where the bootstrap requires `vendor/autoload.php` then
  `spl_autoload_register($fn, true, true)` serving the copy. Always run a byte-identical control through the
  same bootstrap first and print a "MUTANT LOADED" line. Same trick with a prefix map runs a new test file
  against an old tip's `src/` from a scratch `git worktree` (junction `vendor`, and remove the junction with
  `rmdir` BEFORE `worktree remove`, or the delete follows it).
- **A table row only goes red under mutation if something in it depends on the thing mutated.** The AC-23
  table passed with the per-query guard commented out until a "held past scope" row was added: construction
  checks decide every other state.
- **A focused Pest run is not the gate.** `composer stan` analyses the tests directory, so PHPStan errors in a
  new test file surface as reds in two unrelated host tests. Run `pint --test` and `phpstan analyse` on new tests.
- **Pest `toBe($expected, $message)` drops the message** (userland extra args are ignored). Compare a string that
  contains the context instead.
- **Per-file fixtures.** 149 cases rebuilding two SQLite files each ran 143 s here; building once per file and
  folding dataset rows into one case per route took the file to about 4 s. Count what a new test adds to the gate.
- **`DB::build()` on the tenant name leaves a process-wide stanza** (`dynamicConnectionConfigurations`), which
  makes the next `enter()` refuse; rows of one table need a reflection reset between them.
- **Measurement.** `bc` is absent in Git Bash, use awk; heredocs mangle backslashes in PHP bootstraps, use Write.

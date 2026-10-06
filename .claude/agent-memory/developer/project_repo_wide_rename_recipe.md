---
name: project-repo-wide-rename-recipe
description: How the services-to-modules rename was done safely - two commits, an enumerate-then-classify token pass, the protect list, and the Composer path-package traps
metadata:
  type: project
---

The mechanics that made T-005's 80-file rename verifiable. Reusable for FP-203+
renames, and several points are not obvious.

**Split the move from the edits, in that order.** Commit 1 is `git mv` only, zero
bytes changed, so rename detection is trivially 100 % and `git log --follow`
survives. Commit 2 is every identifier edit. Mixing them pushes small
heavily-edited files (stubs, config) below git's 50 % similarity default and they
record as delete+add. Verify rather than assume:
`git diff --cached --stat -M --find-renames=100%` must say
`N files changed, 0 insertions(+), 0 deletions(-)`, and
`--diff-filter=A` / `--diff-filter=D` must both be empty. **Always run
`--follow` on a file created *after* the move as a positive control** — a
`--follow` that printed history for everything would otherwise look like success.
The gate is legitimately RED between those two commits; say so in the commit
message.

**Enumerate tokens, then classify; never substitute from a guessed list.** Run
`grep -oh -E '[A-Za-z_]*[Ss][Ee][Rr][Vv][Ii][Cc][Ee][A-Za-z_0-9]*' <files> | sort |
uniq -c` first. That inventory is what caught five test class names whose
`Service` is followed by a word character (`QaGeneratedServiceTestDiscoveryTest`,
`UnlistedServiceReturns404Test`, `ServicesEnvParsingTest`,
`QaServiceNameValidationTest`, `…GeneratedServiceReachableTest`) — a `\bService\b`
word-boundary rule cannot reach any of them, and a green suite would not have
told me. Re-run the inventory after the pass; it should contain only survivors.

**`-i` on a case-sensitive token destroys the measurement.** A case-insensitive
grep for the old all-caps environment-variable name also matches the ordinary
English word in lower case, which inflated my first surface count from 78 files to
far more and swept in `config/app.php`, `docs/database.md` (`service_charge`,
`service_clients`) and the stock third-party-credentials config — none of which
had anything to do with the rename. Case-sensitive for the identifiers,
case-insensitive only for the few tokens that genuinely vary in case (the
three-letter abbreviation the old generator command used).

**Protect by sentinel, not by ordering luck.** Replace each survivor with a
`\x00Pn\x00` placeholder before any rule runs, restore after, and assert no
sentinel survives. The survivors here: `src/Services` (a module's *service
classes*, nothing to do with modules — give it a comment or the next reader
"finishes the job"), the dead per-module connection and schema replacement keys
that are out of scope, and immutable strings that are facts about history — the
`ai/T-00n-…` branch names and the `docs/qa/T-00n-…` record filenames, two of which
test docblocks link to.
`\bService\b` already leaves `*ServiceProvider` alone, because there is no word
boundary before `Provider`.

**Composer path packages, renaming one.**
- `composer update --lock` **fails**: it re-resolves from the lock, which still
  pins the old package name. The one that works is
  `composer update flexipos/<new-name> --no-install` — expect exactly
  `1 install, 0 updates, 1 removal`.
- Prove no dependency bump rode along: `git diff -U0 composer.lock | grep '"version"'`
  must be **empty**. Only the renamed block's own `dist.reference` may move.
- **`composer install` leaves the old `vendor/<vendor>/<old-name>` behind as a
  dangling junction** pointing at the vanished directory. `ls` still shows it while
  `test -e` says it is absent. Delete it, or the "old name absent from the
  installed tree" check is a lie. Then
  grepping `vendor/composer/autoload_psr4.php` for the **old** PSR-4 namespace root
  must return 0 — that prefix still resolving through a moved relative path is
  exactly how a missed rename stays green.

**Prove counts per test class, not by total.** `pest --list-tests-xml` before and
after, grouped by `<testClass name>` with the basename compared under a rename
map. 304 -> 307 as a total cannot distinguish "+3 added" from "2 dropped, 5
added"; the per-class table showed 0 removed, 0 pre-existing class changed, 2
classes added with 1+2 cases. Related:
[[feedback-guard-must-not-disturb-what-it-measures]],
[[project-backend-toolchain-constraints]].

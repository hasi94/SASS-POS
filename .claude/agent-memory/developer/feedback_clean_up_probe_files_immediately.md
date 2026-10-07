---
name: feedback-clean-up-probe-files-immediately
description: Delete throwaway probe/scratch files inside the repo the moment a measurement is done - leaving them made the gate report 46 unrelated failures
metadata:
  type: feedback
---

When measuring something with a throwaway PHP script, **write it to the session scratchpad, or
delete it in the same command that reads its output**. Never leave it inside the repository,
even under a git-ignored path.

**Why:** on T-003 I left two probe scripts under `modules/host/storage/app/dumpprobe/`. The
next `composer check` reported **46 failures** across `PintLintFixRoundTripTest`,
`QaManifestTrustAttackTest`, `ModuleMakeDemoRegenerationTest` and most of the tenancy suite —
`FileNotFoundException`, `PDOException`, `UnexpectedValueException`. It read exactly like a
catastrophic regression in the change I had just made. The actual cause was that the probe
files were deliberately unformatted, a test runs Pint over a storage path, and that one failure
cascaded. Deleting the two files took the run from 46 failures to 1. Diagnosing it cost more
time than the fix it was supporting.

**A second cause with the same shape, in team mode: the gate reads the working tree, not the
commit.** The vocabulary sweep's corpus is "tracked plus untracked-and-not-ignored", so another
agent's *uncommitted* edit to `docs/backlog.md` failed `composer check` in the middle of my own
task, in a file I was forbidden to touch. Before fixing anything a gate names, compare the two:
`git show HEAD:<file> | grep <token>` against `grep <token> <file>`. If the token is only in the
working tree and the file is not in my diff, it is someone else's edit - report it, do not fix it.

**A third cause, and the one that catches a measurement script rather than a probe: redirecting
a log into the repository.** On T-007 I timed the gate with a shell script that did
`cd "$HOST"` and then wrote `>"$(dirname "$0")/step0-$label.log"`. `dirname "$0"` was `.`, so
seven log files landed beside `modules/host/composer.json` — git-ignored, invisible to
`git status`, and fatal anyway: `QaAtomicGenerationProofTest` fingerprints `modules/host` by
**content hash**, so a log still being appended to during the run fails it even though the file
list never changed, and `QaDocumentationAccuracyTest` swept the captured gate output and found
the word MIT in it. Two failures, both mine, neither related to the change. Always give a
measurement script an **absolute** scratchpad path for its logs; never a relative one, and never
a path derived from `$0` in a script that `cd`s.

**How to apply:** a probe is finished when its output has been read. Delete it then, in the same
Bash call. Before trusting any suite result that looks catastrophic and unrelated to the change,
run `git status --porcelain` and `ls` the storage/fixture directories first — leftover state
from a crashed or interrupted run is a far more common explanation than a broad regression. Two
other failures in that same run (a manifest-trust case, a fixture package) were also stale state
and vanished on a clean re-run.

Related: [[project-backend-toolchain-constraints]] (Pint excludes `storage/` only when given a
directory, which is why storage is otherwise a safe place for lint fixtures).

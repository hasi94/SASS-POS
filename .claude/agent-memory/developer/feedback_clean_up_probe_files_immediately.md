---
name: feedback-clean-up-probe-files-immediately
description: Delete throwaway probe/scratch files inside the repo the moment a measurement is done - leaving them made the gate report 46 unrelated failures
metadata:
  type: feedback
---

When measuring something with a throwaway PHP script, **write it to the session scratchpad, or
delete it in the same command that reads its output**. Never leave it inside the repository,
even under a git-ignored path.

**Why:** on T-003 I left two probe scripts under `services/host/storage/app/dumpprobe/`. The
next `composer check` reported **46 failures** across `PintLintFixRoundTripTest`,
`QaManifestTrustAttackTest`, `SvcMakeDemoRegenerationTest` and most of the tenancy suite —
`FileNotFoundException`, `PDOException`, `UnexpectedValueException`. It read exactly like a
catastrophic regression in the change I had just made. The actual cause was that the probe
files were deliberately unformatted, a test runs Pint over a storage path, and that one failure
cascaded. Deleting the two files took the run from 46 failures to 1. Diagnosing it cost more
time than the fix it was supporting.

**How to apply:** a probe is finished when its output has been read. Delete it then, in the same
Bash call. Before trusting any suite result that looks catastrophic and unrelated to the change,
run `git status --porcelain` and `ls` the storage/fixture directories first — leftover state
from a crashed or interrupted run is a far more common explanation than a broad regression. Two
other failures in that same run (a manifest-trust case, a fixture package) were also stale state
and vanished on a clean re-run.

Related: [[project-backend-toolchain-constraints]] (Pint excludes `storage/` only when given a
directory, which is why storage is otherwise a safe place for lint fixtures).

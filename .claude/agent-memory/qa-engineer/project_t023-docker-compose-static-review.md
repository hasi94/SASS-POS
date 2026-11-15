---
name: t023-docker-compose-static-review
description: T-023 (Docker dev environment) close-out — a real defect found by reading parsed compose JSON under ENGINE_HOLD, with no container started; how to review infra-as-code tasks when running the thing is forbidden.
metadata:
  type: project
---

T-023 (FP-217) authored `docker-compose.yml`, `infra/docker/app/Dockerfile`,
`modules/host/.env.docker.example` and `docs/docker-dev-environment.md` under a
standing `ENGINE_HOLD` (no `docker compose up`, no container, no engine
connection — see [[project-module-host-fragile-areas]] for the hold's origin).
The one permitted Docker-CLI check, `docker compose config` (daemon not
required — confirmed again empirically: `docker ps`/`docker info` error with
the daemon stopped, `docker compose config --quiet`/`--services`/`--format
json` all still exit 0), is also the only lens available for finding real
defects — reading the Dockerfile/compose/env prose is not enough.

**BUG-1 (major): a healthcheck password with a corrupting space, found only by
reading the parsed JSON.** `services.mysql.healthcheck.test` was written as
`["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-p flexipos-root-dev-only"]`.
`HEALTHCHECK CMD` (array/exec form) execs directly with no shell, so the fifth
array element is ONE argv token already containing a space. `mysqladmin`'s
option parser treats everything after `-p` in that single token as the inline
password, so the actual value sent is `" flexipos-root-dev-only"` (leading
space) — wrong, so the healthcheck can never authenticate, `mysql` is never
`healthy`, and `app`'s `depends_on: condition: service_healthy` blocks forever.
The correct form has no space (`-pflexipos-root-dev-only`, attached) or uses
`--password=...`. This is invisible from reading `docker-compose.yml`'s YAML
prose alone in a quick skim — the array already *looks* like five separate
flags/values at a glance — it only becomes obvious once you look at the raw
array elements as strings (or the `--format json` output) and notice the fifth
element is a single string containing an embedded space, not two array
elements.

**How to apply:** on any infra-as-code task gated by ENGINE_HOLD, do not trust
a visual skim of compose YAML for anything that mixes a short CLI flag with a
value inside one list item (`-p`, `-u`, any `--flag=value` vs `--flag value`
distinction). Pull `docker compose config --format json` and inspect the exact
array elements as strings; count characters, don't assume a space is a
separator in exec-form arrays — it never is. This generalizes past mysqladmin:
any `HEALTHCHECK`/`command`/`entrypoint` array with a credential or flag that
conventionally uses `-xvalue` (no space) is worth this same character-level
read.

This defect did not fail any AC in the spec (AC-19 explicitly defers the
runtime proof until G-1) and the structural checks (AC-1 to AC-17) all still
passed — it is the kind of bug that only shows up once someone actually runs
the thing, which is exactly why a static reviewer has to go looking for it
instead of waiting for a gate to turn red.

**Round 2 (fix verification, commit `6775ec2`):** dev switched the token to
`--password=flexipos-root-dev-only` — one argv token, no embedded space,
password matches `MYSQL_ROOT_PASSWORD` set elsewhere in the same file.
Re-extracted the array from a fresh `docker compose config --format json`
independently (small Node snippet on stdin) rather than trusting dev's
report; also checked `git diff 9a6fc78..6775ec2 --stat` to confirm the fix
touched only that one line in `docker-compose.yml`, nothing else drive-by
changed. Full `composer check` reran at the exact same 2750/12/23 split as
round 1 (same pre-existing `QaModuleRegistryContractTest` cluster) — expected,
since the fix touches zero PHP files. Verdict flipped FAIL -> PASS.

**Side note on this environment's background-task waiting:** standalone
`sleep N` and `sleep N; <cmd>` foreground calls are blocked outright (no
Monitor tool was available to me). The only way to let real wall-clock time
pass for a long `composer check` run in the background was a foreground
`until [ -s "$file" ]; do sleep 5; done` loop — it auto-backgrounds itself
after ~180s if the file is still empty, and composer's full-suite output is
heavily buffered (stays at 0 bytes for the entire ~840s run, then flushes
all at once at process exit), so polling file size rather than content
growth is the only signal; async task-notifications arrive attached to the
next tool result only after some real time has elapsed inside a blocking
call, not after instant no-op calls.

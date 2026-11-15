# Review: T-023 — Docker development environment

## Verdict: APPROVE

No Criticals. The compose file, Dockerfile and env profile are correct for what this task proves without starting a database — static validation only. `ENGINE_HOLD` is respected throughout, and the files this task claimed not to touch (`CLAUDE.md`, `modules/host/.env.example`, `modules/host/composer.json`, `composer.lock`) are confirmed untouched by `git diff 98e716c...HEAD --name-only`.

## Correction to the record (W-2)

BUG-1's round-1 description overstated the defect. `mysqladmin ping` returns exit 0 whenever the server is reachable, "even in case of an error such as Access denied" per MySQL's own documentation — so the old `-p flexipos-root-dev-only` token (embedded space, parsed as a password with a leading space) would have produced an access-denied warning but **still reported the healthcheck as passing**. The real defect was a misleading command that didn't check what it looked like it checked, not a stack that blocks forever. The fix (`--password=flexipos-root-dev-only`, commit `6775ec2`) is still correct and still the right thing to ship — a healthcheck should name the real password even if the check's current form doesn't verify it — but the backlog/QA record's "would block forever" framing is corrected here to "would have reported healthy with the wrong password actually passed, undermining the credential's own documentation-as-code value."

## Warnings

- **W-1** `docker-compose.yml` healthcheck has no `start_period`; 10 retries at 5s (~50s) may be shorter than first-init MySQL takes on Docker Desktop/Windows, which could fail `app`'s `depends_on: service_healthy` on the very first `up`. Not blocking — nobody can run `up` until G-1. Filed as a follow-up for FP-218 to fold in.
- **W-2** — see correction above, applied to this review and the backlog row.
- **W-3** `docs/backlog.md`'s FP-217 row said `SPEC (T-023)` while the T-023 task row had already moved past that — corrected directly as part of closing this task.

## Suggestions (filed as follow-ups, non-blocking)

- **S-1** (FP-395) `php:8.2-cli-bookworm`/`composer:2` base images aren't patch-pinned; fine for dev, but FP-218 should pin an exact patch tag or digest if it reuses this image for CI reproducibility.
- **S-2** (FP-396) The app container runs as root over a bind-mounted repo; on Linux hosts, git's "dubious ownership" check will make Composer's git-based version detection noisy. Cheap fix: `RUN git config --system --add safe.directory '*'` in the Dockerfile.
- **S-3** (FP-397) The `flexipos` MySQL user only has grants on `flexipos_master`; tenant provisioning (creating a database per shop) will fail against this stack once anyone exercises it post-hold. Needs an init script granting `CREATE` on `flexipos_%` or a separate provisioning credential — explicitly out of this task's scope.
- **S-4** (FP-398) `.env.docker.example`'s `APP_URL=http://localhost:8000` doesn't follow `FLEXIPOS_APP_PORT` automatically; add a comment noting both must be changed together.
- **S-5** (FP-399) `docs/docker-dev-environment.md` names the override variables but not where Compose actually reads them from (shell env or a root `.env`, which is already gitignored) — should say so explicitly so nobody mistakenly puts them in `modules/host/.env.docker`.
- **S-6** (FP-400) The doc's restated Docker Desktop version row drops `CLAUDE.md`'s "(currently stopped; start it before compose work)" note; either copy it verbatim or stop calling the two tables "identical" in QA's AC-13 evidence.

S-7 (QA agent-memory files riding along in the diff) is noted but not filed — harmless, no behavioral effect, and cleaning stray agent-memory commits out of a PR's file list is a packaging nicety for whoever eventually merges, not a backlog item.

## The five specific checks reviewer ran

1. **Argument-tokenization hazards** — only the one BUG-1 site existed; no second hazard found across compose/Dockerfile.
2. **Credentials** — every credential-looking value carries a `-dev-only` suffix; `.gitignore` confirmed to ignore `modules/host/.env.docker` while keeping `.env.docker.example` tracked; no root `.env` exists; no gap found.
3. **Ports/volumes** — MySQL confirmed on `127.0.0.1:13306`, never `3306`; the app reaches MySQL only via the compose network (`mysql:3306`), never through a host port; no path to the XAMPP-protected engine exists even if the override were misconfigured.
4. **Dockerfile** — copies no source (only the Composer binary via a `COPY --from`); build context contains only the Dockerfile.
5. **Docs vs. files** — spot-checked ports/volumes/commands against the actual compose file; only the S-6 nuance found.

## Done well

- The healthcheck pings over TCP during a window when MySQL's temporary init server has networking switched off, so it structurally can't report healthy too early.
- Container-only `vendor`/`bootstrap/cache` volumes, with the Windows-junction reasoning documented inline — a subtle problem caught at design time.
- Every published port is loopback-only, with an inline comment explaining why the app still listens on `0.0.0.0` inside the container.
- `docs/docker-dev-environment.md` is honest about what's NOT verified (§9) and documents the root-user cost rather than hiding it.

## QA

- Round 1: FAIL, 1 major defect (BUG-1).
- Round 2: PASS, 0 defects, independently re-verified the fix via diffstat and a fresh `docker compose config` parse.

## Final verdict

**APPROVE.** T-023 is DONE.

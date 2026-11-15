# T-023 decisions — Docker development environment

Decisions made autonomously by `ba` while writing `docs/specs/T-023-docker-dev-environment.md`,
per the TEAM PROTOCOL (no blocking questions to the human).

D-1 | Which mail catcher? | Mailpit. | The repo already carries the convention via
`modules/host/vendor/laravel/sail/stubs/mailpit.stub`, and Mailpit is the current
Laravel-ecosystem default (MailHog's actively-maintained successor). Introducing a
third tool nobody has referenced anywhere would be the less conventional choice.
Reversible: yes (swap one compose service).

D-2 | Does this task change `CLAUDE.md`'s own "Local environment" table or
"Commands" section? | No, not directly. The spec requires documentation of the
Docker path to exist, but any edit to `CLAUDE.md` itself is written up as a proposed
addition and held for the human's sign-off, matching the T-005/T-006/T-007 precedent
(`docs/decisions/T-005-rename-modules.md` and the backlog rows for T-005/T-006/T-007,
all "not yet merged: CLAUDE.md still awaits the human"). | `CLAUDE.md` edits in this
project have an established escalation pattern already; this task does not need to
invent a new one or quietly normalize editing it. Reversible: yes (the proposal can
be withdrawn or amended before the human acts on it).

D-3 | Where does the Docker-path documentation live? | A new `docs/docker-dev-environment.md`,
not an addition squeezed into `docs/deployment-vps.md` (which is explicitly the VPS/
production topology, a different audience and a different set of constraints — see
its rules 1-7, all VPS-specific) and not squeezed into `CLAUDE.md` directly (D-2). |
Keeps the production runbook's scope clean and gives the Docker local-dev path a
document whose audience (a developer choosing a setup) matches its content, same
granularity as how `deployment-vps.md` itself is a sibling of `architecture.md`
rather than a section inside it. Reversible: yes.

D-4 | Does this task wire the compose files into `composer check` or any CI gate? |
No. Structural validation (compose file parses, declares the right services) is a
manual/CLI check this task's AC-17 asks `dev` to perform and record, not a new Pest
test added to the suite. | FP-218 (CI pipeline) is the next backlog row, named by
FP-217 as a dependent, and its whole job is to exercise these files in CI. Adding a
gate test here would duplicate FP-218's reason to exist and risks a half-built CI
hook with no pipeline around it yet. Reversible: yes (FP-218 can still add one).

D-5 | Separate `.env.docker.example` file, or additions inside the existing
`.env.example`? | A separate `.env.docker.example` (exact filename left to
`architect`/`dev`, who own the technical shape) rather than interleaving
Docker-service-hostname values into the single existing `.env.example`. | The
existing file's values (`DB_CONNECTION=sqlite`, `127.0.0.1` hosts) are the XAMPP/
local defaults developers already rely on; interleaving a second profile into one
file risks someone copying the wrong half. A separate example file is the
conventional pattern for a second deployment profile (mirrors how
`docs/deployment-vps.md` already describes its own `.env.vps.example` as a distinct
file from the dev default). Reversible: yes.

D-6 | Does the compose MySQL version (8.4) get reconciled with XAMPP's MariaDB
10.4.32? | No, this is explicitly out of scope — already a deliberate, ratified
project decision (`docs/backlog.md`'s "Owner decisions, 2026-10-08" row: "Docker and
MySQL 8.4 parity are out of scope for T-008," and `docs/architecture.md`'s stated
stack "MySQL 8.4 in Docker and CI, MariaDB 10.4 in XAMPP mode"). | Re-litigating a
decision the owner already made in a prior task would be inventing a business
question that was already answered. Reversible: n/a (not reopened).

D-7 | Does bringing up the Docker stack's MySQL/Redis containers count as "connecting
to a real engine" for `ENGINE_HOLD` purposes, even in dev's own local container
(not the shared 43-database MySQL instance the hold is actually about)? | Treated as
yes, conservatively in scope of the hold, for this task. Writing and structurally
validating the compose file is fine; actually running `docker compose up` against
the MySQL service and connecting the application to it is not performed as part of
proving this task's ACs. | The backlog's own language for the hold ("nothing may
connect to a real MySQL/MariaDB engine until G-1") is not qualified by which engine
instance, and this task's lead explicitly called this out as the critical standing
constraint not to violate or route around. A fresh, empty Docker-local MySQL
container is almost certainly harmless, but "almost certainly harmless" is exactly
the kind of silent judgment call the hold exists to take out of individual agents'
hands. Reversible: yes — once G-1 lands, FP-218 or a follow-up can add the
end-to-end proof this task defers (AC-19).

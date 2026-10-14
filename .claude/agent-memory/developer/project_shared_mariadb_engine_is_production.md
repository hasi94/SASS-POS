---
name: project-shared-mariadb-engine-is-production
description: The XAMPP MariaDB on this machine holds other projects' unbacked-up live data; ENGINE_HOLD is on after 16 databases were destroyed on 2026-10-14.
metadata:
  type: project
---

The MariaDB 10.4.32 instance at `127.0.0.1:3306` (XAMPP, `E:\xampp\mysql`) is
**not a test server**. It held 56 schemas on 2026-10-14, most of them other
projects' — several look like live client systems — and **nothing on it is
backed up**. Another project (`scriber_testing`) was observed running
migrations against it while FlexiPOS tests ran, so it is in active use.

On 2026-10-14 a FlexiPOS test sweep destroyed sixteen of those databases
(`99mobility*`, `ability*`, `ai_vehicle_research`, `autofill`, `dayananda*`,
`def`, `delivery*`, `demo_pos`, `dulari_test`, `flexhms_glow`). See
[[red-first-stub-must-be-inert]] for the mechanism.

**Why:** the owner develops several products on one machine with one engine, so
"the local database" is someone's production data. The guard work in T-008
(`packages/testing/src/Engine/`, reserved prefix `flexipos_t8test_`) exists
entirely because of this.

**How to apply:**
- Treat any statement against that engine as a production change. Read-only
  queries (`information_schema`, `SHOW`) are fine; `CREATE`/`DROP DATABASE` goes
  through `Flexipos\Testing\Engine\EngineDatabase` and nothing else.
- `ENGINE_HOLD=1` in `modules/host/phpunit.xml` suspends every connection to it
  and is **on**; clearing it is the owner's decision, not an agent's. Verify the
  current value before assuming engine tests run.
- Before and after anything that writes there, record
  `SELECT schema_name FROM information_schema.schemata` and diff it. Never
  enumerate with SQL `LIKE`: `_` is a single-character wildcard, and
  `SHOW DATABASES LIKE 'flexipos_%'` returns the real `flexipos-master`.

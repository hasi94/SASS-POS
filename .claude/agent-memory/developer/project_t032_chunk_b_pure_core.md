---
name: project-t032-chunk-b-pure-core
description: T-032 chunk B (steps 4-7) facts not in the plan - masked regex mutation, extra helper classes, move() depth semantics, gate numbers
metadata:
  type: project
---

T-032 chunk B: 22 contract files, Input/Fields, AccountInput/AccountRules, FiscalCalendar/PeriodTransitions.

- **Input::code's pre-fold byte filter masks the regex anchor.** Swapping `\z` for `$` alone stays green; only filter plus anchor together turn the newline row red. Report it, do not claim M1 bit.
- Extra pure helpers the plan did not list: `Accounts\AccountState` (rules need path/depth/isSystem, absent from `AccountSnapshot`), `Periods\PeriodRef`, `Support\Fields`, `Exceptions\FinanceRefusal/FinanceRefused` (`because()` factory, full reason vocabulary declared up front).
- `AccountRules::move()` takes the deepest descendant's STORED (absolute) depth, not a height.
- A pure test file needs no `uses(TestCase::class)`; namespace `Flexipos\Finance\Tests\...` and unique basenames.
- Gate after chunk B: 13 failed (same 5 files), 38 skipped, 3719 passed, test step 1144 s. Another project's php.exe test run may be live on this machine.

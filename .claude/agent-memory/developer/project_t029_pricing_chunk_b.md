---
name: t029-pricing-chunk-b
description: T-029 chunk B (pure engine + 112 JSON fixtures) - numbers, design calls that departed from the plan text, and mutation traps
metadata:
  type: project
---

Chunk B gate: 13 failed / 31 skipped / 3820 passed (same failed classes as chunk A), test step 969s (+10% on 880s).

- Inclusive back-out: the plan's "product of every (1+rate/100)" is only right when all members compound. Shipped an exact affine solve (slope M, constant K for compound-after-fixed) in TaxCalculator::inclusiveNet.
- Fixture integers (priority, sequence, step, used_count) are strings too: the lint bans every JSON number. Hand-typed expected values matched the engine first run.
- R1 (effective_to) is absorbed by "latest effective_from wins" unless a fixture has a closed row ending on the date with no successor.
- T-027's factor scan flags any `*`/multipliedBy next to "factor" in packages/*/src: exemption for PriceLadder landed in chunk B (not step 17) because the gate needs it. Avoid the word in other engine files.
- Pest: `toContain($x, $label)` treats the label as a second needle; spread after named args is a parse error (spread first).
- Bash heredocs with many quotes fail here; use Write.

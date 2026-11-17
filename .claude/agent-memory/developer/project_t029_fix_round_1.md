---
name: t029-fix-round-1
description: T-029 review round 1 fix round (steps 21-33) - numbers, a foreign load process that slows the gate, and harness traps
metadata:
  type: project
---

Baseline 13/36/4037 (test step 1272s, contended by a foreign `bench_load.php` php process); final 13 failed (same names) / 41 skipped / 4109 passed, test step 1091s. Gate wall time ~1100-1285s, not the older 925s.

- A foreign `E:\xampp\php\php.exe bench_load.php N` process appeared mid-session (not in this tree); it inflates gate timing. List php processes and say so in the report.
- Python `subprocess` "bash" resolves to WSL; use `C:/Program Files/Git/usr/bin/bash.exe`. In Python heredocs use raw strings for PHP namespaces (`\U` in "Flexipos\Pricing" is a unicode-escape error).
- Pint fixes `\Fully\Qualified` names in test files (fully_qualified_strict_types): import them.
- PHPStan: an anonymous-class recorder returned as an interface needs `@return Iface&object{calls: list<...>}`.
- Lock-order scan: planted-file controls take a path; helpers prefixed `pricingLock`.
- Engine-gated E-1..E-5 live in modules/host/tests/Engine/PricingSchemaEngineTest.php and have never run.

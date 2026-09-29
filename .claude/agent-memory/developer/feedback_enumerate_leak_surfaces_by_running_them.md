---
name: feedback-enumerate-leak-surfaces-by-running-them
description: For any "this value must never be observable" requirement, enumerate the surfaces by executing each one - reasoning about which magic method each honours produces a confident, wrong list
metadata:
  type: feedback
---

When a requirement says a value must never appear in output, **build the list of surfaces by
running each one against a distinctive needle**, not by reasoning about which hook each surface
honours. Write the sweep as a loop over surfaces that collects the leaking ones and asserts the
collection is empty, so adding a surface later is one line.

**Why:** on T-003 I shipped two classes whose docblocks asserted `__debugInfo()` covered
"Symfony's VarDumper, which is what `dd()` and `dump()` use". Every clause was defensible and
the conclusion was wrong — the cloner *merges* `__debugInfo()` alongside the real properties.
The architect's own measurement table had the same gap, because it measured `var_dump()` and
`print_r()` and reasoned from there to the dumpers. QA found the credential printing in `dd()`
output, which is the single most likely surface to end up pasted into a ticket. The residual
list we did write down (`var_export()`, string interpolation) was accurate but incomplete, and
incompleteness was the whole defect.

**How to apply:** for credentials, tokens and PII, sweep at minimum: `var_dump()`, `print_r()`,
`var_export()`, `json_encode()`, `serialize()`, `dd()`/`dump()` via `VarCloner` + **both**
`CliDumper` and `HtmlDumper`, the real log formatters (`LineFormatter`, `JsonFormatter`, not
just the log *event*), both exception renderers, and any framework command that prints config
(`config:show`, `about`). Related: [[project-tenancy-connection-invariants]].

**Companion trap, same task:** Pest's `expect($x)->not->toContain($needle, $message)` takes
**variadic needles**, not a message — the negation passes as soon as *any* needle is absent, so
a "helpful" second argument turns the assertion permanently green. Use one needle per call, or
`str_contains()` plus `expect(...)->toBeFalse($message)` when you want a message.

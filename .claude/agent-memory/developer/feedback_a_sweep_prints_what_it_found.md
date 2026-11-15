---
name: a-sweep-prints-what-it-found
description: Twice in one step a sweep was green over the wrong domain, and both times the printed list - not the assertion - is what showed it.
metadata:
  type: feedback
---

**Make an enumerating instrument print what it found, then read the print before believing the green.** Twice in T-010 step 24 a sweep passed while measuring almost nothing, and the assertion could not have told me:

- The Ruling 7 scan looked for `FOREIGN KEY` in this task's test sources and matched **one file: itself**, via its own control string. The real assertions are `->toThrow(QueryException::class)` after a violating insert and contain none of the words. The printed line said "1 file asserting a foreign key" and named the scanner.
- The bump sweep ran all 23 slices and printed its "changes seen" list: `AssignRole`, `RemoveRoleAssignment` and `ChangeEmployeeStatus` were **absent**. The fixture used one company-wide key, so a role assigned at a branch confers nothing (D-8 (c)) and a status change had nothing to take away. The sweep would have stayed green if any of the three stopped bumping.

**Why:** a sweep's assertion is over the set it enumerated. Both the assertion and the enumeration can be perfect and the *set* still be the wrong one - shape 7 - and the only artefact that distinguishes "found nothing wrong" from "found nothing" is the list.

**How to apply:**
- Print the domain *and* the subset that triggered, not counts.
- Assert the subset is non-empty - `toBeGreaterThan(1)` where the instrument can match itself.
- When a scanner's needle appears in its own source, split the literal (`'A::'.'b('`) rather than excluding the file by name: an allow-list entry is the start of drift, a split string is not.
- Before accepting a marker, grep the tree for what the assertions *actually look like*. "Asserts a foreign key" looks greppable and is not.

See [[name-an-instrument-by-what-it-scans]], [[derive-a-list-or-it-drifts]].

---
name: a-prose-disclaimer-defeats-its-own-regex
description: a SQL/comment header that explains "no DROP here" or "not IF NOT EXISTS" contains the literal forbidden token itself
metadata:
  type: feedback
---

A header comment written to explain why a dangerous clause is absent (e.g.
`.github/ci/register-ci-tenant.sql`'s "-- No DROP anywhere... is deliberately
not IF NOT EXISTS") necessarily contains the literal forbidden word. A test
asserting `not->toMatch('/\bDROP\b/i')` or `not->toContain('IF NOT EXISTS')`
against the raw file text fails immediately against the file's own, correct,
disclaiming prose — not against a real defect.

**Why:** discovered writing CiWorkflowShapeTest's W-2 fix (T-024 QA
round): the reviewer's suggested regex, applied to the raw file, is red on
the already-approved SQL file.

**How to apply:** when a control is "this executable text must not contain
X" and the file is expected to carry human-readable prose *about* X, strip
`--`/`#`/`//` comment lines (or otherwise isolate the executable statements)
before matching, not the raw file. Verify emptied/stripped text against the
real file first - don't assume the regex is innocent until you've run it.
Related: [[name-an-instrument-by-what-it-scans]] (same family: know exactly
what text a check actually runs against).

---
name: a-control-must-redden-the-file-the-plan-names
description: Run the negative control and report which file actually went red - a plan that names the wrong instrument is a finding, and env() silently hands configuration a string
metadata:
  type: feedback
---

**A negative control is only evidence once you have watched the named test go
red. If a different file reddens, say so plainly — that is a finding about the
plan, not a detail to smooth over.**

**Why:** T-009 step 3's control (a scratch migration with a `timestamp` column
and a bare `decimal`) was specified to redden `MigrationSourceRulesTest`. It
did not: that file scans **fixtures only**, as its own header states, and the
shipped-tree sweep belongs to `MigrationTreeDisciplineTest` — which reddened
with AC-35(a) and AC-35(c) named. Reporting the wrong-file result cost one
paragraph; believing the plan would have meant running a control that could
never fail. Three of `dev`'s earlier findings on this task were upheld and two
changed the plan, so the lead wants the measurement.

**How to apply:** create and remove the control in **one** command with a
`trap`, print whether the file is gone, and run the named test *plus* the one
you think actually owns the rule. Quote both results.

**The companion, same shape, different mechanism: a measurement can contradict
the shipped idiom too.** `env()` casts only `"true"`, `"false"`, `"null"` and
`"empty"`. A numeric value from `phpunit.xml` or a `.env` therefore reaches
configuration as the **string** `"1024"` while the default reaches it as the
integer `65536` — the framework's own `config/hashing.php` does not cast, and
the first red in T-009 step 4 was a test comparing `1024` against `'1024'`. The
fix is the cast in the config file with the reason written there, not a test
that expects whichever type today's environment produced. A configuration value
whose type depends on where it was read from is a trap for the next reader.

Related: [[feedback-mutation-must-move-the-observable]],
[[feedback-guard-must-not-disturb-what-it-measures]].

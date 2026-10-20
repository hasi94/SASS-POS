---
name: shell-escaping-destroys-php-namespaces
description: sed/perl one-liners through the Bash tool eat backslashes and $1, silently mangling PHP `use` statements across many files at once
metadata:
  type: feedback
---

Never edit PHP namespaces or `use` statements with a `sed`/`perl` one-liner
through the Bash tool. Write a throwaway PHP script into the scratchpad with
the Write tool and run it with the php binary, or use the Edit tool.

**Why:** on T-010 step 16 a `sed` meant to insert
`use Flexipos\Access\Support\AuditWriter;` into fourteen handlers produced
`use FlexiposAccessSupportAuditWriter;` in every one of them, and the repair
one-liner then wrote a literal `$1` over the class names. Backslashes are
consumed by one layer of quoting and `$1` by another, and both failures are
silent: the files still parse as a list of lines, nothing errors until the
test run. Fourteen files were damaged in one call, and the only thing that
made it recoverable was that they had been committed minutes earlier.

**How to apply:** the rule triggers on any replacement whose text contains a
backslash, a `$`, or a regex capture. A PHP (or Node) script passed as a file
path has no quoting layers at all. Check the result with `git diff --stat`
immediately: an insertion count that is not exactly what you intended is the
tell. And commit before a sweeping mechanical edit - `git checkout --` was the
whole restore path here. See [[feedback_red_phase_needs_a_restore_path]] and
[[feedback_windows_line_endings_in_scripts]].

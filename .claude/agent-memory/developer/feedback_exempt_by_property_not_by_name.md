---
name: feedback-exempt-by-property-not-by-name
description: When a static scan flags something legitimate, exempt it by a property the artefact itself reports - never by adding its name to a list
metadata:
  type: feedback
---

A scan that reports a legitimate artefact has three possible fixes, and only two
are allowed: **reword the artefact**, or **narrow the rule to a property the
artefact itself reports**. Adding the name to an allow-list is the third, and it
is forbidden.

Worked examples from T-008 (2026-10-14):

- AC-6's credential-column scan reported `platform_settings.is_secret`,
  `tenant_databases.credential_version` and `credential_rotated_at` - all
  required by the design, none able to hold a password. The rule now asks the
  schema for the column's **type** and skips numbers, booleans and datetimes. A
  list of three blessed names would have been the first entry of an allow-list;
  a type is a fact about the column, and the control plants
  `credential_version_text` as a string and is still reported.
- AC-33 ("an expand step may not drop or change") would have reported `tenants`'
  own `ALTER TABLE tenants MODIFY sequence …`. The rule now skips tables the
  same migration created - a statement against a table that had no rows a moment
  ago cannot lose data. The alternative on offer was to write
  `MODIFY sequence` instead of `MODIFY COLUMN sequence` so the pattern missed
  it, which is enforcement by syntax accident.
- AC-35(d)'s one exemption is a single path, and that file is still checked - by
  a narrower rule permitting exactly `ascii` and `ascii_bin`. An exemption that
  stops checking is a hole; an exemption that changes the rule is a rule.

**Why:** the first allow-list entry is always for something that "obviously"
does not mean it, and the second is added by whoever is in a hurry. This project
already has the precedent for messages (reword the string, never exempt the
file); this is the same rule for shapes.

**How to apply:** when a scan goes red on something correct, ask what *property*
distinguishes it - a type, a phase, a table it created itself - and express the
rule in terms of that. If no such property exists, the artefact is what changes.
Related: [[feedback-mutation-must-move-the-observable]],
[[feedback-guard-must-not-disturb-what-it-measures]].

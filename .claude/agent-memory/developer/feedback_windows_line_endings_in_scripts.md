---
name: feedback-windows-line-endings-in-scripts
description: Never patch repo files with Python/text-mode writes on this machine - it silently converts the whole file to CRLF and fails Pint or corrupts another role's file
metadata:
  type: feedback
---

When batch-editing files from the Bash tool on this machine, **open in binary mode**
(`open(p, 'rb')` / `open(p, 'wb')`) and do not trust the Edit/Write tools either: on 2026-10-14 every file the Write and Edit
tools touched here - new PHP sources, `phpunit.xml`, `composer.json`, an existing `Pest.php` -
came out entirely CRLF, which Pint then rejected. Python's text-mode
`open(path, 'w')` applies universal-newline translation on Windows and rewrites the **entire
file** as CRLF, not just the lines you touched.

**Why:** it happened. A `python - <<'PY' ... open(path,'w').write(s)` patch turned
`packages/kernel/src/Tenancy/TenancyServiceProvider.php` and the architect-owned
`docs/plans/T-003-tenant-connections.md` into CRLF. Pint caught the source file with its
`line_ending` fixer and failed the gate; nothing checks `docs/`, so the plan file would have
landed as a whole-file diff against its LF siblings (`docs/specs/`, `docs/decisions/`) and
looked like a rewrite of another role's output. This is the same hazard as backlog row FP-156
(no root `.gitattributes`; `core.autocrlf` is true here).

**How to apply:** after any scripted edit, run
`grep -qU $'\r' <file>` over everything touched - including `*.stub`, `*.md`, `*.yaml` and
`*.xml`, which Pint never inspects, so a bad line ending there is invisible until review. Do
this before running the gate, not after.

**Second hazard in the same place, measured 2026-10-20: the Bash tool eats
backslashes inside a quoted heredoc.** A quoted heredoc should pass its body
through literally, and here it does not - doubled backslashes in a Python
literal arrive collapsed. Writing a PSR-4 namespace key that way produced
invalid JSON in composer.json, and the retry died with a SyntaxError.
Symptom: a "SyntaxWarning: invalid escape sequence" naming a sequence you
wrote with doubled backslashes.

**How to apply:** never put a literal backslash inside a heredoc. Bind
`B = chr(92)` and concatenate, or let `json.dumps()` emit the escaped form,
and re-read the file afterwards: the write can succeed with the wrong bytes
and the only signal is a warning on stderr.

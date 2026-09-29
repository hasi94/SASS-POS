---
name: feedback-windows-line-endings-in-scripts
description: Never patch repo files with Python/text-mode writes on this machine - it silently converts the whole file to CRLF and fails Pint or corrupts another role's file
metadata:
  type: feedback
---

When batch-editing files from the Bash tool on this machine, **open in binary mode**
(`open(p, 'rb')` / `open(p, 'wb')`) or use the Edit/Write tools. Python's text-mode
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

---
name: stage-files-during-a-gate-run
description: How to keep writing a big step while a 9-minute gate runs, without contaminating it (T-014)
metadata:
  type: feedback
---

Never write into the repo while `composer check` or a full pest run is going: slice-layout and source-sweep tests read the tree live, and a new slice without its mirrored test folder reddens `VerticalSliceLayoutTest` mid-run. Write the next step into a `scratchpad/stage/` tree that mirrors the repo, plus one `install.php` that copies it over and applies the in-place edits (anchored string replacements that exit non-zero when an anchor has moved), and run it after the gate reads green.

**Why:** a gate is 6 to 11 minutes here and the stage-then-install shape let steps 9 and 10 (about 60 files) be drafted during step 8's gate with no stray red.
**How to apply:** the helper scripts and the stage live in the session scratchpad, never the repo. Large `cat <<'EOF'` blocks fail on apostrophes in this shell (see [[worktree-env-and-gate-baseline]]); use the Write tool for each file, or a PHP generator for a family of near-identical thin files (the seven terminal controllers were one generator).

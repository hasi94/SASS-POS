---
name: feedback-correct-a-claim-in-every-copy
description: When a framework or factual claim turns out false, correct every copy in the same commit and prove absence with a whitespace-collapsed sweep - the code is never the only place it was written
metadata:
  type: feedback
---

A false claim is never in one place. Correcting it where you noticed it is half the job, and the
half that is left is the one a reviewer finds.

**MEASURED on T-007.** "`DatabaseManager::build()` makes the caller supply `name`" is false - the
framework defaults the key and invents `dynamic_<md5>`. Commit `78ae627` corrected it in
`TenantConnectionAuthority::digest()`'s docblock. Review round 1 then found it **verbatim** in
`TenantStanzaForgeryTest`, which is that mechanism's published proof, and the sweep that followed
found it **twice more in my own agent memory**. Four copies, one correction. The conclusion was
unaffected every time, which is exactly why nothing reddened.

**How to apply.**

- Sweep with **whitespace collapsed per file** (`re.sub(r"\s+", " ", text)`), never line by line:
  the claim wraps differently in a `//` comment, a `*` docblock and a Markdown bullet, so a
  line-oriented grep returns a clean pass. One such false pass stood three rounds on this project.
- Sweep **every text extension**, `.md`, `.yaml`, `.stub` and `.claude/agent-memory/` included.
  Pint and PHPStan inspect none of those, so a stale claim there is invisible until review.
- Match on the *reason*, not the sentence. Search for the shape (`caller\s+(?:to\s+)?suppl`,
  the symbol name near the key) and read the context; a verbatim-string search finds one copy.
- Expect residual hits and read each one: after the fix every remaining match should be a
  **quotation naming the claim as false** (the plan, the review, the "this used to say" note).
  Zero hits usually means the needle was too narrow.
- Write the correction out rather than swapping it silently - "this comment used to say X; it does
  not, and here is the commit that corrected it" - so the next reader knows the conclusion
  survived its reason being wrong.
- **Cite framework internals by symbol, not by line.** `DatabaseManager.php:195` became `:192`
  within one task; no test checks a line number, so it is decoration that rots. Name the method
  and the expression and point at the test that pins it.

**Why:** this is FP-176's class - a correct mechanism whose written justification is wrong -
and it recurred *inside* the task filed against it. The gate cannot catch it: every copy is a
comment.

Related: [[project-tenancy-connection-invariants]], [[feedback-defer-in-a-comment-or-file-a-row]],
[[feedback-guard-must-not-disturb-what-it-measures]].

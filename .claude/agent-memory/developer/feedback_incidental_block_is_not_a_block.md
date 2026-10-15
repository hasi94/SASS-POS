---
name: feedback-incidental-block-is-not-a-block
description: How to decide whether an optional defence-in-depth suggestion earns code - check whether the thing blocking it downstream is documented as pending widening
metadata:
  type: feedback
---

**When a reviewer calls a hazard "already blocked downstream", find the block and read
what it is coupled to. A block that sits on a registry whose own comment says it is
waiting to be widened is not a block - it is a coincidence with an expiry date.**

**Why:** T-008 review S-4 was marked optional because an operator switching
`tenancy.mapping.source` to the configuration stand-in in production would be stopped by
the secret resolver, which refuses `env://` outside local development. Measured: the
stand-in's mappings only ever carry `env://` references because that is the *only* scheme
`SchemeSecretResolver` is constructed with, and that construction's own comment says
"refused ... until the task that needs it adds a driver". The first task that registers
`file://` or `vault://` deletes the block without ever opening the tenancy bindings. The
hazard was one unrelated commit away from being live, so the refusal was written where the
source is chosen. The three other optional items on the same round were prose-only and
stayed prose-only.

**How to apply:** for each optional suggestion, name the mechanism that currently makes it
unreachable and ask what ordinary future change removes that mechanism
([[feedback-defer-in-a-comment-or-file-a-row]] asks the same question about who could
trigger a gap). If the answer is "a task already anticipated in a comment", take it. Two
details the shape needs here: refuse on an **allow-list of local environments**, never
`!== 'production'` (review R-6: `prod`, `live` and house names walk through), and reference
the existing list symbol rather than copying the names
([[feedback-derive-a-list-or-it-drifts]]) - two guards answering "is this local
development" that drift leave one of them a hole.

**A docblock bound is softened, not enforced, when enforcing it inverts the failure
direction.** Same round, S-1: `PerTenantLock`'s docblock claimed a length check the code
does not make. A throw would have turned a harmless over-lock (two truncated names sharing
one lock, one tenant reported `skipped`) into a failed tenant, so the sentence was rewritten
to name the real source of the bound - the `CHAR(26)` id column - and a test derived the
headroom from a generated ULID instead of a sample id. State the direction of the failure in
the docblock; that is what makes "documented bound" a decision rather than a shrug.

# Code review: T-037 (FP-236, split) The till application

Branch `ai/T-037-till-application`. Reviewers `reviewer37` to `reviewer37f`, read-only. Saved by the lead from their reports.

## Round 1 - tip `82cd8ac` - CHANGES REQUESTED, one Critical
R-1 refund approval outlived the refund it was granted for (reused after a refusal for a bigger refund). W-1 changed retry skipped the read-back, W-2 late answer closed the wrong dialog, W-3 offline gating incomplete (approval request sent offline), W-4 notifier could reorder refusals. Fix round 1 `4ce06da`.

## Round 2 - tip `4ce06da` - CHANGES REQUESTED, one Critical
R-2 after two lost answers the read-back baseline was overwritten, so a different refund shape could be sent while the first refund's fate was unknown (double payout). W-5 timeout window, W-6 wording, W-7 comment, W-8 shift-menu guard. Fix round 2 `a01b8f9`.

## Round 3 - tip `a01b8f9` - CHANGES REQUESTED, one Critical
Mode/sale switches dropped the pending check; baseline pollution on repeated losses. Fix round 3 `d864d87`.

## Round 4 - tip `d864d87` - CHANGES REQUESTED, two Critical
Dialog stuck after a full-quantity refund that landed (form revalidated against the reloaded sale); late answer from a closed session wrote into the next one. The lead replaced the design (fix round 4, `b2552b1`): on a lost answer the exact request is frozen and only "Send again" (same ids, no revalidation) or "Close" is offered; a session counter guards late answers.

## Round 5 - tip `b2552b1` - CHANGES REQUESTED, two Critical
"Definite refusal" was status != 0, so 5xx, non-envelope replies and `replay_conflict` cleared the frozen refund; the session was not ended on close; Send again was not offline-gated; payment store had the same loose test. Fix round 5 `7bf2dda` (`definitelyRefused`: coded 4xx only, `replay_conflict` excluded; `RefundStore.end()` on destroy; payment store aligned).

## Round 6 - tip `7bf2dda` - APPROVE, no Critical
Warnings accepted as numbered follow-ups (not blocking): W-1 a definite refusal of a Send again unfreezes the form although the first send's fate is unknown - safe only once the server replays a stored id before validating (follow-up B-T037-replay, README); W-2 `sale-store.ts` `failed()` marks the cart for reload only on status 0, use `!definitelyRefused`; W-3 `shift-store.ts` cash movement can get a new id after an unknown outcome and a retyped amount (pending flag like the payment store). Suggestions: key `pendingCheck` by sale id; stale-approval check should require a definite refusal; capture the session in the refund store's read paths; keep `attempt` across sessions (tradeoff).

NOT VERIFIED: the real backend (replay-before-validate), Escape and `closeAll` closing the refund dialog (read only), 44 px targets, focus ring, contrast, screen-reader behaviour.

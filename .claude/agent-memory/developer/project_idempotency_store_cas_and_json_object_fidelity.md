---
name: idempotency-store-cas-and-json-object-fidelity
description: T-021 review round - CAS on the stale-claim takeover, and why decode(assoc) can't survive a replay round-trip
metadata:
  type: project
---

`IdempotencyStore::resolveExisting()`'s stale-claim takeover (packages/kernel/src/Api/Idempotency/IdempotencyStore.php) must CAS on the exact `created_at` it read (`->where('created_at', $row->created_at)` on the takeover `UPDATE`, check the affected-rows count) - without it, two competing retries on the same stale row both match the unconditional `UPDATE` and both get `Claimed` back, running the handler twice. The timeout guarantees the new timestamp always differs from the old one, so a reliable affected-rows count is available for free.

Separately: `json_decode(..., true)` then re-`json_encode()` cannot distinguish an empty JSON object `{}` from an empty JSON array `[]` (PHP has one empty-array literal for both). Any store-then-replay or decode-then-reencode round trip of a stored API response body silently turns `{}` into `[]`. Fix: decode without the `true` flag (keep `stdClass` for objects) at both the point the response is captured for storage (`RequireIdempotencyKey::runAndStore`) and the point it's read back for replay (`IdempotencyStore::resolveExisting`'s `IdempotencyOutcome::replay()`). The consequence ripples: `replay()`'s meta.request_id patch has to branch on `is_object()` vs `is_array()` instead of assuming array, and any existing unit test asserting `toBe(['key' => 'val'])` against a replayed body needs `toEqual((object) ['key' => 'val'])` instead.

**How to apply:** when reviewing or extending idempotency replay code, treat "stores a response containing `{}` and asserts the replay still has `{}` not `[]`" as the canonical regression test for this whole class of bug - it exercises both round-trip points at once when done as an HTTP-level test (`modules/host/tests/Feature/IdempotencyHttpTest.php`).

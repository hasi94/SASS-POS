---
name: hasher-seam-and-assertion-reds
description: How to swap the hasher for a counting double without touching the Hash facade, why Pest scalar datasets fail phpstan here, and the idiom for proving an assertion is red
metadata:
  type: project
---

Three seams measured while building T-009's `Login` slice.

**`Hasher::class` is an alias of `hash.driver`, not of `hash`.** A handler
that injects `Illuminate\Contracts\Hashing\Hasher` receives the *default
driver* (Argon2id), while the `Hash` facade resolves `hash`, the manager.
`Container::instance(Hasher::class, $double)` unsets the alias entry, so
after it, injection gets the double and `Hash::make()` in a fixture still
gets the real driver. That split is what makes a counting-hasher double
usable: the digests it counts checks against are real ones.
**Why:** AC-8 needed invocation counts, and a double bound under `hash`
would also have replaced the hasher the fixtures seed accounts with.
**How to apply:** bind doubles under the contract, seed with the facade.

**A real Argon2id verify costs ~207 ms with the shipped factors**
(m=65536, t=4, p=1) on this machine, against ~1 ms with `phpunit.xml`'s
test factors. A constant digest written for production therefore makes each
test case that hits it pay the full cost. `hashing.verify` is `true`, so
Laravel's Argon2id driver throws `RuntimeException` on any digest that is
not Argon2id - a placeholder string turns that path into a 500 in
production only.

**Pest dataset values must be arrays at phpstan level 6.**
`->with(['a case' => true])` fails `argument.type`; `->with(['a case' =>
[true]])` passes. Named scalar datasets look fine until the gate runs stan.

**To prove an assertion discriminates, run the assertion itself twice.**
Extract it into a function, call it once on good input (green) and once
inside `expect(fn () => ...)->toThrow(PHPUnit\Framework\ExpectationFailedException::class)`
on polluted input. This is how AC-1's no-leak scan and AC-8's parity check
each carry their own negative control without mutating `src/`.
See [[feedback_mutation_must_move_the_observable]] and
[[feedback_observe_during_the_write_not_after]].

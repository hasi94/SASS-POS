---
name: type-graph-rule-does-not-see-a-literal
description: A Deptrac rule proves nobody references a symbol; it says nothing about a copy of that symbol's values, and the two get confused when a boundary rule replaces a textual search.
metadata:
  type: feedback
---

A Deptrac ruleset is a claim about **references between types**. It reports a
`use`, a type hint, an instantiation, a static call. It does **not** report a
fully-qualified class name written as a string (double- or single-quoted,
including a `[FQCN, 'method']` callable), and it does not report a bare-word
copy of the *values* a class holds (`['active', 'read_only']`).

**Why:** T-009 step 1 replaced AC-47's textual search of the authentication
sources with `Package_Auth` being granted `Package_Kernel` but not a carved-out
`Package_Kernel_TenancyStatus`. The plan said this "still reports the
double-quoted copy that defeated the scan on T-008". Measured with three probe
shapes in `packages/auth/src`: the type reference was reported, the string FQCN
in all three spellings was not, and a class holding only `'active'` and
`'read_only'` as bare words produced zero violations. The rule is strictly
stronger than a grep for *symbol reuse* and strictly blind to *value copying* -
which is the evasion the grep was trying to catch in the first place.

**How to apply:** when a boundary rule is offered as the executable replacement
for a textual proof, ask which of the two the criterion is about. Symbol reuse
-> Deptrac is the right instrument. A duplicated list of values -> only the
moved-value proof (hand the dependents a different policy object and watch the
outcome follow) closes it; the boundary rule is a useful second lock, not the
lock. Related: [[prove-an-authority-by-moving-it]],
[[derive-a-list-or-it-drifts]].

**Two Deptrac mechanics found while doing it.** A token matched by two layers is
in both: Deptrac warns on every run and evaluates each depender-layer/
dependee-layer pair separately, so carving a layer out of `Package_Kernel` with
a plain `classLike` collector produced 70 violations inside the kernel itself.
Keep carved layers disjoint with a negative lookahead in the parent's collector
(`^Flexipos\\Kernel\\(?!Tenancy\\(A|B|C)$).*`) rather than a `bool` collector:
`QaToolchainRegistryCoverageTest` reads only top-level `classLike` collector
values, so a `bool` collector makes the parent layer look like it collects
nothing and the registry guard goes red.

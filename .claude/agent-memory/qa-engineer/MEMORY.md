# QA engineer memory

- [Service host fragile areas](project_service-host-fragile-areas.md) — T-001/T-002 defect patterns that produced green-but-meaningless gates; what is guarded now, what still trusts the manifest.
- [Negative-control every guard](project_negative-control-every-guard.md) — making an assertion-of-absence mean something: the `toContain()` trap, the four tautology shapes, mutation-testing a bound.
- [Tenancy boundary leak surfaces](project_tenancy-boundary-leak-surfaces.md) — which connection-construction paths skip the tenant guard, which dumpers print a "redacted" credential anyway, what survives a purge.
- [Assert the contract, not the symptom](feedback_assert-contract-not-symptom.md) — also covers symptom-as-precondition, and correcting a disproved belief everywhere it is stated.
- [Gate split and shared state](project_gate-split-and-shared-state.md) — fast/full counts, proving coverage by name from git, the clone-vs-worktree line-ending proof, and the two shared cache files that fake a red.

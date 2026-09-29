# QA engineer memory

- [Service host fragile areas](project_service-host-fragile-areas.md) — T-001/T-002 defect patterns that produced green-but-meaningless gates; what is guarded now, what still trusts the manifest.
- [Negative-control every guard](project_negative-control-every-guard.md) — the techniques that make an assertion-of-absence mean something here, plus the Pest `toContain()` trap that fakes one.
- [Tenancy boundary leak surfaces](project_tenancy-boundary-leak-surfaces.md) — which connection-construction paths skip the tenant guard, which dumpers print a "redacted" credential anyway, what survives a purge.
- [Assert the contract, not the symptom](feedback_assert-contract-not-symptom.md) — also covers symptom-as-precondition, and correcting a disproved belief everywhere it is stated.

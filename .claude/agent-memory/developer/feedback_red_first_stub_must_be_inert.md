---
name: red-first-stub-must-be-inert
description: A permissive stub written to prove a guard red destroyed 16 of the owner's databases; how to run a red phase that cannot act.
metadata:
  type: feedback
---

A stub written to prove a guard red must be **incapable of acting**, not merely
expected not to act. Before running a red phase, ask what the stub is *wired
into*, not what the stub does.

**Why:** On 2026-10-14, T-008 step 0 required `ReservedDatabaseName` to be
proven red against a stub that returned true for every name. The test file also
asserted a sweep ("pointed at the engine, the sweep returns only reserved
names") through an `EngineDatabase` method that dropped every name the filter
returned. With the stub, the filter returned all 56 schemas on the shared
engine and the sweep dropped them in alphabetical order until it was killed.
**Sixteen of the owner's databases were destroyed, unrecoverably**, three of
them live-looking client systems. My reasoning had been "only the guard is
wrong, and nothing drops anything yet" — both halves false. See
[[project-shared-mariadb-engine-is-production]].

**How to apply:**
- A red phase must touch no irreversible resource. Point the subject at an
  inert double (here: the suite's sqlite connection, where `create database` is
  a syntax error, which *also* proves the guard let the name through).
- Never let a test call an operation whose blast radius is a whole shared
  resource. A sweep belongs in one tool that runs ahead of the suite, with a
  count ceiling: a filter claiming dozens of items is broken, not busy.
- Two independent checks at the point of effect, each with its own literal, so
  one wrong class is not enough. Measured value, in the same incident: with the
  guard class stubbed out, 11 of 13 forbidden names were still refused by the
  second rule.
- Test the filter offline against the **real recorded list** of the resource's
  contents. That is the input the stub was given, and the only one that proves
  the rule.
- Never launch the same destructive run twice concurrently. I did, and two
  in-flight statements continued after `taskkill` reported success — a killed
  client does not abort a running `DROP DATABASE` on the server.

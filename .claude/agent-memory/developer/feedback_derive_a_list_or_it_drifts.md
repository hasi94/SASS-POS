---
name: derive-a-list-or-it-drifts
description: Two sites that must agree on a set read one authority; PHP const expressions cannot do the arithmetic, so the derived side becomes a static method.
metadata:
  type: feedback
---

When two sites must agree on a list - a gate's accepted statuses and a
planner's accepted statuses - **one owns the literal and the other reads it**.
Never a second literal with a test asserting equality.

**Why:** BUG-1 in T-008 was exactly two copies drifting apart: the planner
accepted four statuses, `Tenancy::enter()` accepted one, and three of the four
produced a job row that could only fail. The architect's ruling: "a derived
list cannot drift; a copied one is one edit away from BUG-1 again."

**How to apply:**

- The authority goes on whatever already scopes the rule (here the purpose
  enum), and on the side of the dependency arrow that does not invert layering.
- **Put the list where a test can rebind it, which is not an enum or a
  constant.** T-008 step 13e moved it to a `final` object with a private
  constructor and two factories (`shipped()` / `withScratchList()`), bound as a
  container singleton. An enum constant cannot be rebound, and that - not
  taste - is what made the first attempt unprovable. `array_diff` is still not
  a constant expression, but inside an object the subtraction is just a method,
  so the deviation disappears with the shape.
- Equality alone is not the tooth: see [[prove-an-authority-by-moving-it]].
  **Do not pair it with a source scan** - an earlier version of this note
  recommended one and `qa` evaded it with the same literal in double quotes
  while eleven tests stayed green. A textual search tries to prove a negative
  about text and the set of texts meaning the same thing is unbounded; the
  architect refused the third patch to one on this project. Pair it with a
  behavioural case whose **dataset is read from the authority**, and with a
  moved-list case: bind a policy naming a status nobody shipped and assert
  every dependent accepts it, then bind one missing a shipped status and assert
  every dependent refuses it. Keep a control on the *un*-moved half (here the
  serving list), or injectability has bought a hole.

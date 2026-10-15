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
- `const A = Other::B;` is legal and is a genuine read. **`array_diff` is not
  legal in a constant expression**, so a set defined by subtraction has to be a
  `public static function` - accept the signature change rather than writing
  the literal out "just for now".
- Equality alone is not the tooth: see [[prove-an-authority-by-moving-it]]. Pair
  it with a source scan asserting the derived site names none of the authority's
  values, and with a behavioural case whose **dataset is read from the
  authority**, so a value added to the enum is immediately demanded of the other
  side.

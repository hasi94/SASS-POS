---
name: t009-round2-ingress-and-pin-blind-spots
description: T-009 re-test lessons - ingress channels are not sinks, column-name-keyed query pins miss select-star, QA files must pass Stan/Pint, report is CRLF
metadata:
  type: project
---

A scan of written places (sinks) cannot see a channel a secret ARRIVES by (the request URL). Pin ingress by behaviour per endpoint, and add a route-enumeration test so a new endpoint is not unpinned. Do not call an ingress channel a "sink".

**Why:** R-1 (`input()` merges the query string) was invisible to the four-sink sentinel harness.
**How to apply:** when a pin is per-endpoint, add a completeness test comparing the route set with the pinned set, with a planted-route liveness control.

A query-log "no read" pin keyed on a column name misses `select *` / `->first()`. Pin "no select from the table after the first write" with a before-write liveness read instead.

Gate covers PHPStan and Pint over QA test files: use `->not()` not `->not`, type-hint `RouteCollectionInterface`, run `pint` on new files. `docs/qa/*.md` has CRLF in the working copy; edit via PHP str_replace after normalising, and write big text with Write, not a Bash heredoc.

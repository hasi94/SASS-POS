---
name: enumerate-the-sinks-not-the-scans
description: An instrument pointed at the wrong set of places cannot be fixed by mutating inside the set - ask what is outside it (the request URL was in nobody's list)
metadata:
  type: feedback
---

Before strengthening an instrument, ask whether its **list of places** is complete.
A harness that scans four sinks and mutates well inside all four still proves
nothing about a credential that never reached any of them.

**Why:** T-009 review R-1. The AC-26 sentinel harness enumerated `login_history`,
`security_events`, the application log and exception messages. QA owns that list and its
taxonomy: a **sink** is a place the application *writes* and the harness scans
afterwards; HTTP responses are an adjacent check; the request URL is neither - it
is an **ingress channel a credential arrives by**, outside the application, pinned
behaviourally per endpoint and never scanned. Calling it "a fifth sink" was
rejected, and rightly: no scan of written places could see it. All four auth controllers read
credentials with `$request->input($key)`, which is
`data_get($this->getInputSource()->all() + $this->query->all(), ...)` - the query
bag is merged as a fallback - so a password in the URL authenticated and was
written to nginx's `access.log`. 1145 green tests, and a mutant moving a
credential into the URL would have reddened none of them. Six earlier findings on
that task were all "the instrument does not discriminate"; this was a seventh and
different shape, and no amount of mutation testing inside the set reveals it.

**How to apply:** when a criterion is about absence ("no secret is logged", "no
tenant leaks"), write down the ingress channels and out-of-application destinations too - URLs and
access logs, `Referer`, browser history, cache keys, exception reporters, queue
payloads, metric labels. Pin those by behaviour at the boundary (the endpoint
refuses the URL-only credential, with the body-only control in the same test),
because a content scan cannot reach them. Related: [[feedback_a_prefilter_masks_the_statement]],
[[feedback_mutation_must_move_the_observable]].

**Laravel specifics worth keeping:** `$request->input()` sees the query string;
`$request->post()` is empty for a JSON request; `getInputSource()` is `protected`
and falls back to the query bag for GET. A body-only read is
`($request->isJson() ? $request->json() : $request->request)->all()`.

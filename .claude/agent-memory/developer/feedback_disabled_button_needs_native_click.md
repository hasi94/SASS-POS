---
name: disabled-button-needs-native-click
description: Angular/jsdom test proving a disabled button is not clickable must use .click(), not dispatchEvent(new Event('click'))
metadata:
  type: feedback
---

A test asserting a disabled `<button>` cannot be activated must call `button.click()`, not
`button.dispatchEvent(new Event('click'))`. Dispatching a bare synthetic `click` Event bypasses
the browser/jsdom disabled-element activation-behavior guard, so the handler still fires even
though `disabled` is `true` — the assertion reads as proof of a guard that isn't actually being
exercised.

**Why:** found while fixing FP-405 (T-025 review R-1): a context-page test asserting
`selectTenant` is not called for a non-selectable tenant initially passed a mutant (`dispatchEvent`
fired the handler regardless of `disabled`), then correctly went red once switched to `.click()`.

**How to apply:** any spec in `frontend/projects/shared/src/lib/**` that proves a disabled
control cannot be activated should drive the interaction with `.click()` (or a real user-event
helper), never a manually constructed `click` `Event` via `dispatchEvent`.

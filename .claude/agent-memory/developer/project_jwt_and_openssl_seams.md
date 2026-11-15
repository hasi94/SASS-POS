---
name: jwt-and-openssl-seams
description: firebase/php-jwt 7.x and OpenSSL on this machine - the time seam, what decode does and does not do with kid, the ES256 boundary, and where an openssl.cnf actually is.
metadata:
  type: project
---

Measured on the installed tree (`firebase/php-jwt` 7.2.x, PHP 8.2.12, OpenSSL
3.0.11) while building T-009's token slices. None of it is visible from the
library's README.

- **`JWT::$timestamp` is the only time seam, and it is a public static.** Set
  it before `decode()` and restore it in a `finally`, or a stopped clock leaks
  into the rest of the process. Without it an `exp` boundary cannot be tested
  at all - and a fifteen-minute token minted at a *fixed* instant is "expired"
  for anyone who runs the suite later in the day, which is how a test that
  decodes a token directly turns into a clock-dependent flake.
- **`decode()` with a single `Key` ignores the header `kid` entirely.** Key
  lookup by `kid` only happens when an *array* of keys is passed. So choosing
  the key - and therefore refusing an unknown or retired `kid` - is ours, and
  it means reading the `kid` out of the unverified header before verifying.
  That is correct: the `kid` names which key to try and grants nothing.
- **The library owns four refusals by contract**: `alg: none` (absent from
  `$supported_algs`), an `alg` that is not the key's
  (`constantTimeEquals($key->getAlgorithm(), $header->alg)`), an altered
  payload and a foreign signature (`SignatureInvalidException`), and `exp`
  (refused at exactly `exp`). A test of those proves the library, not us -
  assert `getPrevious()` so the evidence at least names the mechanism.
- **ES256 sits exactly on 7.x's key-size floor.** The floor is parsed out of
  the algorithm name (`(int) str_replace('ES', '', $algorithm)`), a P-256 key
  reports exactly 256 bits, and anything smaller raises
  `DomainException("Provided key is too short")` - which reads as a library
  fault rather than as a curve choice unless a test pins both halves.
- **`openssl_pkey_new()` needs a `config` path on this machine**, and the one
  to use is **`E:\xampp\php\extras\ssl\openssl.cnf`** - beside the PHP binary,
  so no XAMPP Apache path needs hard-coding. `openssl_pkey_export()` needs the
  same `['config' => ...]`; `openssl_pkey_get_details()` does not. A CI runner
  passes no `config` key at all, so that branch has to exist even though it
  fails here.

**Why:** two of these changed a design (the keyring does the `kid` lookup; the
verifier drives the library's static clock), and the openssl path removed a
hard-coded machine path from the plan's own wording.

**How to apply:** read this before writing anything that signs, verifies or
generates a key. Related: [[project-backend-toolchain-constraints]],
[[project-sqlite-standin-vs-engine]].

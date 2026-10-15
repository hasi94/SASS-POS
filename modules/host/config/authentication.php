<?php

/*
|--------------------------------------------------------------------------
| Authentication: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-009 step 4. Every value here is a duration, a count or a length. **Nothing
| in this file names a tenant status, a signing algorithm or a key**, and that
| is a rule rather than an accident:
|
| - the selectable `tenants.status` set is a compile-time constant on
|   `Flexipos\Auth\Tenancy\TenantSelectionPolicy`, in the same shape and for
|   the same reason as `TenantConnectionPurpose::SERVING_STATUSES` - an access
|   decision that an operator can widen from the environment is not an access
|   decision (plan section 1, AC-12);
| - the algorithm is ES256 and is named by the code that mints and verifies,
|   because a configurable algorithm is how alg-confusion gets in (Ruling 1);
| - keys live in `signing_keys` with a `private_key_ref`, never here (D-23).
|
| The figures themselves are conventional and the spec says so: 15 minutes,
| 14 days, 5 failures, 15 minutes of lock, and the two input caps are not
| owner-specified. They are environment-overridable so a deployment can tighten
| them without a release, and each is reversible.
|
| `limits.email` is 191 because that is `users.email`'s width, and
| `limits.password` is 1024 so that a submitted password is bounded well below
| any hasher's own limit while still accepting a long passphrase. AC-9 requires
| both boundary values to be **accepted as input** and to fail as credentials
| rather than as validation, so these two are the lengths validation permits,
| not the lengths it rejects.
|
| The lockout is per account, which lets a stranger lock a known address on
| purpose; D-5's refusal to extend a live lock keeps that from being
| indefinite, and per-IP throttling with a CAPTCHA is filed as FP-199 and marked
| as blocking public launch (D-29). Neither belongs in this file.
|
*/

return [

    /*
    | How long an access token is good for. Short, because nothing revokes one
    | before its `exp` (AC-48 states that as accepted behaviour).
    */
    'access_ttl_minutes' => (int) env('AUTH_ACCESS_TTL_MINUTES', 15),

    /*
    | How long a refresh token is good for. A family is rotated on every use
    | and revoked wholesale on replay; an absolute cap on a family's lifetime
    | is D-21 and is deliberately not implemented.
    */
    'refresh_ttl_days' => (int) env('AUTH_REFRESH_TTL_DAYS', 14),

    'lockout' => [
        /*
        | Consecutive failures that lock an account. The count resets on a
        | successful login and starts afresh after a lock expires (AC-16,
        | AC-20).
        */
        'threshold' => (int) env('AUTH_LOCKOUT_THRESHOLD', 5),

        /*
        | How long the lock lasts. A live lock is never extended by a further
        | attempt (D-5, AC-18), so this is a ceiling and not a sliding window.
        */
        'minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
    ],

    'limits' => [
        /*
        | The longest email and password the login endpoint accepts. Both are
        | accepted at exactly these lengths and refused above them (AC-9).
        */
        'email' => (int) env('AUTH_LIMIT_EMAIL', 191),

        'password' => (int) env('AUTH_LIMIT_PASSWORD', 1024),
    ],

];

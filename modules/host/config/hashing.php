<?php

/*
|--------------------------------------------------------------------------
| Password hashing: Argon2id, and nothing else
|--------------------------------------------------------------------------
|
| T-009 step 4, Ruling 1. `docs/database.md` section 4.1 says
| `password_hash (Argon2id)` and the spec's security NFR says passwords are
| verified against Argon2id hashes only. The framework's own default is
| bcrypt, so this file exists to change it - without it `hashing.driver`
| resolves to `bcrypt` from HashManager's own fallback and every hash this
| platform ever wrote would be the wrong algorithm.
|
| **Argon2id is available on this machine and needs no extension.** Verified on
| E:\xampp\php\php.exe 8.2.12: `PASSWORD_ARGON2ID` is defined and
| `password_hash($p, PASSWORD_ARGON2ID)` returns a real
| `$argon2id$v=19$m=...` digest. `ext-sodium` is absent and is **not** needed -
| PHP's bundled libargon2 provides the algorithm independently. There is no CI
| here (no `.github` directory; FP-218 is still TODO), so the runner image
| having Argon2 compiled in is an acceptance item for FP-218 rather than
| something this file can assert.
|
| **The work factors are environment-driven for one measured reason.** Default
| Argon2id on this machine costs 0.232 s to hash and 0.222 s to verify, against
| 0.0015 s / 0.0014 s at `memory_cost=1024, time_cost=1, threads=1` - a 150x
| difference. The spec's lockout criteria alone submit dozens of passwords, so
| at default cost that is tens of seconds added to a gate whose slowest step is
| budgeted at 106 s cache-cold. `modules/host/phpunit.xml` therefore names the
| cheap triple, exactly as its `BCRYPT_ROUNDS=4` entry already does.
| **The algorithm under test is still Argon2id**; only its work factor moves,
| and the spec's NFR already forbids asserting a duration.
|
| **`verify` stays at the framework's `true`, and it bites deliberately.** With
| it on, presenting a hash that is *not* Argon2id to `Hash::check()` throws
| rather than returning false, so "Argon2id only" is enforced at the call site
| instead of being a comment. Two consequences for later steps: the fixed dummy
| hash AC-8's unknown-email path verifies against must itself be an Argon2id
| digest, and if a non-Argon2id hash ever reaches this master the result is a
| loud 5xx rather than a silent credential refusal - which is the right way
| round for a platform whose only hash writer is this task.
|
| The `bcrypt` block is here for the stock driver that `Hash::driver('bcrypt')`
| builds, which is what the negative control for this step compares against
| (and it is why `BCRYPT_ROUNDS=4` in phpunit.xml has a reader again). Nothing
| in FlexiPOS hashes a password with it.
|
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    | The three factors are cast, which the framework's own stock file does not
    | do. `env()` casts "true", "false" and "null" and nothing else, so a value
    | that came from phpunit.xml or a .env arrives as the **string** "1024"
    | while the default arrives as the integer 65536. `password_hash()` coerces
    | either, so the hashes are identical - but a configuration value whose
    | type depends on where it was read from is a trap for the next reader, and
    | the test that pins these factors had to compare against a string to pass.
    */
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    /*
    | Off, and stated rather than omitted. The framework rehashes on login from
    | inside its own session guard, which this host does not use: it issues
    | signed tokens and has no `Auth::attempt()` path. Leaving the key absent
    | would make a cached configuration disagree with an uncached one about
    | whether anybody chose; `false` says nobody rehashes here, and moving the
    | work factor is a password-change concern, which is out of scope (D-17).
    */
    'rehash_on_login' => false,

];

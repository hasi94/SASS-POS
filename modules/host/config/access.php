<?php

/*
|--------------------------------------------------------------------------
| Access: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-010 step 12, in the shape `config/authentication.php` established and for
| the same reason. Every value here is a count. **Nothing in this file names
| an employee status, a permission key or a refusal reason**, and that is a
| rule rather than an accident:
|
| - the acting employee statuses are a compile-time constant on
|   `Flexipos\Access\Support\EmployeeStatus`, because a set of statuses an
|   operator can widen from the environment is not an access decision - the
|   identical argument `TenantSelectionPolicy` and
|   `TenantConnectionPurpose::SERVING_STATUSES` are built on;
| - the permission keys a module owns are declared by that module's provider
|   and synced into the tenant's `permissions` table (D-13), never listed
|   here;
| - the refusal vocabulary is `Flexipos\Contracts\Access\RefusalReason`
|   (D-26).
|
| The PIN figures the plan lists for this file - minimum and maximum digits,
| the lockout threshold and its minutes - arrive with the PIN slices in step
| 17, beside the code that reads them. A configuration key nobody reads is a
| promise the application does not keep.
|
*/

return [

    'pin' => [
        /*
        | The digit bounds of a cashier PIN (AC-47, D-16). Read by
        | `Flexipos\Access\Support\PinPolicy::shipped()` and by nothing
        | else, so the weak-PIN set is generated for exactly the lengths
        | this file accepts.
        |
        | They are here, and the acting statuses are not, because these are
        | operational figures rather than an access decision: a shop moving
        | to six-digit PINs widens nobody's rights. AC-52a's standing limit
        | is unaffected by the choice - an Argon2id hash of a secret from a
        | space this small is brute-forceable offline either way.
        */
        'min_digits' => (int) env('ACCESS_PIN_MIN_DIGITS', 4),

        'max_digits' => (int) env('ACCESS_PIN_MAX_DIGITS', 8),

        /*
        | The lockout, D-17's two figures: how many consecutive wrong PINs
        | lock an employee, and for how long. Read by `PinPolicy` and
        | through it by the verify slice, so the two numbers have one home
        | each.
        |
        | They mirror T-009 D-5's account lockout deliberately - one rule to
        | learn - and the no-extension rule (a failure while locked neither
        | counts nor postpones the unlock) is what bounds the denial of
        | service a deliberate lock-out would otherwise be.
        */
        'lockout_after' => (int) env('ACCESS_PIN_LOCKOUT_AFTER', 5),

        'lockout_minutes' => (int) env('ACCESS_PIN_LOCKOUT_MINUTES', 15),
    ],

    'employees' => [
        /*
        | How many employees one page of the employee list returns when the
        | caller asks for no particular size (AC-29).
        */
        'page_size' => (int) env('ACCESS_EMPLOYEES_PAGE_SIZE', 50),

        /*
        | The largest page the list will produce. A request for more is
        | CLAMPED to this, not refused (AC-29, and `architect`'s choice is
        | stated there): a caller asking for a thousand rows wants the data,
        | not an error, and a refusal would make the limit a thing every
        | client has to discover by failing.
        */
        'max_page_size' => (int) env('ACCESS_EMPLOYEES_MAX_PAGE_SIZE', 100),
    ],

];

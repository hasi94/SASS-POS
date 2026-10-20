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

<?php

/*
|--------------------------------------------------------------------------
| Organization: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-014. The way `config/access.php` is: every value is a count or a duration,
| and **nothing here names a status, a permission or a code alphabet** - those
| are compile-time constants of the package, because an access or activation
| decision an operator could widen from the environment is not a decision.
| The figures are conventional defaults the spec calls configurable (D-17,
| NFR "Defaults are configuration"), each reversible without a release.
|
*/

return [

    /*
    | List endpoints (branches, locations, terminals, settings): the default
    | page and the largest page a caller may ask for. A larger request is
    | clamped, not refused (AC-13).
    */
    'page_size' => (int) env('ORGANIZATION_PAGE_SIZE', 50),

    'max_page_size' => (int) env('ORGANIZATION_MAX_PAGE_SIZE', 100),

    /*
    | How long an activation code stays valid after it is issued or re-issued
    | (D-17). Single use, and consumed on activation whatever is left.
    */
    'activation_code_lifetime_hours' => (int) env('ORGANIZATION_ACTIVATION_LIFETIME_HOURS', 24),

];

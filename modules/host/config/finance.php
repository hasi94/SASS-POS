<?php

/*
|--------------------------------------------------------------------------
| Finance: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-032. The way `config/customers.php` is: every value here is a count.
| Nothing here names a reason code, an account type or a period status -
| those are `Flexipos\Contracts\Finance\*` and the pure rule classes, because
| a decision a shop could widen from the environment is not an accounting
| rule.
|
| It lives in the host's own `config/`, next to `customers.php`, because no
| package ships its own config directory.
|
*/

return [

    /*
    | Every list this task ships (accounts, fiscal years, periods) is paged
    | the same way (plan Ruling 10, AC-61): the default page and the largest
    | page a caller may ask for. Unlike the other packages a request outside
    | 1 to `max_page_size` is **refused** `page_size_invalid`, not clamped
    | (F-13, D-28).
    */
    'page_size' => (int) env('FINANCE_PAGE_SIZE', 50),

    'max_page_size' => (int) env('FINANCE_MAX_PAGE_SIZE', 200),

];

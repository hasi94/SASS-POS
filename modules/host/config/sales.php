<?php

/*
|--------------------------------------------------------------------------
| Sales: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-030. The way `config/customers.php` is: every value here is a count.
| Nothing here names a status, a refusal reason, a tender rule or a rounding
| policy - those are the package's own closed enums and pure rules (plan
| Rulings 3, 4 and 8), because a decision a shop could widen from the
| environment is not an arithmetic rule.
|
| It lives in the host's own `config/`, next to `customers.php`, because no
| package ships its own config directory.
|
*/

return [

    /*
    | Every list this task ships (approval requests, cash shifts, cash
    | movements, sales, sale lines) is paged the same way (D-26): the default
    | page and the largest page a caller may ask for. A larger request is
    | clamped, not refused.
    */
    'page_size' => (int) env('SALES_PAGE_SIZE', 50),

    'max_page_size' => (int) env('SALES_MAX_PAGE_SIZE', 100),

];

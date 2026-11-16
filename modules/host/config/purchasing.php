<?php

/*
|--------------------------------------------------------------------------
| Purchasing: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-034. The way `config/sales.php` is: every value here is a count. Nothing
| here names a status, a refusal code or a rounding policy - those are the
| package's own closed enums and pure rules.
|
| It lives in the host's own `config/`, next to `sales.php`, because no
| package ships its own config directory.
|
*/

return [

    /*
    | Every list this task ships (suppliers, purchase requests, purchase
    | orders) is paged the same way: the default page and the largest page a
    | caller may ask for. A larger request is clamped, not refused (D-34).
    */
    'page_size' => (int) env('PURCHASING_PAGE_SIZE', 25),

    'max_page_size' => (int) env('PURCHASING_MAX_PAGE_SIZE', 100),

    /*
    | The most lines one request or one order may carry (AC-24).
    */
    'max_lines' => 200,

];

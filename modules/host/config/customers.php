<?php

/*
|--------------------------------------------------------------------------
| Customers: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-028. The way `config/organization.php` and `config/access.php` are:
| every value here is a count. Nothing here names a ledger type, a refusal
| reason or a currency rule - those are `Flexipos\Contracts\Customers\*`
| and the pure calculators (plan Ruling 3 to 6), because a decision a shop
| could widen from the environment is not an arithmetic rule.
|
| It lives in the host's own `config/`, next to `organization.php` and
| `access.php`, because no package ships its own config directory (plan
| section 3).
|
*/

return [

    /*
    | Every list this task ships (customers, groups, addresses, ledger
    | entries, loyalty transactions, notes, communications) is paged the
    | same way (plan Ruling 11, D-17): the default page and the largest page
    | a caller may ask for. A larger request is clamped, not refused.
    */
    'page_size' => (int) env('CUSTOMERS_PAGE_SIZE', 50),

    'max_page_size' => (int) env('CUSTOMERS_MAX_PAGE_SIZE', 100),

];

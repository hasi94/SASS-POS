<?php

/*
|--------------------------------------------------------------------------
| HR (T-038 plan section 3)
|--------------------------------------------------------------------------
|
| `page_size` and `max_page_size` bound every HR list (AC-26, AC-38).
| `documents` bounds employee documents: the largest accepted file in bytes
| (AC-67), the detected content types accepted, and the most live documents
| one employee may hold (AC-69). The allow-list with its extension map lives
| in code (`Documents\FileRules`), where there is one list in one place.
*/

return [
    'page_size' => 50,
    'max_page_size' => 200,
    'documents' => [
        'max_bytes' => 10485760,
        'allowed_types' => ['pdf', 'jpeg', 'png'],
        'per_employee' => 100,
    ],
];

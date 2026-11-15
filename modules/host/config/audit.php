<?php

/*
|--------------------------------------------------------------------------
| Audit: numbers, and only numbers
|--------------------------------------------------------------------------
|
| T-017, in the shape config/access.php establishes and for the same reason:
| every value here is a count. Nothing in this file names a module, an
| action or a refusal reason - the declared action catalogue
| (Flexipos\Contracts\Audit\DeclaresAuditActions) and the refusal vocabulary
| each have their own one home already.
|
*/

return [

    /*
    | The default and maximum page size for both searches (AC-4, AC-16),
    | over the same T-014 D-28 clamping convention as the employee/branch
    | lists: a page above the maximum is clamped, never refused.
    */
    'page_size' => (int) env('AUDIT_PAGE_SIZE', 50),

    'max_page_size' => (int) env('AUDIT_MAX_PAGE_SIZE', 500),

    /*
    | How many days a tenant audit_logs row lives in the live table before
    | the scheduled job moves it into audit_logs_archive (D-11). A
    | non-positive value is refused by the archival handler rather than
    | treated as "archive everything now" - that is not a value a typo
    | should produce.
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    /*
    | How many rows the archival move selects per chunk. Clamped to at
    | most 900 by the handler, which stays under SQLite's default 999
    | bound-parameter limit.
    */
    'archive_chunk_size' => (int) env('AUDIT_ARCHIVE_CHUNK_SIZE', 500),

];

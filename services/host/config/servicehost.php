<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booted service packages
    |--------------------------------------------------------------------------
    |
    | A comma separated list of the service packages (directories under
    | services/) this host process should boot, for example "identity,catalog".
    | Whitespace around each name is ignored and duplicates are collapsed.
    |
    | An empty or unset value is valid: the host then boots with zero service
    | packages, which is a legitimate minimal configuration. A name that has no
    | matching package under services/ makes the host fail fast at boot.
    |
    */

    'services' => env('SERVICES', ''),

];

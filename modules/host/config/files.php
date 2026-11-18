<?php

/*
|--------------------------------------------------------------------------
| Document files (T-038 plan Ruling 6)
|--------------------------------------------------------------------------
|
| `document_root` is the directory the local document store writes under. It
| must be an absolute path outside every web-served directory: the store
| refuses a root inside `public_path()` or `storage_path('app/public')`.
| Stored keys are `<tenant>/<area>/<ULID>`, so one root holds every tenant.
| Set `DOCUMENT_STORE_ROOT` to move it (a mounted volume, for example).
*/

return [
    'document_root' => env('DOCUMENT_STORE_ROOT') ?: storage_path('app/private/documents'),
];

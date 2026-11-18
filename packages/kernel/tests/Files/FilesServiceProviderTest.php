<?php

use Flexipos\Kernel\Files\DocumentStore;
use Flexipos\Kernel\Files\LocalDocumentStore;
use Tests\TestCase;

uses(TestCase::class);

/*
 * T-038 plan step 8, Ruling 6: the host binds the local store, once, at a root
 * the store itself would accept (outside every web-served path).
 */

/**
 * A path in forward slashes and lower case, for a prefix comparison.
 */
function filesProviderFold(string $path): string
{
    return strtolower(str_replace('\\', '/', $path));
}

it('binds the local document store as a singleton', function () {
    $store = app(DocumentStore::class);

    expect($store)->toBeInstanceOf(LocalDocumentStore::class)
        ->and(app(DocumentStore::class))->toBe($store);
});

it('ships a document root under private storage and outside the public paths', function () {
    $root = filesProviderFold((string) config('files.document_root'));

    expect($root)->toEndWith('storage/app/private/documents')
        ->and(str_starts_with($root, filesProviderFold(public_path()).'/'))->toBeFalse()
        ->and(str_starts_with($root, filesProviderFold(storage_path('app/public')).'/'))->toBeFalse();
});

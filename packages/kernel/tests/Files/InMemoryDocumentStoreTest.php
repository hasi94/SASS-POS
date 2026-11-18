<?php

use Flexipos\Kernel\Files\DocumentKey;
use Flexipos\Kernel\Files\DocumentMissing;
use Flexipos\Kernel\Files\DocumentStore;
use Flexipos\Kernel\Files\DocumentStoreFailed;
use Flexipos\Kernel\Files\InMemoryDocumentStore;

/*
 * T-038 plan step 8 (AC-14, AC-69). The fake keeps the real store's rules and
 * is the spy the document operations are measured with.
 */

/**
 * @return resource
 */
function inMemoryDocumentStoreStream(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

it('round-trips put, open and delete and counts each call', function () {
    $store = new InMemoryDocumentStore;
    $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');

    $store->put($key, inMemoryDocumentStoreStream('bytes'));

    expect($store->has($key))->toBeTrue()
        ->and($store->keys())->toBe([$key])
        ->and($store->bytesOf($key))->toBe('bytes')
        ->and(stream_get_contents($store->open($key)))->toBe('bytes');

    $store->delete($key);

    expect($store->has($key))->toBeFalse()
        ->and([$store->puts, $store->opens, $store->deletes])->toBe([1, 1, 1]);
});

it('refuses an existing key, a missing key on open and a forged key, keeping the real rules', function () {
    $store = new InMemoryDocumentStore;
    $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');

    $store->put($key, inMemoryDocumentStoreStream('one'));

    expect(fn () => $store->put($key, inMemoryDocumentStoreStream('two')))->toThrow(DocumentStoreFailed::class)
        ->and($store->bytesOf($key))->toBe('one')
        ->and(fn () => $store->open(DocumentKey::mint('tenant-a', 'hr/employee-documents')))->toThrow(DocumentMissing::class)
        ->and(fn () => $store->put('../x', inMemoryDocumentStoreStream('x')))->toThrow(DocumentStoreFailed::class)
        ->and($store->keys())->toBe([$key]);

    $store->delete(DocumentKey::mint('tenant-a', 'hr/employee-documents'));
});

it('fails the next calls of a kind when its toggle is set, and still counts them', function () {
    $store = new InMemoryDocumentStore;
    $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');
    $store->put($key, inMemoryDocumentStoreStream('bytes'));

    $store->failOnPut = true;
    $store->failOnOpen = true;
    $store->failOnDelete = true;

    expect(fn () => $store->put(DocumentKey::mint('tenant-a', 'hr/employee-documents'), inMemoryDocumentStoreStream('x')))->toThrow(DocumentStoreFailed::class)
        ->and(fn () => $store->open($key))->toThrow(DocumentStoreFailed::class)
        ->and(fn () => $store->delete($key))->toThrow(DocumentStoreFailed::class)
        ->and([$store->puts, $store->opens, $store->deletes])->toBe([2, 1, 1])
        ->and($store->has($key))->toBeTrue();

    $store->failOnDelete = false;
    $store->delete($key);

    expect($store->has($key))->toBeFalse();
});

it('is a document store', function () {
    expect(new InMemoryDocumentStore)->toBeInstanceOf(DocumentStore::class);
});

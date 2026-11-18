<?php

use Flexipos\Kernel\Files\DocumentKey;
use Flexipos\Kernel\Files\DocumentMissing;
use Flexipos\Kernel\Files\DocumentStoreFailed;
use Flexipos\Kernel\Files\LocalDocumentStore;

/*
 * T-038 plan step 8 (AC-69, AC-70). Temp-dir tests: a real directory under
 * the system temp path, removed after each case. The web roots are passed
 * explicitly so no application is needed.
 */

/**
 * A fresh empty directory to use as a store root.
 */
function localDocumentStoreRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'t038-docstore-'.bin2hex(random_bytes(6));

    mkdir($root, 0775, true);

    return str_replace('\\', '/', $root);
}

function localDocumentStoreRemove(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        is_dir($path) ? localDocumentStoreRemove($path) : unlink($path);
    }

    rmdir($directory);
}

/**
 * @return resource
 */
function localDocumentStoreStream(string $bytes)
{
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

function localDocumentStoreNotAStream(): mixed
{
    return 'not a resource';
}

/**
 * Run a case against a fresh store and root, and remove the root afterwards.
 *
 * @param  callable(LocalDocumentStore, string): void  $case
 */
function localDocumentStoreRun(callable $case): void
{
    $root = localDocumentStoreRoot();

    try {
        $case(new LocalDocumentStore($root, [$root.'-public']), $root);
    } finally {
        localDocumentStoreRemove($root);
    }
}

it('round-trips put, open and delete', function () {
    localDocumentStoreRun(function (LocalDocumentStore $store, string $root) {
        $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');

        $store->put($key, localDocumentStoreStream('%PDF-bytes'));

        expect(is_file($root.'/'.$key))->toBeTrue();

        $in = $store->open($key);

        expect(stream_get_contents($in))->toBe('%PDF-bytes');
        fclose($in);

        $store->delete($key);

        expect(is_file($root.'/'.$key))->toBeFalse();
    });
});

it('leaves no temporary file behind after a successful write', function () {
    localDocumentStoreRun(function (LocalDocumentStore $store, string $root) {
        $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');

        $store->put($key, localDocumentStoreStream('abc'));

        expect(scandir(dirname($root.'/'.$key)))->toHaveCount(3); // ., .. and the document
    });
});

it('refuses a put over an existing key and leaves the stored bytes untouched', function () {
    localDocumentStoreRun(function (LocalDocumentStore $store, string $root) {
        $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');

        $store->put($key, localDocumentStoreStream('original'));

        expect(fn () => $store->put($key, localDocumentStoreStream('replacement')))->toThrow(DocumentStoreFailed::class);

        $in = $store->open($key);

        expect(stream_get_contents($in))->toBe('original')
            ->and(scandir(dirname($root.'/'.$key)))->toHaveCount(3);
    });
});

it('reports a missing key as DocumentMissing on open and accepts a delete of it', function () {
    localDocumentStoreRun(function (LocalDocumentStore $store, string $root) {
        $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');

        expect(fn () => $store->open($key))->toThrow(DocumentMissing::class);

        $store->delete($key);
        $store->delete($key);

        expect(is_file($root.'/'.$key))->toBeFalse();
    });
});

it('never lets a forged key reach the filesystem', function (string $forged) {
    localDocumentStoreRun(function (LocalDocumentStore $store, string $root) use ($forged) {
        $marker = $root.'-outside';
        file_put_contents($marker, 'secret');

        try {
            foreach ([
                fn () => $store->put($forged, localDocumentStoreStream('x')),
                fn () => $store->open($forged),
                fn () => $store->delete($forged),
            ] as $call) {
                expect($call)->toThrow(DocumentStoreFailed::class);
            }

            expect(file_get_contents($marker))->toBe('secret')
                ->and(scandir($root))->toBe(['.', '..']);
        } finally {
            unlink($marker);
        }
    });
})->with([
    'traversal out of the root' => ['../outside/hr/01JABCDEFGHJKMNPQRSTVWXYZ1'],
    'traversal in the middle' => ['tenant/hr/../../../01JABCDEFGHJKMNPQRSTVWXYZ1'],
    'a backslash' => ['tenant\\..\\hr/01JABCDEFGHJKMNPQRSTVWXYZ1'],
    'an absolute path' => ['/etc/passwd/hr/01JABCDEFGHJKMNPQRSTVWXYZ1'],
    'a drive letter' => ['C:/Windows/hr/01JABCDEFGHJKMNPQRSTVWXYZ1'],
    'a NUL byte' => ["tenant/hr\0/01JABCDEFGHJKMNPQRSTVWXYZ1"],
]);

it('refuses a root inside a web-served directory and accepts one beside it', function () {
    $web = localDocumentStoreRoot();

    try {
        expect(fn () => new LocalDocumentStore($web, [$web]))->toThrow(DocumentStoreFailed::class)
            ->and(fn () => new LocalDocumentStore($web.'/documents', [$web]))->toThrow(DocumentStoreFailed::class)
            ->and(fn () => new LocalDocumentStore($web.'/a/../documents', [$web]))->toThrow(DocumentStoreFailed::class)
            ->and(fn () => new LocalDocumentStore(strtoupper($web).'/documents', [$web]))->toThrow(DocumentStoreFailed::class);

        // The control: a sibling that merely shares a prefix is not inside.
        expect(new LocalDocumentStore($web.'-documents', [$web]))->toBeInstanceOf(LocalDocumentStore::class);
    } finally {
        localDocumentStoreRemove($web);
    }
});

it('refuses a relative root', function () {
    expect(fn () => new LocalDocumentStore('storage/documents', []))->toThrow(DocumentStoreFailed::class)
        ->and(fn () => new LocalDocumentStore('', []))->toThrow(DocumentStoreFailed::class)
        ->and(fn () => new LocalDocumentStore('../documents', []))->toThrow(DocumentStoreFailed::class);
});

it('refuses a stream that is not a resource without writing', function () {
    localDocumentStoreRun(function (LocalDocumentStore $store, string $root) {
        $key = DocumentKey::mint('tenant-a', 'hr/employee-documents');

        expect(fn () => $store->put($key, localDocumentStoreNotAStream()))->toThrow(DocumentStoreFailed::class)
            ->and(scandir($root))->toBe(['.', '..']);
    });
});

it('keeps two tenants apart under one root', function () {
    localDocumentStoreRun(function (LocalDocumentStore $store, string $root) {
        $a = DocumentKey::mint('tenant-a', 'hr/employee-documents');
        $b = DocumentKey::mint('tenant-b', 'hr/employee-documents');

        $store->put($a, localDocumentStoreStream('A'));
        $store->put($b, localDocumentStoreStream('B'));

        expect(stream_get_contents($store->open($a)))->toBe('A')
            ->and(stream_get_contents($store->open($b)))->toBe('B')
            ->and(dirname($root.'/'.$a, 4))->toBe($root)
            ->and(dirname($root.'/'.$b, 4))->toBe($root)
            ->and(dirname($root.'/'.$a, 3))->not->toBe(dirname($root.'/'.$b, 3));
    });
});

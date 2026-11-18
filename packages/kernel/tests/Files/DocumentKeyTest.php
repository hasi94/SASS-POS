<?php

use Flexipos\Kernel\Files\DocumentKey;
use Flexipos\Kernel\Files\DocumentStoreFailed;

/*
 * T-038 plan step 8 (AC-70). Pure: a key is minted by the server and parsed
 * before it may touch a disk; every forged shape is refused here.
 */

const DOCUMENT_KEY_TENANT = '01JABCDEFGHJKMNPQRSTVWXYZ0';
const DOCUMENT_KEY_ULID = '01JABCDEFGHJKMNPQRSTVWXYZ1';

it('mints tenant, area and a fresh upper-case ULID', function () {
    $key = DocumentKey::mint(DOCUMENT_KEY_TENANT, 'hr/employee-documents');
    $parsed = DocumentKey::parse($key);

    expect($key)->toMatch('#\A'.DOCUMENT_KEY_TENANT.'/hr/employee-documents/[0-9A-HJKMNP-TV-Z]{26}\z#')
        ->and($parsed->tenantSegment())->toBe(DOCUMENT_KEY_TENANT)
        ->and($parsed->area())->toBe('hr/employee-documents')
        ->and($parsed->ulid())->toBe(substr($key, -26))
        ->and($parsed->toString())->toBe($key);
});

it('never mints the same key twice', function () {
    $keys = [];

    for ($i = 0; $i < 50; $i++) {
        $keys[DocumentKey::mint('tenant-a', 'hr/employee-documents')] = true;
    }

    expect($keys)->toHaveCount(50);
});

it('accepts a plain tenant segment and a single-segment area', function () {
    expect(DocumentKey::parse('shop_1-A/files/'.DOCUMENT_KEY_ULID)->area())->toBe('files');
});

it('refuses every forged or malformed key', function (string $key) {
    expect(fn () => DocumentKey::parse($key))->toThrow(DocumentStoreFailed::class);
})->with([
    'empty' => [''],
    'a single segment' => ['abc'],
    'two segments' => ['tenant/'.DOCUMENT_KEY_ULID],
    'parent directory in the tenant' => ['../hr/employee-documents/'.DOCUMENT_KEY_ULID],
    'parent directory in the area' => ['tenant/../employee-documents/'.DOCUMENT_KEY_ULID],
    'parent directory before the ULID' => ['tenant/hr/../'.DOCUMENT_KEY_ULID],
    'a dot segment' => ['tenant/./hr/'.DOCUMENT_KEY_ULID],
    'a dot in a name' => ['tenant/hr/employee.documents/'.DOCUMENT_KEY_ULID],
    'a backslash' => ['tenant\\hr/employee-documents/'.DOCUMENT_KEY_ULID],
    'a backslash separator' => ['tenant/hr\\employee-documents/'.DOCUMENT_KEY_ULID],
    'a drive letter' => ['C:/hr/employee-documents/'.DOCUMENT_KEY_ULID],
    'a colon' => ['ten:ant/hr/'.DOCUMENT_KEY_ULID],
    'an absolute path' => ['/tenant/hr/'.DOCUMENT_KEY_ULID],
    'a NUL byte' => ["tenant\0/hr/".DOCUMENT_KEY_ULID],
    'an empty segment' => ['tenant//hr/'.DOCUMENT_KEY_ULID],
    'a trailing slash' => ['tenant/hr/'.DOCUMENT_KEY_ULID.'/'],
    'a trailing newline' => ['tenant/hr/'.DOCUMENT_KEY_ULID."\n"],
    'a tenant that starts with a dash' => ['-tenant/hr/'.DOCUMENT_KEY_ULID],
    'a tenant of 65 characters' => [str_repeat('a', 65).'/hr/'.DOCUMENT_KEY_ULID],
    'a lower-case ULID' => ['tenant/hr/'.strtolower(DOCUMENT_KEY_ULID)],
    'a ULID with an excluded letter' => ['tenant/hr/01JABCDEFGHJKMNPQRSTVWXYZU'],
    'a short ULID' => ['tenant/hr/01JABC'],
    'a name instead of a ULID' => ['tenant/hr/passwd'],
    'a percent-encoded traversal' => ['tenant/hr/..%2f'.DOCUMENT_KEY_ULID],
    'over 255 characters' => ['tenant/'.str_repeat('a/', 130).DOCUMENT_KEY_ULID],
]);

it('refuses to mint from a bad tenant segment or area', function (string $tenant, string $area) {
    expect(fn () => DocumentKey::mint($tenant, $area))->toThrow(DocumentStoreFailed::class);
})->with([
    'an empty tenant' => ['', 'hr/employee-documents'],
    'a traversing tenant' => ['..', 'hr/employee-documents'],
    'a slash in the tenant' => ['a/b', 'hr/employee-documents'],
    'a dot in the tenant' => ['a.b', 'hr/employee-documents'],
    'a traversing area' => ['tenant', 'hr/../x'],
    'an empty area' => ['tenant', ''],
    'a backslash in the area' => ['tenant', 'hr\\x'],
]);

it('does not echo a refused key in its message', function () {
    try {
        DocumentKey::parse('SENTINEL-9921/../etc');
    } catch (DocumentStoreFailed $failed) {
        expect($failed->getMessage())->not->toContain('SENTINEL-9921');

        return;
    }

    throw new RuntimeException('The key should have been refused.');
});

<?php

namespace Flexipos\Hr\Tests\Documents;

use Flexipos\Hr\Documents\DocumentInput;
use Flexipos\Hr\Documents\DocumentType;
use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Tests\Support\HrRefusals;

/*
|--------------------------------------------------------------------------
| Document metadata parsing (T-038 plan step 8; AC-66, AC-74, AC-75, D-33)
|--------------------------------------------------------------------------
*/

it('has exactly the nine document types', function () {
    expect(array_map(static fn (DocumentType $type): string => $type->value, DocumentType::cases()))
        ->toBe(['contract', 'nic_copy', 'passport', 'cv', 'certificate', 'appraisal', 'warning', 'medical', 'other']);
});

it('parses a minimal add and defaults confidentiality to true', function () {
    expect(DocumentInput::add(['type' => 'contract', 'title' => '  Employment contract  ']))->toBe([
        'type' => 'contract',
        'title' => 'Employment contract',
        'issue_date' => null,
        'expiry_date' => null,
        'is_confidential' => true,
        'notes' => null,
    ]);
});

it('accepts an explicit false on a contract (AC-75 control)', function () {
    expect(DocumentInput::add(['type' => 'contract', 'title' => 'T', 'is_confidential' => false])['is_confidential'])->toBeFalse();
});

it('refuses a medical document marked non-confidential and accepts it confidential', function () {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add(['type' => 'medical', 'title' => 'T', 'is_confidential' => false]));

    expect($refused->field)->toBe('is_confidential')
        ->and(DocumentInput::add(['type' => 'medical', 'title' => 'T'])['is_confidential'])->toBeTrue()
        ->and(DocumentInput::add(['type' => 'medical', 'title' => 'T', 'is_confidential' => true])['is_confidential'])->toBeTrue();
});

it('names every invalid field of an add in one refusal', function () {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add([
        'type' => 'resume', 'title' => '   ', 'issue_date' => '2025-02-30', 'expiry_date' => 'soon',
        'is_confidential' => 'yes', 'notes' => str_repeat('n', 1001),
    ]));

    expect($refused->context['fields'])->toBe(['type', 'title', 'issue_date', 'expiry_date', 'is_confidential', 'notes']);
});

it('refuses a missing type and a missing title', function () {
    expect(HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add([]))->context['fields'])->toBe(['type', 'title']);
});

it('accepts a 150 character title and refuses 151, and 1000 notes and refuses 1001', function () {
    $added = DocumentInput::add(['type' => 'cv', 'title' => str_repeat('t', 150), 'notes' => str_repeat('n', 1000)]);

    expect(strlen($added['title']))->toBe(150)->and(strlen((string) $added['notes']))->toBe(1000);

    HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add(['type' => 'cv', 'title' => str_repeat('t', 151)]));
    HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add(['type' => 'cv', 'title' => 'T', 'notes' => str_repeat('n', 1001)]));
});

it('refuses every client-supplied storage field on an add as validation, naming it', function () {
    foreach (['file_path', 'checksum', 'size_bytes', 'mime', 'original_name', 'employee_id', 'created_by', 'is_active'] as $field) {
        $refused = HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add(['type' => 'cv', 'title' => 'T', $field => 'x']), $field);

        expect($refused->context['fields'])->toBe([$field]);
    }
});

it('accepts an expiry equal to the issue date and a past expiry, and refuses an earlier one as date_order', function () {
    expect(DocumentInput::add(['type' => 'cv', 'title' => 'T', 'issue_date' => '2024-05-05', 'expiry_date' => '2024-05-05'])['expiry_date'])->toBe('2024-05-05')
        ->and(DocumentInput::add(['type' => 'cv', 'title' => 'T', 'expiry_date' => '2001-01-01'])['expiry_date'])->toBe('2001-01-01');

    $refused = HrRefusals::expect(HrRefusal::DateOrder, fn () => DocumentInput::add(['type' => 'cv', 'title' => 'T', 'issue_date' => '2024-05-05', 'expiry_date' => '2024-05-04']));

    expect($refused->context)->toBe(['earlier' => 'issue_date', 'later' => 'expiry_date']);
});

it('refuses path-like and NUL text in a title as a control or leaves it as plain text, never a path', function () {
    // A NUL byte is a control character: refused. A slash is plain display text.
    HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add(['type' => 'cv', 'title' => "a\0b"]));

    expect(DocumentInput::add(['type' => 'cv', 'title' => 'Contract 2024/25'])['title'])->toBe('Contract 2024/25');

    foreach (['../x', '..%2fx', '/etc/passwd', 'C:\\x'] as $traversal) {
        HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::add(['type' => $traversal, 'title' => 'T']));
    }
});

// ---- update -----------------------------------------------------------------------

it('refuses every field an update may not change as not_updatable_here', function () {
    foreach (['file_path', 'checksum', 'size_bytes', 'mime', 'employee_id', 'original_name'] as $field) {
        HrRefusals::expect(HrRefusal::NotUpdatableHere, fn () => DocumentInput::update([$field => 'x'], []), $field);
    }

    HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::update(['bogus' => 'x'], []));
});

it('returns the patch and the merged result of an update', function () {
    $current = ['type' => 'contract', 'issue_date' => '2024-01-01', 'expiry_date' => '2026-01-01', 'is_confidential' => true];

    $result = DocumentInput::update(['title' => 'New', 'notes' => '  ', 'expiry_date' => null], $current);

    expect($result['patch'])->toBe(['title' => 'New', 'notes' => null, 'expiry_date' => null])
        ->and($result['merged']['expiry_date'])->toBeNull()
        ->and($result['merged']['issue_date'])->toBe('2024-01-01');
});

it('checks the rules on the merged update, not on the request alone', function () {
    $current = ['type' => 'contract', 'issue_date' => '2024-01-01', 'expiry_date' => '2026-01-01', 'is_confidential' => false];

    // Changing the type to medical strands the stored non-confidential flag.
    HrRefusals::expect(HrRefusal::Validation, fn () => DocumentInput::update(['type' => 'medical'], $current));

    // Moving the issue date past the stored expiry date.
    HrRefusals::expect(HrRefusal::DateOrder, fn () => DocumentInput::update(['issue_date' => '2027-01-01'], $current));

    // The control: the same medical change that also marks it confidential.
    expect(DocumentInput::update(['type' => 'medical', 'is_confidential' => true], $current)['merged']['type'])->toBe('medical');
});

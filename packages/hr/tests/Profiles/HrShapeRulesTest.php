<?php

namespace Flexipos\Hr\Tests\Profiles;

use Flexipos\Hr\Exceptions\HrRefusal;
use Flexipos\Hr\Profiles\ShapeRules;
use Flexipos\Hr\Tests\Support\HrRefusals;

/*
|--------------------------------------------------------------------------
| The email and JSON shapes (T-038 plan step 7; AC-47)
|--------------------------------------------------------------------------
*/

it('accepts a valid email, trims it, and reads a blank one as absent', function () {
    expect(ShapeRules::email(' asha@example.com ', 'work_email'))->toBe('asha@example.com')
        ->and(ShapeRules::email('', 'work_email'))->toBeNull()
        ->and(ShapeRules::email(null, 'work_email'))->toBeNull();
});

it('refuses an invalid email, naming the field', function (mixed $bad) {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ShapeRules::email($bad, 'personal_email'));

    expect($refused->field)->toBe('personal_email');
})->with([
    'no at sign' => ['asha.example.com'],
    'no domain' => ['asha@'],
    'a space inside' => ['as ha@example.com'],
    'not a string' => [5],
]);

it('accepts an email of 191 characters and refuses 192', function () {
    $domain = str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 61);

    expect(strlen('a@'.$domain))->toBe(191)
        ->and(ShapeRules::email('a@'.$domain, 'work_email'))->not->toBeNull();

    HrRefusals::expect(HrRefusal::Validation, fn () => ShapeRules::email('aa@'.$domain, 'work_email'));
});

it('accepts a valid emergency contact and drops an absent relationship', function () {
    expect(ShapeRules::emergencyContact(['phone' => ' 0771234567 ', 'name' => 'Kamal', 'relationship' => 'Brother']))
        ->toBe(['name' => 'Kamal', 'relationship' => 'Brother', 'phone' => '0771234567'])
        ->and(ShapeRules::emergencyContact(['name' => 'Kamal', 'phone' => '1']))->toBe(['name' => 'Kamal', 'phone' => '1'])
        ->and(ShapeRules::emergencyContact(null))->toBeNull();
});

it('refuses a malformed emergency contact', function (mixed $bad) {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ShapeRules::emergencyContact($bad));

    expect($refused->field)->toStartWith('emergency_contact');
})->with([
    'a string' => ['Kamal'],
    'a list' => [['Kamal', '0771']],
    'an empty object' => [[]],
    'a missing name' => [['phone' => '1']],
    'a missing phone' => [['name' => 'K']],
    'a blank name' => [['name' => '  ', 'phone' => '1']],
    'an unknown key' => [['name' => 'K', 'phone' => '1', 'email' => 'x@y.com']],
    'a nested value' => [['name' => ['K'], 'phone' => '1']],
    'a non-string leaf' => [['name' => 'K', 'phone' => 771234]],
    'a boolean leaf' => [['name' => 'K', 'phone' => '1', 'relationship' => true]],
]);

it('accepts 100 characters in an emergency contact string and refuses 101', function () {
    expect(ShapeRules::emergencyContact(['name' => str_repeat('n', 100), 'phone' => '1']))->not->toBeNull();

    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ShapeRules::emergencyContact(['name' => str_repeat('n', 101), 'phone' => '1']));

    expect($refused->field)->toBe('emergency_contact.name');
});

it('accepts a valid address in canonical key order', function () {
    expect(ShapeRules::address(['city' => 'Colombo', 'line1' => '12 Main St', 'country' => 'LK']))
        ->toBe(['line1' => '12 Main St', 'city' => 'Colombo', 'country' => 'LK'])
        ->and(ShapeRules::address(null))->toBeNull();
});

it('refuses a malformed address', function (mixed $bad) {
    $refused = HrRefusals::expect(HrRefusal::Validation, fn () => ShapeRules::address($bad));

    expect($refused->field)->toStartWith('address');
})->with([
    'a string' => ['12 Main St'],
    'a list' => [['12 Main St']],
    'an empty object' => [[]],
    'only blanks' => [['line1' => '  ']],
    'an unknown key' => [['line1' => 'x', 'street' => 'y']],
    'a nested value' => [['line1' => ['x']]],
    'a non-string leaf' => [['postal_code' => 10100]],
]);

it('accepts 150 characters in an address string and refuses 151', function () {
    expect(ShapeRules::address(['line1' => str_repeat('a', 150)]))->not->toBeNull();

    HrRefusals::expect(HrRefusal::Validation, fn () => ShapeRules::address(['line1' => str_repeat('a', 151)]));
});

it('never echoes a refused shape value', function () {
    $sentinel = 'SENTINEL-5530-RR';

    foreach ([
        fn () => ShapeRules::address(['line1' => $sentinel, 'bogus' => $sentinel]),
        fn () => ShapeRules::emergencyContact(['name' => $sentinel, 'phone' => [$sentinel]]),
        fn () => ShapeRules::email($sentinel, 'personal_email'),
    ] as $attempt) {
        expect(HrRefusals::capture($attempt)->getMessage())->not->toContain($sentinel);
    }
});

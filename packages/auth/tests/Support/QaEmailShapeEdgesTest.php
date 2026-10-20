<?php

use Flexipos\Auth\Support\EmailAddress;

/*
 * T-009 QA, D-38 / AC-23: the edges of the shape rule that the dev matrix does
 * not pin. Found by mutation: deleting the "domain does not end with a dot"
 * clause left every existing test green. Pure value object - no database.
 */

it('maps each shape-edge value to exactly the marker', function (string $value) {
    expect(EmailAddress::fromSubmitted($value)->forHistory())->toBe('(not an email)');
})->with([
    'trailing dot on the domain' => 'user@example.',
    'domain that is only a dot' => 'user@.',
    'leading dot on the domain' => 'user@.example.com',
    'tab inside' => "us\ter@example.com",
    'no dot after the at' => 'user@localhost',
    'two ats' => 'a@b@c.com',
]);

it('keeps address-shaped values byte for byte, trimmed', function (string $value, string $stored) {
    expect(EmailAddress::fromSubmitted($value)->forHistory())->toBe($stored);
})->with([
    'typo domain' => ['user@exmaple.con', 'user@exmaple.con'],
    'padded' => ['  user@example.com  ', 'user@example.com'],
    'case kept' => ['Owner@Example.COM', 'Owner@Example.COM'],
    'dot inside, not at the edge' => ['u@a.b', 'u@a.b'],
]);

<?php

use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Auth\Support\IpAddress;

/*
|--------------------------------------------------------------------------
| T-010 QA - the two homes of inet_pton packing agree (plan Ruling 11, FP-306)
|--------------------------------------------------------------------------
|
| `Flexipos\Auth\Support\IpAddress` (master: `login_history.ip`,
| `refresh_tokens.ip`, `users`) and `Flexipos\Access\Support\AccessLogWriter::
| packIp()` (tenant: `access_log.ip`, `audit_logs.ip`) each wrap the
| standard library's `inet_pton`, because a value object at a package
| boundary cannot be shared without widening the edge AC-11 exists to keep.
| The two cannot be merged yet (the three-use rule is met only when
| AuditWriter packs, which it now does through the access copy), so the
| agreement is pinned here, in the only tree that is granted both layers.
|
| The risk is not that `inet_pton` changes; it is NORMALISATION. If one home
| folded `::ffff:203.0.113.7` to its IPv4 form and the other did not, the
| master and a tenant database would disagree about the identity of one
| client, silently and permanently. So the inputs below are chosen for the
| places two wrappers could differ: the mapped form, case, zero-padding, the
| unspecified address, empty and null, and strings that are not addresses.
|
| **The comparison is of the packed BYTES (hex) and of the failure class**,
| not of anything printed, because the bytes are what is stored.
|
| **Negative control (QA ran it, recorded in the report):** making
| `AccessLogWriter::packIp()` fold the mapped form to IPv4 turns the
| agreement case red on the mapped row.
|
*/

/**
 * What one home does with an input: the packed bytes in hex, null, or the
 * class of the exception.
 */
function qaInetOutcome(callable $pack, mixed $input): string
{
    try {
        $packed = $pack($input);
    } catch (Throwable $thrown) {
        return 'throws '.$thrown::class;
    }

    return $packed === null ? 'null' : 'bytes '.bin2hex($packed);
}

it('packs every input to the same bytes, or refuses it with the same class, in both homes', function () {
    $inputs = [
        'v4' => '203.0.113.7',
        'v4 zeros' => '0.0.0.0',
        'v6' => '2001:db8::1',
        'v6 upper case' => '2001:DB8::1',
        'v6 zero padded' => '2001:0db8:0000:0000:0000:0000:0000:0001',
        'v6 loopback' => '::1',
        'v6 unspecified' => '::',
        'v4 mapped' => '::ffff:203.0.113.7',
        'v4 mapped upper' => '::FFFF:203.0.113.7',
        'v4 mapped hex' => '::ffff:cb00:7107',
        'empty' => '',
        'null' => null,
        'not an address' => 'not-an-ip',
        'cidr' => '203.0.113.7/24',
        'short v4' => '1.2.3',
        'out of range v4' => '203.0.113.256',
        'leading space' => ' 203.0.113.7',
        'zone id' => 'fe80::1%eth0',
    ];

    $rows = [];

    foreach ($inputs as $label => $input) {
        $auth = qaInetOutcome(static fn ($v): ?string => IpAddress::pack($v), $input);
        $access = qaInetOutcome(static fn ($v): ?string => AccessLogWriter::packIp($v), $input);

        $rows[] = sprintf('%-18s auth=%-34s access=%s', $label, $auth, $access);

        expect($access)->toBe($auth, "[{$label}] the two homes disagree about ".var_export($input, true));
    }

    fwrite(STDOUT, PHP_EOL.'inet_pton agreement ('.count($rows).' inputs):'.PHP_EOL.implode(PHP_EOL, $rows).PHP_EOL);

    // Liveness of the comparison: the inputs really did split into packed,
    // empty and refused outcomes, so "both agree" is not "both always throw".
    $outcomes = array_map(static fn (string $row): string => explode(' ', trim(explode('auth=', $row)[1]))[0], $rows);

    expect(array_unique($outcomes))->toContain('bytes')
        ->and(array_unique($outcomes))->toContain('null')
        ->and(array_unique($outcomes))->toContain('throws');

    // The mapped form stays 16 bytes in both: the identity of one client is
    // one value, and it is not folded to a 4-byte address.
    expect(qaInetOutcome(static fn ($v): ?string => IpAddress::pack($v), '::ffff:203.0.113.7'))
        ->toBe('bytes 00000000000000000000ffffcb007107');
});

it('unpacks every stored width to the same printed address, or refuses it alike, in both homes', function () {
    $stored = [
        'v4' => inet_pton('203.0.113.7'),
        'v6' => inet_pton('2001:db8::1'),
        'v4 mapped' => inet_pton('::ffff:203.0.113.7'),
        'loopback' => inet_pton('::1'),
        'empty' => '',
        'null' => null,
        '3 bytes' => 'abc',
        '5 bytes' => 'abcde',
        '15 bytes' => str_repeat('a', 15),
        '17 bytes' => str_repeat('a', 17),
    ];

    foreach ($stored as $label => $packed) {
        $auth = qaInetOutcome(static fn ($v): ?string => IpAddress::unpack($v), $packed);
        $access = qaInetOutcome(static fn ($v): ?string => AccessLogWriter::unpackIp($v), $packed);

        expect($access)->toBe($auth, "[{$label}] the two homes unpack differently");
    }

    // Round trip in each home, through the other's output.
    foreach (['203.0.113.7', '2001:db8::1', '::ffff:203.0.113.7'] as $address) {
        expect(AccessLogWriter::unpackIp(IpAddress::pack($address)))->toBe(IpAddress::unpack(AccessLogWriter::packIp($address)));
    }
});

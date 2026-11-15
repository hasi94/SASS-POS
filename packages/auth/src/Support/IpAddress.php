<?php

declare(strict_types=1);

namespace Flexipos\Auth\Support;

use InvalidArgumentException;

/**
 * The one place a caller's address becomes bytes, and bytes become an address.
 *
 * **One column holds both families** (AC-27). `login_history.ip`,
 * `security_events.ip`, `refresh_tokens.ip` and `users.last_login_ip` are all
 * `VARBINARY(16)`, and what goes in them is `inet_pton`'s packed form - 4
 * bytes for IPv4, 16 for IPv6 - rather than the printed text. Packed is what
 * makes one column enough for both: the text forms need 45 characters and sort
 * meaninglessly, while the packed forms are fixed width and comparable.
 *
 * **Never assert on the stored bytes through SQL on the stand-in, and this is
 * measured, not cautionary.** Step 2(b)'s spike found SQLite's `length()` and
 * `typeof()` report `1` and `text` for a packed address, because both truncate
 * at the first NUL byte and `203.0.113.7` packs to a string whose second byte
 * is one. The value itself round-trips intact through PDO - it is only
 * SQLite's own scalar functions that mis-describe it. So every proof of AC-27
 * reads the column into PHP and compares after {@see self::unpack()}, and no
 * test asserts `length(ip)` in SQL. The column is `VARBINARY(16)` on the
 * engine only, which is why AC-27 is graded *corroborating* and not
 * independently proven: the stand-in stores a BLOB and the width is the
 * engine's to enforce.
 *
 * **A malformed address is a defect here, not a user input.** The address
 * comes from the request's own socket, not from a submitted field, so a value
 * `inet_pton` refuses means the caller handed this class something that is not
 * an address at all. It throws rather than storing null, because a login
 * history quietly missing the addresses of one proxy's traffic is the kind of
 * hole that is found years later. Absence is a third state and it is spelled
 * explicitly: null in, null out.
 */
final class IpAddress
{
    /**
     * The two packed widths, which are also the two families.
     */
    public const WIDTH_V4 = 4;

    public const WIDTH_V6 = 16;

    /**
     * A printed address to the bytes the column holds.
     *
     * @throws InvalidArgumentException when the value is not an address
     */
    public static function pack(?string $address): ?string
    {
        if ($address === null || $address === '') {
            return null;
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            throw new InvalidArgumentException(sprintf('[%s] is not an IP address', $address));
        }

        return $packed;
    }

    /**
     * The bytes the column holds back to a printed address.
     *
     * The stand-in hands back a plain PHP string; so does the engine for a
     * `VARBINARY`. Anything that is not 4 or 16 bytes never came from
     * {@see self::pack()} and is refused rather than guessed at.
     *
     * @throws InvalidArgumentException when the value is not a packed address
     */
    public static function unpack(?string $packed): ?string
    {
        if ($packed === null || $packed === '') {
            return null;
        }

        if (! in_array(strlen($packed), [self::WIDTH_V4, self::WIDTH_V6], true)) {
            throw new InvalidArgumentException(
                sprintf('a packed address is %d or %d bytes, not %d', self::WIDTH_V4, self::WIDTH_V6, strlen($packed))
            );
        }

        $address = @inet_ntop($packed);

        if ($address === false) {
            throw new InvalidArgumentException('the value is not a packed IP address');
        }

        return $address;
    }
}

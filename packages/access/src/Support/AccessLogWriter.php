<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * The only writer of `access_log` in this package (D-21, AC-69).
 *
 * **Three types and no fourth, refused rather than documented.** D-21 closes
 * the set at `pin_login`, `pin_failed` and `permission_denied`;
 * `terminal_login` and `override_used` belong to tasks that do not exist, and
 * the column is wide enough for them. {@see self::write()} refuses anything
 * outside {@see self::TYPES} before it reaches the table, so a later caller
 * inventing a type gets an exception rather than a row nobody's report knows
 * how to read. The three named methods above it exist so that no ordinary
 * caller passes a type at all.
 *
 * **One writer, because three facts would otherwise drift.** `created_at`
 * comes from the one clock T-009 bound rather than from `now()` in several
 * places; `detail` is encoded once, so "valid JSON" (AC-69) is a property of
 * this method and not of each caller; and the address is packed once, so the
 * column holds `inet_pton` bytes and never a printed string.
 *
 * **Why the packing is here and not `Flexipos\Auth\Support\IpAddress`.** That
 * class is the same three lines and is the right home - but it lives in
 * `packages/auth`, and `Package_Access` is allowed to depend on the kernel
 * and the published contracts only. Reaching across would be a boundary
 * violation the deptrac run reports, and widening the ruleset to let an
 * authorization package depend on authentication would buy six lines at the
 * cost of the edge AC-11 exists to protect. The honest state is therefore two
 * small copies of one fact, declared here rather than left to be discovered:
 * the fix is to promote the helper into `packages/kernel`, which is a change
 * to a shared package's surface and belongs to whoever owns that decision,
 * not to a PIN slice. **Recommended row:** *"`inet_pton` packing has two
 * homes, `Flexipos\Auth\Support\IpAddress` and
 * `Flexipos\Access\Support\AccessLogWriter`, because no package both may
 * depend on holds it. Promote one into the kernel."*
 *
 * **No PIN, ever** (AC-48). `detail` holds a closed reason and nothing a
 * caller submitted; the methods below take no PIN and there is no parameter
 * through which one could arrive. A near-miss PIN written to a log is close
 * enough to the real one to be a disclosure, which is why AC-50 names it.
 */
final class AccessLogWriter
{
    /**
     * A PIN verification that succeeded (AC-49).
     */
    public const TYPE_PIN_LOGIN = 'pin_login';

    /**
     * A PIN verification that was refused, for any of AC-50's five causes.
     */
    public const TYPE_PIN_FAILED = 'pin_failed';

    /**
     * A guarded route that refused a request (AC-64).
     */
    public const TYPE_PERMISSION_DENIED = 'permission_denied';

    /**
     * The closed set D-21 allows this task to write.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_PIN_LOGIN,
        self::TYPE_PIN_FAILED,
        self::TYPE_PERMISSION_DENIED,
    ];

    public function __construct(private readonly RunnerClock $clock) {}

    /**
     * One successful PIN verification.
     */
    public function pinLogin(Connection $connection, string $employeeId, ?string $ip): void
    {
        $this->write($connection, self::TYPE_PIN_LOGIN, $employeeId, null, null, [], $ip);
    }

    /**
     * One refused PIN verification, with its cause.
     *
     * `$employeeId` is null exactly when the submitted code matched nobody:
     * there is no row to name, and inventing one would be worse than the
     * absence (AC-50).
     */
    public function pinFailed(Connection $connection, ?string $employeeId, string $reason, ?string $ip): void
    {
        $this->write($connection, self::TYPE_PIN_FAILED, $employeeId, null, null, ['reason' => $reason], $ip);
    }

    /**
     * One guarded route refusing a request (AC-64).
     *
     * The finer cause - `not_granted`, `explicit_deny`, `out_of_scope` - is
     * written here and nowhere else; the caller is told `not_permitted` and
     * no more (D-26).
     */
    public function permissionDenied(
        Connection $connection,
        ?string $employeeId,
        string $permissionKey,
        ?string $branchId,
        string $reason,
        ?string $ip,
    ): void {
        $this->write(
            $connection,
            self::TYPE_PERMISSION_DENIED,
            $employeeId,
            $permissionKey,
            $branchId,
            ['reason' => $reason],
            $ip,
        );
    }

    /**
     * Write one row, refusing a type outside the closed set.
     *
     * Public so that the refusal is reachable by a test: a guard no caller
     * can invoke is a guard no measurement can show to be live, and the
     * three methods above are the only production callers.
     *
     * @param  array<string, mixed>  $detail
     *
     * @throws InvalidArgumentException when the type is not one of D-21's three
     */
    public function write(
        Connection $connection,
        string $type,
        ?string $employeeId,
        ?string $permissionKey,
        ?string $branchId,
        array $detail,
        ?string $ip,
    ): void {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "[{$type}] is not one of the access log types this task writes (".implode(', ', self::TYPES).').'
            );
        }

        $connection->table('access_log')->insert([
            'employee_id' => $employeeId,
            'terminal_id' => null,
            'type' => $type,
            'permission_key' => $permissionKey,
            'branch_id' => $branchId,
            'detail' => $detail === [] ? null : (string) json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'ip' => self::packIp($ip),
            'created_at' => $this->clock->timestamp(),
        ]);
    }

    /**
     * A printed address as the bytes `access_log.ip` holds, or null.
     *
     * A value `inet_pton` refuses is a defect at the call site rather than a
     * caller's input - the address comes from the request's own socket - so
     * it throws instead of storing null and losing the row's provenance.
     *
     * @throws InvalidArgumentException when the value is not an address
     */
    public static function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            throw new InvalidArgumentException("[{$ip}] is not an IP address.");
        }

        return $packed;
    }

    /**
     * The stored bytes back as a printed address, or null.
     *
     * Anything that is not 4 or 16 bytes never came from
     * {@see self::packIp()}. Never assert on the stored width through SQL on
     * the stand-in: SQLite's `length()` truncates at the first NUL byte, so
     * a packed IPv4 reports 1.
     */
    public static function unpackIp(?string $packed): ?string
    {
        if ($packed === null || $packed === '') {
            return null;
        }

        $address = @inet_ntop($packed);

        if ($address === false) {
            throw new InvalidArgumentException('That value is not a packed IP address.');
        }

        return $address;
    }
}

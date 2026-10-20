<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\IssueTokenPair;

use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Access\EmployeeClaims;
use InvalidArgumentException;

/**
 * Issue one access token and one refresh token for a principal.
 *
 * The command three slices construct - `Login`, `SelectTenant` and
 * `RefreshToken` - which is why the claim shapes and the refresh row live
 * behind one handler instead of in each of them.
 *
 * `familyId` is the rotation chain. **Absent means "start a new family"**,
 * which is what a login and a tenant selection do (AC-30: selecting tenant A
 * makes F1, selecting B makes F2, and the platform family F0 is neither used
 * nor revoked); present means "this is the next token in that family", which
 * is what a refresh does.
 *
 * `ip` is the **packed** form - `inet_pton`'s 4 or 16 bytes - because the
 * column is binary and holds both families in one column (AC-27). The one
 * place an address becomes packed is `Support\IpAddress`, which belongs to
 * step 7; this handler stores what it is given and converts nothing.
 */
final class IssueTokenPair
{
    public function __construct(
        public readonly string $tokenType,
        public readonly string $userId,
        public readonly ?string $tenantId = null,
        /**
         * The five employee-derived claims, or none at all (AC-58).
         *
         * **A value object and deliberately not an employee id.** T-009
         * passed `?string $employeeId` and T-010 would have had to pass
         * five more parameters beside it, at which point "all or none"
         * becomes a rule each caller remembers. Here the only way to put
         * an `employee` claim into a token is to hand over a whole
         * {@see EmployeeClaims}, which cannot be built half-formed - so a
         * token with `employee` and no `perm_version` is unreachable from
         * this command rather than merely not minted.
         */
        public readonly ?EmployeeClaims $employeeClaims = null,
        public readonly ?string $familyId = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {
        if (! in_array($tokenType, TokenClaims::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('unknown token type %s', $tokenType));
        }

        if ($userId === '') {
            throw new InvalidArgumentException('a token pair must name a principal');
        }
    }
}

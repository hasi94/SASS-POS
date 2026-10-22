<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Contracts\Auth\TerminalSessions;
use Flexipos\Contracts\Organization\ActivationCodeFormat;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Organization\Exceptions\ActivationCodeUnavailable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Making one activation code: the one place creating a terminal and
 * re-issuing a code both come to (T-014 AC-26, AC-28, D-17).
 *
 * **It returns the plaintext once and the hash and expiry to store**, and the
 * caller must keep the plaintext out of every write: the row gets the hash,
 * the audit row gets neither, the response gets the code. The expiry is the
 * clock's now plus the configured lifetime, so a test moves it by moving the
 * clock.
 *
 * **The tenant part is `tenants.code` verbatim, and a code that does not fit
 * the format stops issuing** (Ruling 7): the master's table is auth's, so the
 * code is asked of `TerminalSessions`, then checked against the published
 * pattern. A non-conforming one is logged at error level - naming the tenant
 * id and never a code - and thrown as {@see ActivationCodeUnavailable}
 * **before the caller has written anything**: callers ask for the code
 * before their first insert. Tenant codes must be stored upper-case; FP-206
 * must store them so.
 */
final class ActivationIssuer
{
    public function __construct(
        private readonly TerminalSessions $sessions,
        private readonly Tenancy $tenancy,
        private readonly RunnerClock $clock,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{code: string, hash: string, expires_at: string}
     *
     * @throws ActivationCodeUnavailable
     */
    public function issue(): array
    {
        $tenantId = $this->tenancy->current()->tenantId();

        try {
            $tenantPart = $this->sessions->tenantPartFor($tenantId);
        } catch (Throwable $thrown) {
            $this->logger->error('An activation code could not be issued: the tenant part is unavailable.', [
                'tenant_id' => $tenantId,
                'exception' => $thrown::class,
            ]);

            throw new ActivationCodeUnavailable("The tenant part for tenant [{$tenantId}] is unavailable.", 0, $thrown);
        }

        if (preg_match(ActivationCodeFormat::TENANT_PART_PATTERN, $tenantPart) !== 1) {
            $this->logger->error('An activation code could not be issued: the stored tenant code does not fit the format.', [
                'tenant_id' => $tenantId,
            ]);

            throw new ActivationCodeUnavailable("The stored code of tenant [{$tenantId}] does not fit the activation-code format.");
        }

        $secret = ActivationCode::generateSecret();
        $hours = max(1, (int) $this->config->get('organization.activation_code_lifetime_hours', 24));

        return [
            'code' => ActivationCode::format($tenantPart, $secret),
            'hash' => ActivationCode::hashOf($secret),
            'expires_at' => $this->clock->now()->addHours($hours)->format(RunnerClock::PRECISION),
        ];
    }
}

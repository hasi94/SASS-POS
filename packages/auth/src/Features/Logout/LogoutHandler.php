<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\Logout;

use Flexipos\Auth\Tokens\RefreshTokenValue;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * End the session a refresh token belongs to (AC-49).
 *
 * **It never refuses, and that is the design.** Logout answers the same way
 * whatever it was given: a live token, one that expired last week, one
 * already revoked, a string that was never a token, an empty body. Any
 * difference - a 404, a different message, even a different latency class -
 * would turn this endpoint into an oracle for whether a value is a real
 * token, and it is the one endpoint a client is encouraged to call with
 * whatever it happens to be holding.
 *
 * **It is therefore idempotent by construction rather than by a check.**
 * Revoking is a single conditional `UPDATE` over the family; running it
 * again matches no unrevoked rows and changes nothing. There is no "is it
 * already revoked" branch to get wrong, and no way to extend or move a
 * revocation that has already happened.
 *
 * **The whole family goes, not the presented row.** A refresh chain is one
 * session; signing out of a session that left three rotations behind it
 * would otherwise leave the previous links usable.
 *
 * **No signing key is resolved here**, unlike the three slices that mint:
 * logout issues nothing, so there is nothing for a key to sign and no reason
 * a deployment that cannot sign should also be unable to sign people out. It
 * is the one authentication operation that still works with every key
 * retired.
 *
 * **Nothing is recorded.** No `login_history` row - no credential was judged
 * (D-36) - and no `security_events` row: a person signing out is the system
 * working, not an event. AC-49 asks for a revocation and an unvarying
 * answer, and both are observable in `refresh_tokens`.
 */
final class LogoutHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(Logout $command): LogoutResult
    {
        $familyId = $this->familyOf($command->token);

        if ($familyId !== null) {
            $now = $this->clock->timestamp();

            $this->master()
                ->table('refresh_tokens')
                ->where('family_id', $familyId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
        }

        return new LogoutResult;
    }

    /**
     * The family the presented value belongs to, or null when it belongs to
     * none.
     *
     * The lookup is on the hash, so the plaintext is compared against
     * nothing stored (AC-32), and an unreadable value simply finds no row -
     * one path for "not a token" and "not one of ours", because the answer
     * is the same either way.
     */
    private function familyOf(mixed $token): ?string
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $family = $this->master()
            ->table('refresh_tokens')
            ->where('token_hash', RefreshTokenValue::fromPlaintext($token)->hash())
            ->value('family_id');

        return $family === null ? null : (string) $family;
    }

    private function master(): Connection
    {
        return $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));
    }
}

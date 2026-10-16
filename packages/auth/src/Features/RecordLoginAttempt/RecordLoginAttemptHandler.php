<?php

declare(strict_types=1);

namespace Flexipos\Auth\Features\RecordLoginAttempt;

use Flexipos\Auth\Support\IpAddress;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The one writer of `login_history` and of `security_events`.
 *
 * **It opens no transaction, and that is the whole of AC-24.** A failed login
 * ends in an error response, and the history row has to survive it. There are
 * two ways to get that: write the row and then undo nothing, or write it
 * inside something and remember to commit before every exit. The second is a
 * cleanup path, and a cleanup path is a thing somebody forgets on the sixth
 * refusal branch. So this handler inserts, outside any transaction of its own,
 * at the moment the attempt is decided and before any response is rendered -
 * and "the row is persisted in spite of the error" becomes a property of where
 * the write sits rather than of a rollback nobody wrote. Its test proves it
 * the way the plan asks: a decorator that throws after the call still leaves
 * the row, and the transaction level observed *during* the insert is zero.
 *
 * **Why these two tables have one writer.** `login_history.failure_reason` is
 * the only place the cause of a refusal survives D-12's byte-identical 401, so
 * every later refusal test is asserted against it. An instrument with two
 * authors is an instrument that disagrees with itself; `Login`, `SelectTenant`
 * and `RefreshToken` all hand this slice a command and none of them touches
 * either table.
 *
 * **Nothing here writes a credential.** No password, no access token and no
 * refresh token is a parameter of the command, so there is no value this
 * handler could put in a column even by accident (AC-26). The one free-form
 * column is `security_events.detail`, and the only detail T-009 writes is a
 * lockout's own figures.
 *
 * Time is the kernel's injected `RunnerClock` - one `now()` in the process and
 * one `DATETIME(3)` rendering, so a boundary criterion can drive it.
 */
final class RecordLoginAttemptHandler
{
    /**
     * The only principal T-009 authenticates.
     *
     * FP-209's terminal tokens add the second value, which is why the column
     * exists and why this is a named constant rather than a literal in an
     * insert. Its agreement with the refresh row's `principal_type` is pinned
     * by a test rather than by one slice importing the other.
     */
    public const PRINCIPAL_TYPE = 'user';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(RecordLoginAttempt $command): void
    {
        $now = $this->clock->timestamp();
        $ip = IpAddress::pack($command->ip);

        // The one branch: a command that records no attempt writes no
        // `login_history` row. A replayed refresh token is an event and not
        // a credential judgement (D-36), and the table means the second
        // thing.
        if ($command->recordsAttempt()) {
            $this->insertAttempt($command, $now, $ip);
        }

        if ($command->securityEventType === null) {
            return;
        }

        $this->master()->table('security_events')->insert([
            'type' => $command->securityEventType,
            'user_id' => $command->userId,
            'tenant_id' => $command->tenantId,
            // The actor of a lockout is the account itself, not an operator.
            // An administrator-driven event fills this in and leaves the two
            // above as the subject.
            'actor_id' => null,
            'detail' => $command->securityEventDetail === null
                ? null
                : json_encode($command->securityEventDetail, JSON_THROW_ON_ERROR),
            'ip' => $ip,
            'created_at' => $now,
        ]);
    }

    private function insertAttempt(RecordLoginAttempt $command, string $now, ?string $ip): void
    {
        $this->master()->table('login_history')->insert([
            'user_id' => $command->userId,
            'tenant_id' => $command->tenantId,
            'email' => $command->email,
            'principal_type' => self::PRINCIPAL_TYPE,
            'success' => $command->success,
            'failure_reason' => $command->failureReason,
            'ip' => $ip,
            'user_agent' => $command->userAgent,
            'device_id' => $command->deviceId,
            'created_at' => $now,
        ]);
    }

    private function master(): Connection
    {
        return $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'));
    }
}

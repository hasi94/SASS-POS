<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Api\Idempotency;

use Carbon\CarbonImmutable;
use Flexipos\Kernel\Api\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The tenant-database half of idempotency (T-021 Ruling 6, D-7, D-8).
 *
 * **The in-flight claim is a null `response_code`,** not a second column: the
 * documented table (`docs/database.md` §6.21) does not name one, and
 * nullable `response_code` carries the same state. The claim is inserted
 * outside the handler's own transaction, because a concurrent request must
 * be able to see it (stated, not hidden - plan risk 3).
 */
final class IdempotencyStore
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Claim `$key` for a request whose fingerprint is `$hash`. See the class
     * docblock and T-021 Ruling 6 for the full decision table.
     */
    public function claim(string $key, string $hash): IdempotencyOutcome
    {
        $connection = $this->tenant->get();

        try {
            $connection->table('idempotency_keys')->insert([
                'key' => $key,
                'request_hash' => $hash,
                'response_code' => null,
                'response_body' => null,
                'created_at' => $this->clock->timestamp(),
            ]);

            return IdempotencyOutcome::claimed();
        } catch (UniqueConstraintViolationException) {
            return $this->resolveExisting($connection, $key, $hash);
        }
    }

    /**
     * Store a 2xx handler result against an in-flight claim (AC-18).
     */
    public function complete(string $key, string $hash, int $status, mixed $body): void
    {
        $this->tenant->get()
            ->table('idempotency_keys')
            ->where('key', $key)
            ->where('request_hash', $hash)
            ->whereNull('response_code')
            ->update([
                'response_code' => $status,
                'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
            ]);
    }

    /**
     * Release an in-flight claim after a non-2xx outcome or a throw, so the
     * same key can be retried (AC-21, D-6).
     */
    public function release(string $key, string $hash): void
    {
        $this->tenant->get()
            ->table('idempotency_keys')
            ->where('key', $key)
            ->where('request_hash', $hash)
            ->whereNull('response_code')
            ->delete();
    }

    private function resolveExisting(Connection $connection, string $key, string $hash): IdempotencyOutcome
    {
        $row = $connection->table('idempotency_keys')->where('key', $key)->first();

        if ($row === null) {
            // Deleted between the failed insert and this read (a concurrent
            // release or an expired-row cleanup); the key is free again.
            return $this->claim($key, $hash);
        }

        if ($this->isExpired($row->created_at)) {
            $connection->table('idempotency_keys')
                ->where('key', $key)
                ->where('created_at', $row->created_at)
                ->delete();

            return $this->claim($key, $hash);
        }

        if (! hash_equals($row->request_hash, $hash)) {
            return IdempotencyOutcome::reused();
        }

        if ($row->response_code === null) {
            if (! $this->isStaleClaim($row->created_at)) {
                return IdempotencyOutcome::inProgress();
            }

            // The holder died without completing or releasing; take the
            // claim over rather than leave the key stuck for the rest of
            // the retention window (AC-54, D-22).
            $connection->table('idempotency_keys')
                ->where('key', $key)
                ->where('request_hash', $hash)
                ->whereNull('response_code')
                ->update(['created_at' => $this->clock->timestamp()]);

            return IdempotencyOutcome::claimed();
        }

        return IdempotencyOutcome::replay(
            (int) $row->response_code,
            json_decode((string) $row->response_body, true)
        );
    }

    private function isExpired(string $createdAt): bool
    {
        $retentionHours = (int) $this->config->get('api.idempotency.retention_hours', 24);

        return $this->clock->now()->greaterThan(
            CarbonImmutable::parse($createdAt)->addHours($retentionHours)
        );
    }

    private function isStaleClaim(string $createdAt): bool
    {
        $timeoutSeconds = (int) $this->config->get('api.idempotency.claim_timeout_seconds', 120);

        return $this->clock->now()->greaterThan(
            CarbonImmutable::parse($createdAt)->addSeconds($timeoutSeconds)
        );
    }
}

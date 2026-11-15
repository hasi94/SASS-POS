<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Closure;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Provisioning\TenantDatabaseNames;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningRuns;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\TenantCode;
use Flexipos\Platform\Support\MasterConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `reserve_identity`, the first of the eleven `create` steps (T-026 AC-6,
 * AC-7, AC-10 to AC-12, AC-13, AC-37).
 *
 * **AC-10/AC-12.** Inserts one `tenants` row, `status = 'provisioning'`,
 * `plan_id` null - starting a subscription is `activate`'s job, not this
 * one's. `sequence` is read as `MAX(sequence) + 1` and the three mechanical
 * names ({@see TenantDatabaseNames}) are derived from it once; a retried
 * attempt of this same step reuses exactly those names rather than
 * recomputing them from whatever `sequence` happens to read next time.
 *
 * **AC-37.** The only value this file ever writes to `tenants.code` is the
 * return of {@see TenantCode::normalise()} - proven statically by
 * `ReserveIdentityCodeWriteTest`, not only by a runtime assertion.
 *
 * **Intent, then effect (Ruling 2).** The chosen sequence/tenant id/names are
 * committed through `$journal->write()` *before* the insert is attempted, so
 * a crash between the two leaves a resumed attempt everything it needs to
 * retry the exact same insert rather than mint a second identity (AC-5,
 * AC-12). On resume, `$journal->read()` is consulted first: if the `tenants`
 * row already exists, this step is a no-op; if it does not, the insert is
 * retried unchanged.
 *
 * **AC-6/AC-7.** Two distinct refusal shapes share one mechanism:
 *
 * - **AC-6** (the common case): before ever touching the journal, an
 *   up-front read refuses a `--code` that already names another tenant,
 *   naming the run that reserved it, so the operator can `--resume` that run
 *   instead of starting a parallel one.
 * - **AC-7** (the race the up-front read cannot close): the insert itself
 *   can still collide, either on `code` (another attempt won the race) or on
 *   `sequence` (two attempts computed the same `MAX + 1`). A unique-violation
 *   is never trusted at face value - it is re-read to tell the two apart: a
 *   `code` collision is refused by naming the winner, exactly like the
 *   up-front check; a `sequence` collision is not an error at all, only a
 *   reason to recompute `sequence` and retry, bounded by
 *   {@see self::MAX_SEQUENCE_ATTEMPTS} so a step that is wrong does not spin
 *   forever. {@see self::$raceHook} is the test-only seam that lets a test
 *   insert the conflicting row in the exact window a real two-process race
 *   would - between this attempt's read and its insert - without this class
 *   knowing anything about tests.
 */
final class ReserveIdentity implements ProvisioningStep
{
    private const MAX_SEQUENCE_ATTEMPTS = 3;

    /**
     * Test-only. Invoked once, immediately before the insert this attempt is
     * about to make - the window AC-7's race occupies - then cleared. Never
     * set outside a test.
     */
    public static ?Closure $raceHook = null;

    public function __construct(
        private readonly MasterConnection $master,
        private readonly ProvisioningRuns $runs,
        private readonly RunnerClock $clock,
    ) {}

    public function run(RunContext $context, StepJournal $journal): array
    {
        $intent = $journal->read();

        if (isset($intent['tenant_id'])) {
            $existing = $this->master->get()->table('tenants')->where('id', $intent['tenant_id'])->first();

            if ($existing !== null) {
                return $intent + ['code' => TenantCode::normalise($context->input['code'])];
            }

            return $this->attemptInsert($context, $journal, $intent);
        }

        $this->refuseIfCodeTaken(TenantCode::normalise($context->input['code']), null);

        $intent = $this->chooseIdentity();
        $journal->write($intent);

        return $this->attemptInsert($context, $journal, $intent);
    }

    public function compensate(RunContext $context): ?string
    {
        // Abandon (Ruling 10, step 16) archives tenants.status for the whole
        // run from Features\AbandonProvisioning directly; there is nothing
        // this step's own compensate() needs to undo beyond that.
        return null;
    }

    /**
     * `code` is deliberately never stored here: it never changes between
     * attempts of the same run (it comes straight from `--code`), so every
     * site that needs it reads it fresh through {@see TenantCode::normalise()}
     * instead of carrying a second copy through the journal (AC-37).
     *
     * @return array<string, mixed>
     */
    private function chooseIdentity(): array
    {
        $sequence = $this->nextSequence();

        return [
            'tenant_id' => (string) Str::ulid(),
            'sequence' => $sequence,
            'database_name' => TenantDatabaseNames::databaseName($sequence),
            'app_username' => TenantDatabaseNames::appUsername($sequence),
            'migrate_username' => TenantDatabaseNames::migrateUsername($sequence),
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function attemptInsert(RunContext $context, StepJournal $journal, array $intent): array
    {
        for ($attempt = 1; $attempt <= self::MAX_SEQUENCE_ATTEMPTS; $attempt++) {
            if (self::$raceHook !== null) {
                $hook = self::$raceHook;
                self::$raceHook = null;
                $hook();
            }

            try {
                $now = $this->clock->timestamp();

                $this->master->get()->transaction(function () use ($context, $intent, $now): void {
                    $this->master->get()->table('tenants')->insert([
                        'id' => $intent['tenant_id'],
                        'sequence' => $intent['sequence'],
                        'code' => TenantCode::normalise($context->input['code']),
                        'slug' => $context->input['slug'],
                        'name' => $context->input['name'],
                        'status' => 'provisioning',
                        'plan_id' => null,
                        'country' => $context->input['country'],
                        'timezone' => $context->input['timezone'],
                        'currency' => $context->input['currency'],
                        'business_type' => $context->input['business_type'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $this->runs->setTenantId($context->runId, (string) $intent['tenant_id'], $now);
                });

                return $intent + ['code' => TenantCode::normalise($context->input['code'])];
            } catch (UniqueConstraintViolationException) {
                $this->refuseIfCodeTaken(TenantCode::normalise($context->input['code']), (string) $intent['tenant_id']);

                // Not a code collision: it was the sequence. Recompute and
                // journal the new attempt before retrying - AC-7's bounded
                // retry, never a silent infinite loop.
                $intent = $this->chooseIdentity();
                $journal->write($intent);
            }
        }

        throw new RuntimeException(
            'reserve_identity could not reserve a unique sequence after '.self::MAX_SEQUENCE_ATTEMPTS.' attempts.'
        );
    }

    /**
     * @throws PlatformRefused when `$code` already names a different tenant
     */
    private function refuseIfCodeTaken(string $code, ?string $ownTenantId): void
    {
        $existing = $this->master->get()->table('tenants')->where('code', $code)->first();

        if ($existing === null || (string) $existing->id === $ownTenantId) {
            return;
        }

        $run = $this->master->get()->table('tenant_provisioning_runs')
            ->where('tenant_id', (string) $existing->id)
            ->orderByDesc('created_at')
            ->first();

        if ($run !== null) {
            throw PlatformRefused::validation(
                "Tenant code [{$code}] is already reserved by provisioning run [{$run->id}]. Use --resume={$run->id} instead."
            );
        }

        throw PlatformRefused::validation("Tenant code [{$code}] is already in use.");
    }

    private function nextSequence(): int
    {
        $max = (int) $this->master->get()->table('tenants')->max('sequence');

        return $max + 1;
    }
}

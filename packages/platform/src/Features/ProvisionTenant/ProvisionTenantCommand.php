<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use Flexipos\Platform\Provisioning\ProvisioningRunRefused;
use Illuminate\Console\Command;
use LogicException;
use RuntimeException;

/**
 * `php artisan tenant:provision` (T-026 AC-1, D-28).
 *
 * Every option is kebab-case, matching every other command in this
 * codebase; `--actor=` matches `ChangeSubscriptionStatusCommand`'s own
 * convention and `--plan=` takes the plan's **id**, matching
 * `ChangeSubscriptionPlanCommand`'s (B-11).
 *
 * **The one catch clause covers both packages' refusals without importing
 * either.** `Flexipos\Platform\Exceptions\PlatformRefused` and the
 * organization package's own refusal type (reached only through
 * `Flexipos\Contracts\Organization\DefaultBranch::validate()`, AC-39) both
 * extend `\RuntimeException`, and {@see ProvisioningRunRefused}
 * does too - so catching the base type here answers all three without this
 * file ever holding a `use` of `packages/organization`'s internals.
 */
final class ProvisionTenantCommand extends Command
{
    protected $signature = 'tenant:provision
        {--code= : The tenant code, 1 to 30 characters of A-Z, 0-9 and hyphen}
        {--name= : The tenant/shop name}
        {--slug= : Defaults to the lower-cased code (D-2)}
        {--country= : Exactly two upper-case letters}
        {--timezone= : An IANA timezone identifier}
        {--currency= : Exactly three upper-case letters}
        {--business-type= : retail, restaurant or both}
        {--owner-email= : The new owner\'s login email}
        {--owner-name= : The owner employee\'s display name (defaults from the email)}
        {--plan= : The plan id to start the subscription on}
        {--active : Start the subscription active instead of trial}
        {--branch-code= : Defaults to MAIN}
        {--branch-name= : Defaults to the tenant\'s own name}
        {--server= : Override automatic server selection}
        {--actor= : The acting platform user id, recorded on this run\'s audit rows}
        {--resume= : Resume an existing run by id}
        {--abandon= : Abandon an existing run by id}
        {--reissue-owner-password= : Reissue the owner\'s one-time password for a run whose create_owner step already succeeded, including an already-completed run}';

    protected $description = 'Create a tenant end to end: database, schema, seed data and an owner login, in one resumable run';

    public function handle(ProvisionTenantHandler $handler): int
    {
        try {
            $result = $handler->handle(new ProvisionTenant(
                code: $this->stringOption('code'),
                name: $this->stringOption('name'),
                country: $this->stringOption('country'),
                timezone: $this->stringOption('timezone'),
                currency: $this->stringOption('currency'),
                businessType: $this->stringOption('business-type'),
                ownerEmail: $this->stringOption('owner-email'),
                ownerName: $this->stringOption('owner-name'),
                planId: $this->stringOption('plan'),
                active: (bool) $this->option('active'),
                branchCode: $this->stringOption('branch-code'),
                branchName: $this->stringOption('branch-name'),
                serverCode: $this->stringOption('server'),
                slug: $this->stringOption('slug'),
                actorUserId: $this->stringOption('actor'),
                resumeRunId: $this->stringOption('resume'),
                abandonRunId: $this->stringOption('abandon'),
                reissueOwnerPasswordRunId: $this->stringOption('reissue-owner-password'),
            ));
        } catch (RuntimeException|LogicException $refused) {
            // Review round 1 R-1: if a created/rotated owner password was
            // ever generated before this failure, this is the one chance
            // this process gets to show it - printed before the error, the
            // same order a successful run already uses.
            if ($refused instanceof ProvisionTenantFailedWithOwnerAnnouncement) {
                $this->printOwnerAnnouncement($refused->ownerMode, $refused->ownerPassword);
            }

            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Provisioning run {$result->runId} is {$result->status}.");
        $this->announceOwner($result);

        if ($result->abandonMessage !== null) {
            $this->components->warn($result->abandonMessage);
        }

        return self::SUCCESS;
    }

    /**
     * D-26: the owner's initial password is printed exactly once, from the
     * in-memory result, and only in the attempt that actually inserted the
     * `users` row.
     */
    private function announceOwner(ProvisionTenantResult $result): void
    {
        $this->printOwnerAnnouncement($result->ownerMode, $result->ownerPassword);
    }

    /**
     * The one place both a successful result and a failure-with-announcement
     * (review round 1 R-1) print the owner's credential state, so the two
     * paths cannot drift into two different sentences for the same mode.
     */
    private function printOwnerAnnouncement(?string $mode, ?string $password): void
    {
        match ($mode) {
            'created' => $this->components->warn("Owner login created. Initial password: {$password}"),
            'rotated' => $this->components->warn(
                "The owner login's password could not be recovered from an earlier attempt and was rotated. New password: {$password}"
            ),
            'attached' => $this->components->info('The owner email already had a login; it was attached to this tenant.'),
            'already_created' => $this->components->info('The initial password was shown when it was created.'),
            // Review round 2 R-3: reached only from a resume (or
            // --reissue-owner-password) that found create_owner's own
            // output already password-bearing but nobody ever saw it in
            // this process - never silence either outcome.
            'already_signed_in' => $this->components->info(
                "The owner's one-time password from an earlier, crashed attempt could not be recovered: the login "
                .'has already been signed into (or its password already changed) since, so nothing was reissued.'
            ),
            'nothing_to_reissue' => $this->components->info(
                'This run has no owner password to reissue: create_owner has not succeeded yet, or the owner email '
                .'was attached to an existing login rather than creating a new one.'
            ),
            default => null,
        };
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return $value === null ? null : (string) $value;
    }
}

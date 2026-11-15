<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ProvisionTenant;

use DateTimeZone;
use Flexipos\Contracts\Organization\BranchSeed;
use Flexipos\Contracts\Organization\DefaultBranch;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\TenantCode;
use Flexipos\Platform\Support\MasterConnection;

/**
 * AC-1, validated and normalised: every field a fresh `tenant:provision`
 * invocation needs, checked **before any row is written** (D-25, D-28).
 *
 * **This is also the run's own `input` JSON** ({@see self::toArray()}):
 * `tenant_provisioning_runs.input` holds no secrets (Ruling 2), and nothing
 * here is one, so the validated shape and the journalled shape are the same
 * object, not two that could drift.
 *
 * **Business-type and currency/country format are this class's own check,
 * never `packages/organization`'s `Fields`** - `Package_Platform` may reach
 * only the kernel and the published contracts (AC-39), so the mechanical
 * rules AC-1 states for `--country`/`--currency`/`--timezone`/`--business-type`
 * are read directly from `tenants`' own columns and restated here, exactly
 * once. Only the branch name/code rule is delegated, through
 * {@see DefaultBranch::validate()} - the one rule that contract exists to
 * answer without a tenant connection (B-12).
 */
final class ProvisionTenantInput
{
    /** Mirrors `tenants.business_type` (`docs/database.md` section 4.1). */
    public const BUSINESS_TYPES = ['retail', 'restaurant', 'both'];

    public function __construct(
        public readonly string $code,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $country,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly string $businessType,
        public readonly string $ownerEmail,
        public readonly ?string $ownerName,
        public readonly string $planId,
        public readonly bool $active,
        public readonly ?string $branchCode,
        public readonly ?string $branchName,
        public readonly ?string $serverCode,
    ) {}

    /**
     * @throws PlatformRefused validation, or the organization package's own
     *                         refusal type for the branch rule (both are
     *                         RuntimeException; the command catches by that
     *                         base type rather than importing the other
     *                         package's exception class, AC-39)
     */
    public static function validate(ProvisionTenant $submitted, MasterConnection $master, DefaultBranch $defaultBranch): self
    {
        $code = TenantCode::normalise($submitted->code);
        $name = self::requiredText($submitted->name, 'name', 150);
        $country = self::country($submitted->country);
        $currency = self::currency($submitted->currency);
        $timezone = self::timezone($submitted->timezone);
        $businessType = self::businessType($submitted->businessType);
        $ownerEmail = self::email($submitted->ownerEmail);
        $ownerName = self::optionalText($submitted->ownerName);
        $planId = self::plan($master, $submitted->planId);
        // Branch name/code's own rules are DefaultBranch::validate()'s job
        // (B-12, Ruling 5) - this only trims-or-nulls, never a second width
        // check that could go out of step with organization's own.
        $branchCode = self::optionalText($submitted->branchCode);
        $branchName = self::optionalText($submitted->branchName);
        $serverCode = self::optionalText($submitted->serverCode);
        $slug = $submitted->slug === null ? strtolower($code) : self::slug($submitted->slug);

        $defaultBranch->validate(new BranchSeed(
            tenantName: $name,
            timezone: $timezone,
            currency: $currency,
            businessType: $businessType,
            code: $branchCode,
            name: $branchName,
        ));

        return new self(
            code: $code,
            slug: $slug,
            name: $name,
            country: $country,
            timezone: $timezone,
            currency: $currency,
            businessType: $businessType,
            ownerEmail: $ownerEmail,
            ownerName: $ownerName,
            planId: $planId,
            active: $submitted->active,
            branchCode: $branchCode,
            branchName: $branchName,
            serverCode: $serverCode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'slug' => $this->slug,
            'name' => $this->name,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'business_type' => $this->businessType,
            'owner_email' => $this->ownerEmail,
            'owner_name' => $this->ownerName,
            'plan_id' => $this->planId,
            'active' => $this->active,
            'branch_code' => $this->branchCode,
            'branch_name' => $this->branchName,
            'server_code' => $this->serverCode,
        ];
    }

    private static function requiredText(?string $submitted, string $field, int $max): string
    {
        if ($submitted === null || trim($submitted) === '') {
            throw PlatformRefused::validation("The --{$field} option is required and must not be blank.");
        }

        $value = trim($submitted);

        if (mb_strlen($value) > $max) {
            throw PlatformRefused::validation("The --{$field} option must be at most {$max} characters.");
        }

        return $value;
    }

    private static function optionalText(?string $submitted): ?string
    {
        if ($submitted === null || trim($submitted) === '') {
            return null;
        }

        return trim($submitted);
    }

    /**
     * Two upper-case letters, submitted as-is - AC-1 states "exactly two
     * upper-case letters", never folded.
     */
    private static function country(?string $submitted): string
    {
        $value = self::requiredText($submitted, 'country', 2);

        if (preg_match('/\A[A-Z]{2}\z/', $value) !== 1) {
            throw PlatformRefused::validation('The --country option must be exactly two upper-case letters.');
        }

        return $value;
    }

    /**
     * Three upper-case letters, submitted as-is - the same "never folded"
     * rule AC-1 states for --country.
     */
    private static function currency(?string $submitted): string
    {
        $value = self::requiredText($submitted, 'currency', 3);

        if (preg_match('/\A[A-Z]{3}\z/', $value) !== 1) {
            throw PlatformRefused::validation('The --currency option must be exactly three upper-case letters.');
        }

        return $value;
    }

    private static function timezone(?string $submitted): string
    {
        $value = self::requiredText($submitted, 'timezone', 64);

        if (! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            throw PlatformRefused::validation('The --timezone option must be a recognised IANA zone.');
        }

        return $value;
    }

    private static function businessType(?string $submitted): string
    {
        $value = self::requiredText($submitted, 'business-type', 30);

        if (! in_array($value, self::BUSINESS_TYPES, true)) {
            throw PlatformRefused::validation(
                'The --business-type option must be one of '.implode(', ', self::BUSINESS_TYPES).'.'
            );
        }

        return $value;
    }

    private static function email(?string $submitted): string
    {
        $value = self::requiredText($submitted, 'owner-email', 191);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw PlatformRefused::validation('The --owner-email option must be a valid email address.');
        }

        return $value;
    }

    /**
     * The exact lookup `StartSubscriptionHandler` already performs (AC-1,
     * B-8): not soft-deleted, `is_active = true`. Read directly here rather
     * than inventing a second rule for what counts as a valid plan.
     */
    private static function plan(MasterConnection $master, ?string $submitted): string
    {
        $value = self::requiredText($submitted, 'plan', 191);

        $plan = $master->get()->table('plans')
            ->where('id', $value)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->first();

        if ($plan === null) {
            throw PlatformRefused::notFound("No active plan [{$value}].");
        }

        return $value;
    }

    private static function slug(string $submitted): string
    {
        $value = strtolower(trim($submitted));

        if ($value === '' || mb_strlen($value) > 63) {
            throw PlatformRefused::validation('The --slug option must be 1 to 63 characters.');
        }

        return $value;
    }
}

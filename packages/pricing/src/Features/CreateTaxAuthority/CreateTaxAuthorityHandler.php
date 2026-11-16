<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreateTaxAuthority;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\TaxAuthorityFields;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a tax authority (T-029 AC-4). `code` is unique (also by case) and
 * non-blank. The two account columns are not built (F-17).
 */
final class CreateTaxAuthorityHandler
{
    public const ACTION = 'pricing_tax_authority_created';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateTaxAuthority $command): CreateTaxAuthorityResult
    {
        Fields::only($command->fields, TaxAuthorityFields::FIELDS);

        $read = TaxAuthorityFields::read($command->fields);

        if (! isset($read['code'], $read['name'])) {
            throw PricingAdminRefused::validation('The code and the name are required.');
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $read): CreateTaxAuthorityResult {
            Uniqueness::assertCodeFree($connection, 'tax_authorities', (string) $read['code']);

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('tax_authorities')->insert([
                    'id' => $id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'code' => $read['code'],
                    'name' => $read['name'],
                    'country' => $read['country'] ?? null,
                    'registration_no' => $read['registration_no'] ?? null,
                    'filing_frequency' => $read['filing_frequency'] ?? null,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate("The code [{$read['code']}] is already in use.");
            }

            $row = $connection->table('tax_authorities')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_authority', $id, null, $row === null ? null : (array) $row);

            return new CreateTaxAuthorityResult($id);
        });
    }
}

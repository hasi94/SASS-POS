<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdateTaxAuthority;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Flexipos\Pricing\Registry\PricingAuditActions;
use Flexipos\Pricing\Support\Fields;
use Flexipos\Pricing\Support\Identifier;
use Flexipos\Pricing\Support\Lookup;
use Flexipos\Pricing\Support\TaxAuthorityFields;
use Flexipos\Pricing\Support\TenantConnection;
use Flexipos\Pricing\Support\Uniqueness;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Update a tax authority's code, name, country, registration number or filing
 * frequency (T-029 AC-4).
 */
final class UpdateTaxAuthorityHandler
{
    public const ACTION = 'pricing_tax_authority_updated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateTaxAuthority $command): void
    {
        Fields::only($command->fields, TaxAuthorityFields::FIELDS);

        if ($command->fields === []) {
            throw PricingAdminRefused::validation('An update must name at least one field.');
        }

        $id = Identifier::fold($command->taxAuthorityId, 'taxAuthorityId');
        $changes = TaxAuthorityFields::read($command->fields);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $id, $changes): void {
            $before = Lookup::any($connection, 'tax_authorities', $id, 'tax authority', lock: true);

            if (isset($changes['code']) && $changes['code'] !== $before->code) {
                Uniqueness::assertCodeFree($connection, 'tax_authorities', $changes['code'], $id);
            }

            try {
                $connection->table('tax_authorities')->where('id', $id)->update($changes + ['updated_at' => $this->clock->timestamp()]);
            } catch (UniqueConstraintViolationException) {
                throw PricingAdminRefused::duplicate('The code is already in use.');
            }

            $after = $connection->table('tax_authorities')->where('id', $id)->first();

            $this->audit->record(PricingAuditActions::MODULE, $command->actorId, self::ACTION, 'tax_authority', $id, (array) $before, $after === null ? null : (array) $after);
        });
    }
}

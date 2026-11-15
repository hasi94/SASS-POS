<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\UpdateCustomerNote;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Update a customer note (T-028 plan Ruling 10, AC-34). `customer_id` is
 * immutable here.
 */
final class UpdateCustomerNoteHandler
{
    public const ACTION = 'customer_note_updated';

    public const FIELDS = ['note', 'pinned', 'follow_up_date'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateCustomerNote $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $noteId = Identifier::fold($command->noteId, 'noteId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $noteId, $fields): void {
            $before = $connection->table('customer_notes')->where('id', $noteId)->lockForUpdate()->first();

            if ($before === null) {
                throw CustomersRefused::notFound("The customer note [{$noteId}] does not exist.");
            }

            $changes = [];

            if (Fields::has($fields, 'note')) {
                $changes['note'] = Fields::requiredText($fields, 'note', 65535);
            }

            if (Fields::has($fields, 'pinned')) {
                $changes['pinned'] = Fields::flag($fields, 'pinned', false);
            }

            if (Fields::has($fields, 'follow_up_date')) {
                $changes['follow_up_date'] = Fields::date($fields, 'follow_up_date', null);
            }

            $changes['updated_at'] = $this->clock->timestamp();

            $connection->table('customer_notes')->where('id', $noteId)->update($changes);

            $after = $connection->table('customer_notes')->where('id', $noteId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_note',
                $noteId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}

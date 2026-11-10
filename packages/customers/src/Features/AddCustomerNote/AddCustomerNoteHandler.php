<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\AddCustomerNote;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Lookup;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Add a customer note (T-028 plan Ruling 10, AC-34). Leaf CRUD with no
 * append-only constraint; there is no delete slice (no AC asks for one).
 */
final class AddCustomerNoteHandler
{
    public const ACTION = 'customer_note_added';

    public const FIELDS = ['customer_id', 'note', 'pinned', 'follow_up_date'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(AddCustomerNote $command): AddCustomerNoteResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $customerId = Identifier::fold(Fields::requiredText($fields, 'customer_id', 26), 'customer_id');
        $note = Fields::requiredText($fields, 'note', 65535);
        $pinned = Fields::flag($fields, 'pinned', false);
        $followUpDate = Fields::date($fields, 'follow_up_date', null);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use (
            $connection, $command, $customerId, $note, $pinned, $followUpDate,
        ): AddCustomerNoteResult {
            Lookup::any($connection, 'customers', $customerId, 'customer');

            $now = $this->clock->timestamp();
            $noteId = (string) Str::ulid();

            $connection->table('customer_notes')->insert([
                'id' => $noteId,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => null,
                'updated_by' => null,
                'customer_id' => $customerId,
                'note' => $note,
                'pinned' => $pinned,
                'follow_up_date' => $followUpDate,
            ]);

            $row = $connection->table('customer_notes')->where('id', $noteId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_note',
                $noteId,
                null,
                $row === null ? null : (array) $row,
            );

            return new AddCustomerNoteResult($noteId);
        });
    }
}

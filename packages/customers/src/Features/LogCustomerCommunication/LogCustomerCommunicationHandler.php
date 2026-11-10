<?php

declare(strict_types=1);

namespace Flexipos\Customers\Features\LogCustomerCommunication;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Customers\Exceptions\CustomersRefused;
use Flexipos\Customers\Registry\CustomersAuditActions;
use Flexipos\Customers\Support\Fields;
use Flexipos\Customers\Support\Identifier;
use Flexipos\Customers\Support\Lookup;
use Flexipos\Customers\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Log a customer communication (T-028 plan Ruling 8, AC-35). A
 * communication has no update: it is a log of what happened, not an
 * editable record.
 */
final class LogCustomerCommunicationHandler
{
    public const ACTION = 'customer_communication_logged';

    public const CHANNELS = ['call', 'email', 'sms', 'whatsapp', 'visit', 'campaign'];

    public const DIRECTIONS = ['in', 'out'];

    public const FIELDS = ['customer_id', 'channel', 'direction', 'subject', 'body', 'campaign_ref', 'employee_id', 'occurred_at'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(LogCustomerCommunication $command): LogCustomerCommunicationResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $customerId = Identifier::fold(Fields::requiredText($fields, 'customer_id', 26), 'customer_id');
        $channel = Fields::enum($fields, 'channel', self::CHANNELS, null);

        if ($channel === null) {
            throw CustomersRefused::validation('The channel is required and must be one of: '.implode(', ', self::CHANNELS).'.');
        }

        $direction = Fields::enum($fields, 'direction', self::DIRECTIONS, null);

        if ($direction === null) {
            throw CustomersRefused::validation('The direction is required and must be one of: '.implode(', ', self::DIRECTIONS).'.');
        }

        $subject = Fields::optionalText($fields, 'subject', 255);
        $body = Fields::optionalText($fields, 'body', 65535);
        $campaignRef = Fields::optionalText($fields, 'campaign_ref', 100);
        $employeeId = Identifier::foldNullable(Fields::has($fields, 'employee_id') ? $fields['employee_id'] : null, 'employee_id');

        $connection = $this->tenant->get();

        return $connection->transaction(function () use (
            $connection, $command, $customerId, $channel, $direction, $subject, $body, $campaignRef, $employeeId, $fields,
        ): LogCustomerCommunicationResult {
            Lookup::any($connection, 'customers', $customerId, 'customer');

            if ($employeeId !== null && $connection->table('employees')->where('id', $employeeId)->first() === null) {
                throw CustomersRefused::notFound("No employee has id [{$employeeId}].");
            }

            $now = $this->clock->timestamp();
            $occurredAt = Fields::has($fields, 'occurred_at') ? Fields::optionalText($fields, 'occurred_at', 29) : $now;
            $occurredAt ??= $now;
            $communicationId = (string) Str::ulid();

            $connection->table('customer_communications')->insert([
                'id' => $communicationId,
                'created_at' => $now,
                'updated_at' => $now,
                'customer_id' => $customerId,
                'channel' => $channel,
                'direction' => $direction,
                'subject' => $subject,
                'body' => $body,
                'campaign_ref' => $campaignRef,
                'employee_id' => $employeeId,
                'occurred_at' => $occurredAt,
            ]);

            $row = $connection->table('customer_communications')->where('id', $communicationId)->first();

            $this->audit->record(
                CustomersAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'customer_communication',
                $communicationId,
                null,
                $row === null ? null : (array) $row,
            );

            return new LogCustomerCommunicationResult($communicationId);
        });
    }
}

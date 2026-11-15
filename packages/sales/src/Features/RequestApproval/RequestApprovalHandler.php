<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RequestApproval;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Approvals\ApprovalBinding;
use Flexipos\Sales\Approvals\ApprovalStatus;
use Flexipos\Sales\Approvals\ApprovalType;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\TenantConnection;
use Illuminate\Support\Str;

/**
 * Ask for an approval (T-030 AC-23, D-13, D-47).
 *
 * Only the four types this task builds are accepted, each bound to exactly what
 * {@see ApprovalBinding} says; `credit_limit`, `negative_stock` and `custom` are
 * `validation`. The requester is the actor, never a field: a request for someone
 * else's name would be a way to approve one's own void. `plugin_name`, `status`
 * and every decision column are not fields at all.
 *
 * **Lock order.** The branch is read first under a shared lock, which is also the
 * first statement of the transaction (Ruling 7, item 2); the row it inserts is
 * new, so nothing else can wait on it.
 */
final class RequestApprovalHandler
{
    public const ACTION = 'sales_approval_requested';

    private const FIELDS = ['type', 'branch_id', 'terminal_id', 'reference_type', 'reference_id', 'payload'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly EmployeeDirectory $employees,
    ) {}

    public function handle(RequestApproval $command): RequestApprovalResult
    {
        Fields::only($command->fields, self::FIELDS);

        if ($command->actorId === null) {
            throw SalesRefused::validation('An approval request names the employee who asks for it.');
        }

        $fields = $command->fields;

        $type = is_string($fields['type'] ?? null) ? ApprovalType::tryFrom($fields['type']) : null;

        if ($type === null) {
            throw SalesRefused::validation('The type must be one of: '.implode(', ', array_map(static fn (ApprovalType $case): string => $case->value, ApprovalType::cases())).'.');
        }

        $branchId = Identifier::fold($fields['branch_id'] ?? null, 'branch_id');
        $terminalId = Identifier::foldNullable($fields['terminal_id'] ?? null, 'terminal_id');
        $referenceType = Fields::requiredText($fields, 'reference_type', 50);
        $referenceId = Identifier::foldNullable($fields['reference_id'] ?? null, 'reference_id');
        $requestedBy = Identifier::fold($command->actorId, 'actor');

        ApprovalBinding::assertReference($type, $referenceType, $referenceId);

        $payload = ApprovalBinding::canonicalPayload($type, $fields['payload'] ?? null);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $type, $branchId, $terminalId, $referenceType, $referenceId, $requestedBy, $payload): RequestApprovalResult {
            Lookup::any($connection, 'branches', $branchId, 'branch', Lookup::SHARED);

            if ($terminalId !== null) {
                Lookup::live($connection, 'terminals', $terminalId, 'terminal');
            }

            if ($referenceType === ApprovalBinding::SALE) {
                $sale = Lookup::any($connection, 'sales', (string) $referenceId, 'sale');

                if (strtoupper((string) $sale->branch_id) !== $branchId) {
                    throw SalesRefused::validation('The sale belongs to another branch.');
                }
            }

            if (! $this->employees->isLive($requestedBy)) {
                throw SalesRefused::notFound("No employee has id [{$requestedBy}].");
            }

            $id = (string) Str::ulid();
            $now = $this->clock->timestamp();

            $connection->table('approval_requests')->insert([
                'id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
                'terminal_id' => $terminalId,
                'branch_id' => $branchId,
                'type' => $type->value,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'requested_by' => $requestedBy,
                'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => ApprovalStatus::Pending->value,
            ]);

            $row = $connection->table('approval_requests')->where('id', $id)->first();

            $this->audit->record(SalesAuditActions::MODULE, $requestedBy, self::ACTION, 'approval_request', $id, null, $row === null ? null : (array) $row, $branchId);

            return new RequestApprovalResult($id);
        });
    }
}

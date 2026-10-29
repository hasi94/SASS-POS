<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\SearchPlatformAuditLog;

use DateTimeImmutable;
use Exception as DateParseException;
use Flexipos\Kernel\Audit\AuditPage;
use Flexipos\Kernel\Audit\PageSize;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\PlatformAuditActions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * List `platform_audit_logs` rows, filtered, paged newest-first
 * (T-017 AC-16, AC-17, Ruling 4, Ruling 10).
 *
 * **Never a tenant statement** (AC-16's own "never reads the master" sibling
 * claim, mirrored): every statement this handler issues is against the
 * master connection, resolved the one way this package ever resolves it.
 *
 * **The gate runs first**, exactly as the tenant side's does: a caller who
 * is not platform staff does not get to learn whether their filter was
 * well formed either.
 */
final class SearchPlatformAuditLogHandler
{
    /**
     * `platform_audit_logs`'s own column list, in table order. Not shared
     * with the tenant side's `AuditColumns`: this package may not depend on
     * `packages/access`, and the two tables do not share a shape (no
     * `module`, no `branch_id`, no `changed_fields`; `actor_email` and
     * `tenant_id` instead).
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'actor_type', 'actor_id', 'actor_email', 'tenant_id', 'action',
        'subject_type', 'subject_id', 'before', 'after', 'ip', 'user_agent',
        'request_id', 'created_at',
    ];

    public function __construct(
        private readonly MasterConnection $master,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(SearchPlatformAuditLog $query): AuditPage
    {
        $connection = $this->master->get();

        $this->assertPlatformStaff($connection, $query->actorUserId);

        if ($query->subjectId !== null && $query->subjectType === null) {
            throw PlatformRefused::validation('subject_id requires subject_type.');
        }

        if ($query->action !== null && ! in_array($query->action, PlatformAuditActions::ALL, true)) {
            throw PlatformRefused::validation("Unknown action [{$query->action}].");
        }

        if ($query->beforeId !== null && $query->beforeId < 1) {
            throw PlatformRefused::validation('before_id must be a positive integer.');
        }

        $from = $this->parseInstant($query->from, atEndOfDay: false);
        $to = $this->parseInstant($query->to, atEndOfDay: true);

        if ($from !== null && $to !== null && $to < $from) {
            throw PlatformRefused::validation('The end of the range precedes its start.');
        }

        $builder = $connection->table('platform_audit_logs')->select(self::COLUMNS);

        $this->applyFilters($builder, $query, $from, $to);

        $maxPageSize = (int) $this->config->get('audit.max_page_size', 500);
        $size = PageSize::clamp($query->pageSize, (int) $this->config->get('audit.page_size', 50), $maxPageSize);

        $rows = $builder->orderByDesc('id')->limit($size + 1)->get()->all();

        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        $nextBeforeId = $hasMore ? (int) ((array) $rows[count($rows) - 1])['id'] : null;

        return new AuditPage(
            self::COLUMNS,
            array_map(static fn (object $row): array => self::renderIp((array) $row), $rows),
            $nextBeforeId,
        );
    }

    /**
     * @throws PlatformRefused `forbidden` (Ruling 10): no row, soft-deleted,
     *                         not active, or not platform staff - one reason
     *                         for all four, so the gate does not reveal
     *                         which user ids exist
     */
    private function assertPlatformStaff(Connection $connection, string $actorUserId): void
    {
        $user = $connection->table('users')->where('id', $actorUserId)->first();

        if (
            $user === null
            || $user->deleted_at !== null
            || (string) $user->status !== 'active'
            || ! (bool) $user->is_platform_staff
        ) {
            throw PlatformRefused::forbidden('The actor may not read platform audit records.');
        }
    }

    private function applyFilters(Builder $builder, SearchPlatformAuditLog $query, ?DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        if ($query->tenantId !== null) {
            $builder->where('tenant_id', $query->tenantId);
        }

        if ($query->actorId !== null) {
            $builder->where('actor_id', $query->actorId);
        }

        if ($query->action !== null) {
            $builder->where('action', $query->action);
        }

        if ($query->subjectType !== null) {
            $builder->where('subject_type', $query->subjectType);

            if ($query->subjectId !== null) {
                $builder->where('subject_id', $query->subjectId);
            }
        }

        if ($from !== null) {
            $builder->where('created_at', '>=', $from->format(RunnerClock::PRECISION));
        }

        if ($to !== null) {
            $builder->where('created_at', '<=', $to->format(RunnerClock::PRECISION));
        }

        if ($query->beforeId !== null) {
            $builder->where('id', '<', $query->beforeId);
        }
    }

    /**
     * `ip` as hex, never a printed address (Ruling 11): a third `inet_ntop`
     * home is the hazard FP-306 records, and no package platform may depend
     * on holds the one this project already has.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function renderIp(array $row): array
    {
        $row['ip'] = $row['ip'] === null ? null : bin2hex((string) $row['ip']);

        return $row;
    }

    private function parseInstant(?string $value, bool $atEndOfDay): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($day === false) {
                throw PlatformRefused::validation("[{$value}] is not a valid date.");
            }

            return $atEndOfDay ? $day->modify('+1 day')->modify('-1 millisecond') : $day;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (DateParseException) {
            throw PlatformRefused::validation("[{$value}] is not a parseable instant.");
        }
    }
}

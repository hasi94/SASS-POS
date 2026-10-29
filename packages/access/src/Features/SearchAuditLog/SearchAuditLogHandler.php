<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SearchAuditLog;

use DateTimeImmutable;
use Exception as DateParseException;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AccessLogWriter;
use Flexipos\Access\Support\AuditCatalogue;
use Flexipos\Access\Support\AuditColumns;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Kernel\Audit\AuditPage;
use Flexipos\Kernel\Audit\PageSize;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\Builder;

/**
 * List `audit_logs` or `audit_logs_archive` rows, filtered, paged
 * newest-first (T-017 AC-4, AC-5, AC-9, Ruling 4).
 *
 * **Never a cross-database join, never the master connection** (AC-4's own
 * words): every statement this handler issues is against the tenant
 * connection the caller has already entered.
 *
 * **The gate runs first, before any filter is even read.** A caller who
 * may not read audit records does not get to learn whether their filter
 * was well formed either.
 */
final class SearchAuditLogHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly PermissionResolver $resolver,
        private readonly AuditCatalogue $catalogue,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(SearchAuditLog $query): AuditPage
    {
        $decision = $this->resolver->decide($query->actorEmployeeId, 'audit.read', null);

        if (! $decision->allowed) {
            throw AccessRefused::notPermitted($decision);
        }

        if ($query->subjectId !== null && $query->subjectType === null) {
            throw AccessRefused::validation('subject_id requires subject_type.');
        }

        if ($query->module !== null && ! in_array($query->module, $this->catalogue->modules(), true)) {
            throw AccessRefused::validation("Unknown module [{$query->module}].");
        }

        if ($query->action !== null) {
            $known = $query->module !== null
                ? $this->catalogue->knows($query->module, $query->action)
                : in_array($query->action, $this->catalogue->actions(), true);

            if (! $known) {
                throw AccessRefused::validation("Unknown action [{$query->action}] for module [{$query->module}].");
            }
        }

        if ($query->beforeId !== null && $query->beforeId < 1) {
            throw AccessRefused::validation('before_id must be a positive integer.');
        }

        $from = $this->parseInstant($query->from, atEndOfDay: false);
        $to = $this->parseInstant($query->to, atEndOfDay: true);

        if ($from !== null && $to !== null && $to < $from) {
            throw AccessRefused::validation('The end of the range precedes its start.');
        }

        $connection = $this->tenant->get();
        $table = $query->archive ? 'audit_logs_archive' : 'audit_logs';

        $builder = $connection->table($table)->select(AuditColumns::ALL);

        $this->applyFilters($builder, $query, $from, $to);

        $maxPageSize = (int) $this->config->get('audit.max_page_size', 500);
        $size = PageSize::clamp($query->pageSize, (int) $this->config->get('audit.page_size', 50), $maxPageSize);

        $rows = $builder->orderByDesc('id')->limit($size + 1)->get()->all();

        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        $nextBeforeId = $hasMore ? (int) ((array) $rows[count($rows) - 1])['id'] : null;

        return new AuditPage(
            AuditColumns::ALL,
            array_map(static fn (object $row): array => self::renderIp((array) $row), $rows),
            $nextBeforeId,
        );
    }

    /**
     * `ip` as a printed address, never the packed bytes the column stores
     * (Ruling 11: "`ip` is printable on the tenant side"). Unlike the
     * platform side, this package already owns {@see AccessLogWriter}, so
     * the one unpacker it ships is reused rather than a second `inet_ntop`
     * home invented here.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function renderIp(array $row): array
    {
        $row['ip'] = AccessLogWriter::unpackIp($row['ip'] === null ? null : (string) $row['ip']);

        return $row;
    }

    private function applyFilters(Builder $builder, SearchAuditLog $query, ?DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        if ($query->subjectType !== null) {
            $builder->where('subject_type', $query->subjectType);

            if ($query->subjectId !== null) {
                $builder->where('subject_id', $query->subjectId);
            }
        }

        if ($query->actorId !== null) {
            $builder->where('actor_id', $query->actorId);
        }

        if ($query->module !== null) {
            $builder->where('module', $query->module);
        }

        if ($query->action !== null) {
            $builder->where('action', $query->action);
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
     * Parse a filter bound as an instant: ISO 8601, or a bare `Y-m-d`.
     *
     * A bare date is widened to the edge of the day it names, so that
     * "both ends are inclusive" means the whole day is in range: `from`
     * takes the day's first instant, `to` its last.
     *
     * @throws AccessRefused `validation` when the value parses as neither
     */
    private function parseInstant(?string $value, bool $atEndOfDay): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($day === false) {
                throw AccessRefused::validation("[{$value}] is not a valid date.");
            }

            return $atEndOfDay ? $day->modify('+1 day')->modify('-1 millisecond') : $day;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (DateParseException) {
            throw AccessRefused::validation("[{$value}] is not a parseable instant.");
        }
    }
}

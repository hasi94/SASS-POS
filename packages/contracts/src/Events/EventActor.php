<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Events;

use Flexipos\Contracts\Audit\ActorType;

/**
 * Who, or what, caused an event to be written (T-018 Ruling 2, AC-8).
 *
 * The same closed vocabulary `Flexipos\Access\Support\AuditWriter` uses for
 * `audit_logs`, so the tenant database does not grow a second "who did this"
 * vocabulary (D-16). `name` is a caller-supplied snapshot, taken at write
 * time and never re-joined later - there is no name-lookup here, the same
 * property `AuditWriter::actorName()` has for the employee it snapshots.
 */
final readonly class EventActor
{
    public function __construct(
        public ActorType $type,
        public ?string $id = null,
        public ?string $name = null,
    ) {}

    public static function employee(string $id, ?string $name = null): self
    {
        return new self(ActorType::Employee, $id, $name);
    }

    public static function terminal(string $id, ?string $name = null): self
    {
        return new self(ActorType::Terminal, $id, $name);
    }

    public static function plugin(string $id, ?string $name = null): self
    {
        return new self(ActorType::Plugin, $id, $name);
    }

    public static function platformStaff(string $id, ?string $name = null): self
    {
        return new self(ActorType::PlatformStaff, $id, $name);
    }

    /**
     * No actor was given. The one case with neither an id nor a name: a
     * background process is not a snapshot of anybody.
     */
    public static function system(): self
    {
        return new self(ActorType::System, null, null);
    }

    /**
     * The three keys `outbox_events.actor` stores, mirroring
     * `audit_logs`'s three actor columns (Ruling 4).
     *
     * @return array{type: string, id: ?string, name: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

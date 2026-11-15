<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Bus;

use Flexipos\Contracts\Events\EventActor;
use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Support\EnvelopeCodec;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-018 step 3 - the wire codec round trips exactly (Ruling 3, AC-3)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

it('round trips every field, including unicode and nested payloads', function () {
    $envelope = new EventEnvelope(
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'tenant-a',
        'fixture.pinged',
        3,
        'fixture',
        '01ARZ3NDEKTSV4RRFFQ69G5FBB',
        '01ARZ3NDEKTSV4RRFFQ69G5FCC',
        ['name' => 'caf\xc3\xa9 \u{00e9}', 'nested' => ['a' => [1, 2, 3], 'b' => null]],
        '2026-01-01 00:00:00.000',
        '01ARZ3NDEKTSV4RRFFQ69G5FDD',
        '01ARZ3NDEKTSV4RRFFQ69G5FEE',
        EventActor::employee('E1', 'Kamal'),
    );

    $decoded = EnvelopeCodec::decode(EnvelopeCodec::encode($envelope));

    expect($decoded)->toBeInstanceOf(EventEnvelope::class)
        ->and($decoded?->id)->toBe($envelope->id)
        ->and($decoded?->tenantId)->toBe($envelope->tenantId)
        ->and($decoded?->type)->toBe($envelope->type)
        ->and($decoded?->version)->toBe($envelope->version)
        ->and($decoded?->aggregateType)->toBe($envelope->aggregateType)
        ->and($decoded?->aggregateId)->toBe($envelope->aggregateId)
        ->and($decoded?->branchId)->toBe($envelope->branchId)
        ->and($decoded?->payload)->toBe($envelope->payload)
        ->and($decoded?->occurredAt)->toBe($envelope->occurredAt)
        ->and($decoded?->correlationId)->toBe($envelope->correlationId)
        ->and($decoded?->causationId)->toBe($envelope->causationId)
        ->and($decoded?->actor->type)->toBe($envelope->actor->type)
        ->and($decoded?->actor->id)->toBe($envelope->actor->id)
        ->and($decoded?->actor->name)->toBe($envelope->actor->name);
});

it('duplicates type and event_id as top-level fields', function () {
    $envelope = new EventEnvelope(
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'tenant-a',
        'fixture.pinged',
        1,
        'fixture',
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        null,
        null,
        '2026-01-01 00:00:00.000',
        null,
        null,
        EventActor::system(),
    );

    $wire = EnvelopeCodec::encode($envelope);

    expect($wire['type'])->toBe('fixture.pinged')
        ->and($wire['event_id'])->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV');
});

it('decodes a corrupt or missing field to null, never a throw', function () {
    expect(EnvelopeCodec::decode(['envelope' => 'not json at all {']))->toBeNull()
        ->and(EnvelopeCodec::decode(['envelope' => '"just a string"']))->toBeNull()
        ->and(EnvelopeCodec::decode([]))->toBeNull()
        ->and(EnvelopeCodec::decode(['envelope' => '{"id":"x"}']))->toBeNull();
});

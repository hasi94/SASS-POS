<?php

namespace Flexipos\Catalog\Tests\Products;

use Flexipos\Catalog\Products\ComponentGraph;

/*
|--------------------------------------------------------------------------
| T-027 plan step 16 - ComponentGraph, pure (AC-34a, F-5)
|--------------------------------------------------------------------------
*/

it('refuses a direct self-component', function () {
    expect(ComponentGraph::wouldCreateCycle([], 'A', 'A'))->toBeTrue();
});

it('refuses a direct cycle: A contains B, then B contains A', function () {
    $edges = [['parent' => 'A', 'component' => 'B']];

    expect(ComponentGraph::wouldCreateCycle($edges, 'B', 'A'))->toBeTrue();
});

it('refuses a transitive cycle: A contains B contains C, then C contains A', function () {
    $edges = [
        ['parent' => 'A', 'component' => 'B'],
        ['parent' => 'B', 'component' => 'C'],
    ];

    expect(ComponentGraph::wouldCreateCycle($edges, 'C', 'A'))->toBeTrue();
});

it('accepts a diamond: A contains B, A contains C, then B contains C', function () {
    $edges = [
        ['parent' => 'A', 'component' => 'B'],
        ['parent' => 'A', 'component' => 'C'],
    ];

    expect(ComponentGraph::wouldCreateCycle($edges, 'B', 'C'))->toBeFalse();
});

it('accepts an edge with no relationship at all', function () {
    $edges = [['parent' => 'A', 'component' => 'B']];

    expect(ComponentGraph::wouldCreateCycle($edges, 'X', 'Y'))->toBeFalse();
});

it('does not loop on a cycle already present elsewhere in the edge list', function () {
    // Defensive: if a hand-inserted cycle already exists among the edges
    // (never produced by this handler), the visited set must still stop
    // the walk rather than loop forever.
    $edges = [
        ['parent' => 'P', 'component' => 'Q'],
        ['parent' => 'Q', 'component' => 'P'],
    ];

    expect(ComponentGraph::wouldCreateCycle($edges, 'Z', 'P'))->toBeFalse();
});

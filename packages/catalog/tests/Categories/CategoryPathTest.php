<?php

namespace Flexipos\Catalog\Tests\Categories;

use Flexipos\Catalog\Categories\CategoryPath;

/*
|--------------------------------------------------------------------------
| T-027 plan step 9 - categories.path and categories.depth (AC-1 to AC-5,
| AC-3a), pure
|--------------------------------------------------------------------------
*/

it('computes a root category: path is its own id, depth 0', function () {
    $result = CategoryPath::compute(null, 0, 'ROOT00000000000000000000X');

    expect($result)->toBe(['path' => 'ROOT00000000000000000000X', 'depth' => 0]);
});

it('computes a child category: path is parent/self, depth is parent + 1', function () {
    $result = CategoryPath::compute('ROOT00000000000000000000X', 0, 'CHILD0000000000000000000X');

    expect($result)->toBe(['path' => 'ROOT00000000000000000000X/CHILD0000000000000000000X', 'depth' => 1]);
});

it('computes a grandchild category: path is root/child/self, depth 2', function () {
    $result = CategoryPath::compute('ROOT00000000000000000000X/CHILD0000000000000000000X', 1, 'GRANDCHILD000000000000000');

    expect($result)->toBe([
        'path' => 'ROOT00000000000000000000X/CHILD0000000000000000000X/GRANDCHILD000000000000000',
        'depth' => 2,
    ]);
});

it('accepts a path at depth 17 (18 segments, 485 characters) and refuses depth 18 (19 segments, 512 characters)', function () {
    $segment = str_pad('X', 26, '0');

    $path17 = implode('/', array_fill(0, 18, $segment));
    $path18 = implode('/', array_fill(0, 19, $segment));

    expect(mb_strlen($path17))->toBe(485)
        ->and(CategoryPath::exceedsMax($path17))->toBeFalse()
        ->and(mb_strlen($path18))->toBe(512)
        ->and(CategoryPath::exceedsMax($path18))->toBeTrue();
});

it('rewrites a descendant path and depth when its ancestor moves', function () {
    $oldAncestor = 'ROOT00000000000000000000X/MID000000000000000000000X';
    $newAncestor = 'OTHER0000000000000000000X';
    $descendantPath = $oldAncestor.'/LEAF0000000000000000000X';

    $rewritten = CategoryPath::rewriteDescendantPath($oldAncestor, $newAncestor, $descendantPath);

    expect($rewritten)->toBe('OTHER0000000000000000000X/LEAF0000000000000000000X')
        ->and(CategoryPath::rewriteDescendantDepth(1, 0, 2))->toBe(1);
});

<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Products;

/**
 * Whether adding a `product_components` edge would close a cycle (T-027
 * AC-34a, plan Ruling 8, F-5).
 *
 * **Pure.** `AddProductComponent` reads every existing `(parent_product_id,
 * component_product_id)` pair and hands them here; this class does not touch
 * the database.
 *
 * A proposed edge `parent -> component` closes a cycle exactly when the
 * component can already reach the parent through existing edges - including
 * the direct self-reference, where `component === parent` closes the cycle
 * on the very first node visited. A breadth-first search from `component`,
 * never from `parent`, is what keeps a diamond (A contains B, A contains C,
 * B contains C) from being mistaken for a cycle: C cannot reach B, only B
 * and A can reach C.
 */
final class ComponentGraph
{
    /**
     * @param  list<array{parent: string, component: string}>  $edges  every existing component edge
     */
    public static function wouldCreateCycle(array $edges, string $parentId, string $componentId): bool
    {
        $adjacency = [];

        foreach ($edges as $edge) {
            $adjacency[$edge['parent']][] = $edge['component'];
        }

        $visited = [];
        $queue = [$componentId];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === $parentId) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($adjacency[$current] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return false;
    }
}

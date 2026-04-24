# Pattern — Hierarchy (Closure Table)

> One-line purpose: model arbitrary-depth trees (org units, category trees, threaded comments) with O(1) ancestor/descendant queries.

## When to use this pattern
- Deep or variable-depth trees where you frequently need "all descendants" or "all ancestors" in a single query (org charts, category trees, account hierarchies).
- Trees where you also need depth information, subtree counts, or to move/reparent nodes without rewriting many rows.
- Any hierarchy whose read:write ratio is heavily read-biased.

Does NOT fit:
- Fixed-depth hierarchies (e.g. "country → region → city" with exactly 3 levels) — just use 3 FK columns.
- Very write-heavy trees with constant reparenting across huge subtrees — closure rebuilds on move are O(ancestors × descendants); consider materialised-path or nested-sets if you benchmark a bottleneck.

## Schema

```sql
-- Migration: 0020_hierarchy_nodes.sql
CREATE TABLE org_nodes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    node_type   VARCHAR(40)  NOT NULL,
    parent_id   INT UNSIGNED NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_parent (parent_id),
    CONSTRAINT fk_node_parent FOREIGN KEY (parent_id) REFERENCES org_nodes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE org_closure (
    ancestor_id   INT UNSIGNED NOT NULL,
    descendant_id INT UNSIGNED NOT NULL,
    depth         SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (ancestor_id, descendant_id),
    KEY idx_descendant_depth (descendant_id, depth),
    CONSTRAINT fk_cl_anc  FOREIGN KEY (ancestor_id)   REFERENCES org_nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_cl_desc FOREIGN KEY (descendant_id) REFERENCES org_nodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `org_closure` holds one row per `(ancestor, descendant)` pair including `(n, n, 0)` self-rows — this is what makes `WHERE ancestor_id = :id` return the subtree.
- `depth` lets you scope queries to "direct children only" (`depth = 1`) vs. "entire subtree" (`depth > 0`).
- `parent_id` on the node table is redundant with closure but keeps the UI and single-row integrity simple.

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\OrgStructure\Services;

use AppCore\Core\Database;
use RuntimeException;

final class HierarchyService
{
    public function __construct(private readonly Database $db) {}

    public function addNode(string $name, string $type, ?int $parentId): int
    {
        $this->db->beginTransaction();
        try {
            $id = $this->db->insert('org_nodes', [
                'name' => $name, 'node_type' => $type, 'parent_id' => $parentId,
            ]);
            // Self-row
            $this->db->query(
                'INSERT INTO org_closure (ancestor_id, descendant_id, depth) VALUES (?, ?, 0)',
                [$id, $id]
            );
            if ($parentId !== null) {
                // Copy ancestors of parent and append this node as descendant at depth+1
                $this->db->query(
                    'INSERT INTO org_closure (ancestor_id, descendant_id, depth)
                       SELECT ancestor_id, ?, depth + 1
                       FROM   org_closure
                       WHERE  descendant_id = ?',
                    [$id, $parentId]
                );
            }
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function moveNode(int $nodeId, ?int $newParentId): void
    {
        if ($newParentId !== null && $this->isDescendant($newParentId, $nodeId)) {
            throw new RuntimeException('Cannot move a node under its own descendant.');
        }

        $this->db->beginTransaction();
        try {
            // 1. Disconnect the subtree from its old ancestors.
            $this->db->query(
                'DELETE c FROM org_closure c
                   JOIN org_closure d ON c.descendant_id = d.descendant_id
                   JOIN org_closure s ON c.ancestor_id   = s.ancestor_id
                  WHERE d.ancestor_id = ? AND s.descendant_id = ? AND s.ancestor_id != s.descendant_id',
                [$nodeId, $nodeId]
            );
            // 2. Reconnect under new parent (if any).
            if ($newParentId !== null) {
                $this->db->query(
                    'INSERT INTO org_closure (ancestor_id, descendant_id, depth)
                       SELECT supertree.ancestor_id, subtree.descendant_id,
                              supertree.depth + subtree.depth + 1
                       FROM   org_closure supertree
                       JOIN   org_closure subtree
                       WHERE  supertree.descendant_id = ?
                         AND  subtree.ancestor_id     = ?',
                    [$newParentId, $nodeId]
                );
            }
            $this->db->update('org_nodes', ['parent_id' => $newParentId], ['id' => $nodeId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function getDescendants(int $nodeId, bool $includeSelf = false): array
    {
        return $this->db->fetchAll(
            'SELECT n.id, n.name, n.node_type, n.parent_id, c.depth
               FROM org_closure c
               JOIN org_nodes   n ON n.id = c.descendant_id
              WHERE c.ancestor_id = ? AND c.depth >= ?
              ORDER BY c.depth, n.sort_order, n.name',
            [$nodeId, $includeSelf ? 0 : 1]
        );
    }

    public function getAncestors(int $nodeId): array
    {
        return $this->db->fetchAll(
            'SELECT n.id, n.name, c.depth
               FROM org_closure c
               JOIN org_nodes   n ON n.id = c.ancestor_id
              WHERE c.descendant_id = ? AND c.depth > 0
              ORDER BY c.depth DESC',
            [$nodeId]
        );
    }

    public function getSubtreeDepth(int $nodeId): int
    {
        $row = $this->db->fetchOne(
            'SELECT MAX(depth) AS d FROM org_closure WHERE ancestor_id = ?',
            [$nodeId]
        );
        return (int) ($row['d'] ?? 0);
    }

    private function isDescendant(int $candidate, int $ancestor): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM org_closure WHERE ancestor_id = ? AND descendant_id = ? AND depth > 0',
            [$ancestor, $candidate]
        ) !== null;
    }
}
```

## Controller integration

```php
public function move(Request $request): Response
{
    if ($c = $this->requirePermission('org.write')) return $c;

    $id     = (int) $request->getParam('id');
    $target = $request->getParam('parent_id');
    $target = $target === '' ? null : (int) $target;

    try {
        $this->hierarchy->moveNode($id, $target);
        $this->audit->log('org_node', $id, 'move', [], ['parent_id' => $target], $this->userId());
        $this->flash('success', t('org.moved'));
    } catch (\RuntimeException $e) {
        $this->flash('error', $e->getMessage());
    }
    return $this->redirect(route('org.index'));
}
```

## Template hints

Render trees breadth-first by ordering on `depth`, then `sort_order`. For large trees use HTMX to lazy-load children on expand (`hx-get="/org/{id}/children" hx-swap="afterend"`). Reuse `_empty_state` when a subtree is empty.

## Pitfalls

- Forgetting the self-row `(n, n, 0)` breaks "include self" queries and most JOIN patterns.
- Moving a node without a transaction leaves the closure table inconsistent — always wrap disconnect + reconnect together.
- `ON DELETE CASCADE` on closure rows is essential; without it, deleting a node orphans closure entries and subsequent queries return phantom paths.
- Never expose `depth` as a user-editable column — it is derived.
- `getDescendants` without an `ORDER BY depth` will interleave generations and confuse tree-render loops.

## Further reading
- [ADR-0007 — Closure tables for hierarchies](../decisions/0007-closure-tables.md)
- Alternatives (nested-sets, materialised-path) — rejected in ADR-0007 for reparenting cost and readability respectively.

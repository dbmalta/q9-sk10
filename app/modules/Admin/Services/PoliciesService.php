<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * Policies service.
 *
 * Manages named policies (each a group of versioned terms documents),
 * audience scoping via org nodes, activation state, acknowledgement
 * statistics, and per-policy acknowledgement reports.
 */
class PoliciesService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ──── CRUD ────

    public function createPolicy(string $name, ?string $description, int $createdBy, array $nodeIds = []): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Policy name is required');
        }

        $id = $this->db->insert('policies', [
            'name' => $name,
            'description' => $description !== null ? trim($description) : null,
            'is_active' => 1,
            'created_by' => $createdBy,
        ]);

        $this->replaceScope($id, $nodeIds);

        return $id;
    }

    public function updatePolicy(int $id, string $name, ?string $description, array $nodeIds): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Policy name is required');
        }

        $this->db->update('policies', [
            'name' => $name,
            'description' => $description !== null ? trim($description) : null,
        ], ['id' => $id]);

        $this->replaceScope($id, $nodeIds);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update('policies', ['is_active' => $active ? 1 : 0], ['id' => $id]);
    }

    public function getById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `policies` WHERE id = :id",
            ['id' => $id]
        );
    }

    public function getAll(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `policies` ORDER BY is_active DESC, name ASC"
        );
    }

    public function getScope(int $policyId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT node_id FROM `policy_scopes` WHERE policy_id = :pid",
            ['pid' => $policyId]
        );
        return array_map(fn($r) => (int) $r['node_id'], $rows);
    }

    private function replaceScope(int $policyId, array $nodeIds): void
    {
        $this->db->delete('policy_scopes', ['policy_id' => $policyId]);
        foreach (array_unique(array_map('intval', $nodeIds)) as $nodeId) {
            if ($nodeId <= 0) continue;
            $this->db->insert('policy_scopes', [
                'policy_id' => $policyId,
                'node_id' => $nodeId,
            ]);
        }
    }

    // ──── Audience resolution ────

    /**
     * Resolve the member ids that are required to acknowledge this policy.
     * Empty scope = all active members. Otherwise members in any scoped
     * node (or its descendants via org_closure).
     */
    public function getRequiredMemberIds(int $policyId): array
    {
        $scope = $this->getScope($policyId);

        if (empty($scope)) {
            $rows = $this->db->fetchAll(
                "SELECT id FROM `members` WHERE status = 'active'"
            );
        } else {
            $placeholders = [];
            $params = [];
            foreach ($scope as $i => $nodeId) {
                $key = "n$i";
                $placeholders[] = ":$key";
                $params[$key] = $nodeId;
            }
            $in = implode(',', $placeholders);
            $rows = $this->db->fetchAll(
                "SELECT DISTINCT m.id
                 FROM members m
                 JOIN member_nodes mn ON mn.member_id = m.id
                 JOIN org_closure oc ON oc.descendant_id = mn.node_id
                 WHERE m.status = 'active'
                   AND oc.ancestor_id IN ($in)",
                $params
            );
        }

        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    // ──── Stats ────

    /**
     * Stats for a single policy: required, acknowledged, rate (0–100).
     * A member is "acknowledged" if their linked user has accepted the
     * currently-published version of the policy.
     */
    public function getStats(int $policyId): array
    {
        $requiredIds = $this->getRequiredMemberIds($policyId);
        $required = count($requiredIds);

        $published = $this->db->fetchOne(
            "SELECT id FROM `terms_versions`
             WHERE policy_id = :pid AND is_published = 1
             LIMIT 1",
            ['pid' => $policyId]
        );

        if ($published === null || $required === 0) {
            return [
                'required' => $required,
                'acknowledged' => 0,
                'rate' => 0.0,
                'published_version_id' => $published['id'] ?? null,
            ];
        }

        $placeholders = [];
        $params = ['vid' => (int) $published['id']];
        foreach ($requiredIds as $i => $mid) {
            $key = "m$i";
            $placeholders[] = ":$key";
            $params[$key] = $mid;
        }
        $in = implode(',', $placeholders);

        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT m.id) AS c
             FROM members m
             JOIN terms_acceptances ta ON ta.user_id = m.user_id
             WHERE m.id IN ($in)
               AND ta.terms_version_id = :vid",
            $params
        );

        $acked = (int) ($row['c'] ?? 0);

        return [
            'required' => $required,
            'acknowledged' => $acked,
            'rate' => $required > 0 ? round(($acked / $required) * 100, 1) : 0.0,
            'published_version_id' => (int) $published['id'],
        ];
    }

    // ──── Member-facing ────

    /**
     * List the active policies (with a published version) that this member
     * is in scope for but has not yet acknowledged. Returns one entry per
     * policy, always pointing at the currently-published version of that
     * policy.
     *
     * @return array<int, array{policy_id:int, policy_name:string, version_id:int, version_title:string, version_number:string}>
     */
    public function getOutstandingForMember(int $memberId): array
    {
        return $this->findForMember($memberId, false);
    }

    /**
     * List active policies the member is in scope for AND has already
     * acknowledged (the current published version).
     */
    public function getAcknowledgedForMember(int $memberId): array
    {
        return $this->findForMember($memberId, true);
    }

    private function findForMember(int $memberId, bool $acknowledged): array
    {
        $member = $this->db->fetchOne(
            "SELECT id, user_id, status FROM members WHERE id = :id",
            ['id' => $memberId]
        );
        if ($member === null || $member['status'] !== 'active' || empty($member['user_id'])) {
            return [];
        }
        $userId = (int) $member['user_id'];

        $policies = $this->db->fetchAll(
            "SELECT p.id, p.name
             FROM policies p
             WHERE p.is_active = 1
               AND EXISTS (SELECT 1 FROM terms_versions tv WHERE tv.policy_id = p.id AND tv.is_published = 1)"
        );

        $out = [];
        foreach ($policies as $p) {
            $pid = (int) $p['id'];

            // Is member in this policy's audience?
            $required = $this->getRequiredMemberIds($pid);
            if (!in_array($memberId, $required, true)) {
                continue;
            }

            $version = $this->db->fetchOne(
                "SELECT id, title, version_number, published_at, grace_period_days
                 FROM terms_versions
                 WHERE policy_id = :pid AND is_published = 1 LIMIT 1",
                ['pid' => $pid]
            );
            if ($version === null) {
                continue;
            }

            $hasAck = $this->db->fetchOne(
                "SELECT accepted_at FROM terms_acceptances
                 WHERE terms_version_id = :vid AND user_id = :uid",
                ['vid' => (int) $version['id'], 'uid' => $userId]
            );
            $isAcked = $hasAck !== null;

            if ($isAcked !== $acknowledged) {
                continue;
            }

            // Overdue = published_at + grace_period_days in the past.
            $isOverdue = false;
            if (!$isAcked && !empty($version['published_at'])) {
                $grace = (int) ($version['grace_period_days'] ?? 0);
                try {
                    $deadline = (new \DateTimeImmutable($version['published_at']))
                        ->modify('+' . $grace . ' days');
                    $isOverdue = $deadline < new \DateTimeImmutable();
                } catch (\Throwable $e) {
                    $isOverdue = false;
                }
            }

            $out[] = [
                'policy_id' => $pid,
                'policy_name' => $p['name'],
                'version_id' => (int) $version['id'],
                'version_title' => (string) $version['title'],
                'version_number' => (string) $version['version_number'],
                'accepted_at' => $isAcked ? $hasAck['accepted_at'] : null,
                'is_overdue' => $isOverdue,
            ];
        }
        return $out;
    }

    // ──── Report ────

    /**
     * Per-member acknowledgement report for the currently-published version
     * of a policy. Returns: id, membership_number, first_name, surname,
     * email, acknowledged (bool), accepted_at (nullable).
     */
    public function getAcknowledgementReport(int $policyId): array
    {
        $requiredIds = $this->getRequiredMemberIds($policyId);
        if (empty($requiredIds)) {
            return [];
        }

        $published = $this->db->fetchOne(
            "SELECT id FROM `terms_versions`
             WHERE policy_id = :pid AND is_published = 1
             LIMIT 1",
            ['pid' => $policyId]
        );
        $versionId = $published['id'] ?? null;

        $placeholders = [];
        $params = [];
        foreach ($requiredIds as $i => $mid) {
            $key = "m$i";
            $placeholders[] = ":$key";
            $params[$key] = $mid;
        }
        $in = implode(',', $placeholders);

        if ($versionId !== null) {
            $params['vid'] = (int) $versionId;
            return $this->db->fetchAll(
                "SELECT m.id, m.membership_number, m.first_name, m.surname, m.email,
                        ta.accepted_at,
                        CASE WHEN ta.id IS NOT NULL THEN 1 ELSE 0 END AS acknowledged
                 FROM members m
                 LEFT JOIN terms_acceptances ta
                        ON ta.user_id = m.user_id
                       AND ta.terms_version_id = :vid
                 WHERE m.id IN ($in)
                 ORDER BY acknowledged ASC, m.surname, m.first_name",
                $params
            );
        }

        return $this->db->fetchAll(
            "SELECT m.id, m.membership_number, m.first_name, m.surname, m.email,
                    NULL AS accepted_at,
                    0 AS acknowledged
             FROM members m
             WHERE m.id IN ($in)
             ORDER BY m.surname, m.first_name",
            $params
        );
    }
}

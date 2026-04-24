<?php

declare(strict_types=1);

namespace AppCore\Modules\Permissions\Services;

use AppCore\Core\Database;
use AppCore\Core\Session;

/**
 * Role assignment management.
 *
 * Assigns roles to users with optional start/end dates and optional scope
 * node IDs. Invalidates the acting user's cached permissions on every
 * mutation.
 */
class AssignmentService
{
    private Database $db;
    private Session $session;

    public function __construct(Database $db, Session $session)
    {
        $this->db = $db;
        $this->session = $session;
    }

    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT ra.id, ra.role_id, ra.start_date, ra.end_date, ra.created_at,
                    r.name AS role_name, r.description AS role_description
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             WHERE ra.user_id = :uid
             ORDER BY ra.start_date DESC",
            ['uid' => $userId]
        );
    }

    /**
     * @param array<int> $nodeIds
     */
    public function assign(int $userId, int $roleId, ?string $startDate, ?string $endDate, array $nodeIds, ?int $assignedBy): int
    {
        $this->db->beginTransaction();
        try {
            $assignmentId = $this->db->insert('role_assignments', [
                'user_id'     => $userId,
                'role_id'     => $roleId,
                'start_date'  => $startDate ?: gmdate('Y-m-d'),
                'end_date'    => $endDate ?: null,
                'assigned_by' => $assignedBy,
            ]);

            foreach ($nodeIds as $nodeId) {
                $this->db->insert('role_assignment_scopes', [
                    'assignment_id' => $assignmentId,
                    'node_id'       => (int) $nodeId,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->invalidateCacheFor($userId);
        return $assignmentId;
    }

    public function end(int $assignmentId, ?string $endDate = null): void
    {
        $assignment = $this->db->fetchOne(
            "SELECT user_id FROM role_assignments WHERE id = :id",
            ['id' => $assignmentId]
        );
        if ($assignment === null) {
            return;
        }

        $this->db->update('role_assignments',
            ['end_date' => $endDate ?: gmdate('Y-m-d')],
            ['id' => $assignmentId]
        );

        $this->invalidateCacheFor((int) $assignment['user_id']);
    }

    /**
     * If the user being modified is the session user, invalidate their cached
     * permissions so the change is reflected on the next request.
     */
    private function invalidateCacheFor(int $userId): void
    {
        $current = $this->session->getUser();
        if ($current !== null && (int) $current['id'] === $userId) {
            $this->session->remove('_permissions');
        }
    }
}

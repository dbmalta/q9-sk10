<?php

declare(strict_types=1);

namespace App\Modules\Permissions\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;

/**
 * Role assignments controller.
 *
 * Manages role assignments for users: listing current + historical
 * assignments, creating new assignments, and ending assignments
 * (soft-delete via end_date rather than hard delete).
 */
class AssignmentsController extends Controller
{
    /**
     * GET /admin/roles/assignments/{userId} — list assignments for a user.
     */
    public function forUser(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.read');
        if ($guard !== null) return $guard;

        $userId = (int) $vars['userId'];
        $user = $this->app->getDb()->fetchOne(
            "SELECT id, email FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if ($user === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $showHistory = $request->getParam('history') === '1';

        if ($showHistory) {
            $assignments = $this->app->getDb()->fetchAll(
                "SELECT ra.*, r.name AS role_name
                 FROM role_assignments ra
                 JOIN roles r ON r.id = ra.role_id
                 WHERE ra.user_id = :user_id
                 ORDER BY ra.end_date IS NULL DESC, ra.start_date DESC",
                ['user_id' => $userId]
            );
        } else {
            $assignments = $this->app->getDb()->fetchAll(
                "SELECT ra.*, r.name AS role_name
                 FROM role_assignments ra
                 JOIN roles r ON r.id = ra.role_id
                 WHERE ra.user_id = :user_id
                   AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())
                 ORDER BY ra.start_date DESC",
                ['user_id' => $userId]
            );
        }

        // Load scope nodes for each assignment
        foreach ($assignments as &$assignment) {
            $assignment['scopes'] = $this->app->getDb()->fetchAll(
                "SELECT node_id FROM role_assignment_scopes WHERE assignment_id = :id",
                ['id' => $assignment['id']]
            );
        }

        $roles = $this->app->getDb()->fetchAll("SELECT id, name FROM roles ORDER BY name");

        return $this->render('@permissions/assignments/index.html.twig', [
            'target_user' => $user,
            'assignments' => $assignments,
            'roles' => $roles,
            'show_history' => $showHistory,
            'breadcrumbs' => [
                ['label' => $this->t('permissions.roles'), 'url' => '/admin/roles'],
                ['label' => $this->t('permissions.assignments_for', ['email' => $user['email']])],
            ],
        ]);
    }

    /**
     * POST /admin/roles/assignments/{userId} — create a new assignment.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $userId = (int) $vars['userId'];
        $user = $this->app->getDb()->fetchOne(
            "SELECT id FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if ($user === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $roleId = (int) $this->getParam('role_id', 0);
        $contextType = $this->getParam('context_type');
        $contextId = $this->getParam('context_id');
        $startDate = $this->getParam('start_date', gmdate('Y-m-d'));
        $scopeNodeIds = $this->getParam('scope_nodes', []);

        // Validate role exists
        $role = $this->app->getDb()->fetchOne("SELECT id FROM roles WHERE id = :id", ['id' => $roleId]);
        if ($role === null) {
            $this->flash('error', $this->t('permissions.invalid_role'));
            return $this->redirect("/admin/roles/assignments/$userId");
        }

        // Validate context type
        if ($contextType !== null && !in_array($contextType, ['node', 'team'], true)) {
            $contextType = null;
        }

        $currentUser = $this->app->getSession()->getUser();

        $assignmentId = $this->app->getDb()->insert('role_assignments', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'context_type' => $contextType ?: null,
            'context_id' => $contextId ? (int) $contextId : null,
            'start_date' => $startDate,
            'assigned_by' => $currentUser['id'] ?? null,
        ]);

        // Add scope nodes
        if (is_array($scopeNodeIds)) {
            foreach ($scopeNodeIds as $nodeId) {
                $this->app->getDb()->insert('role_assignment_scopes', [
                    'assignment_id' => $assignmentId,
                    'node_id' => (int) $nodeId,
                ]);
            }
        }

        // Invalidate the user's cached permissions
        // Note: only affects the current session; the target user's session
        // will reload on their next request
        $this->app->getPermissionResolver()->invalidate();

        Logger::info('Role assignment created', [
            'target_user_id' => $userId,
            'role_id' => $roleId,
            'assigned_by' => $currentUser['id'] ?? null,
        ]);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/roles/assignments/$userId");
    }

    /**
     * POST /admin/roles/assignments/{id}/end — end an assignment (set end_date).
     */
    public function end(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $assignmentId = (int) $vars['id'];
        $assignment = $this->app->getDb()->fetchOne(
            "SELECT * FROM role_assignments WHERE id = :id",
            ['id' => $assignmentId]
        );

        if ($assignment === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $this->app->getDb()->update('role_assignments', [
            'end_date' => gmdate('Y-m-d'),
        ], ['id' => $assignmentId]);

        $this->app->getPermissionResolver()->invalidate();

        Logger::info('Role assignment ended', [
            'assignment_id' => $assignmentId,
            'user_id' => $assignment['user_id'],
            'role_id' => $assignment['role_id'],
        ]);

        $this->flash('success', $this->t('permissions.assignment_ended'));
        return $this->redirect("/admin/roles/assignments/{$assignment['user_id']}");
    }

    /**
     * Translate a key using the app's i18n service.
     */
    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

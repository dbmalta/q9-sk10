<?php

declare(strict_types=1);

namespace App\Modules\Permissions\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * Roles management controller.
 *
 * CRUD operations for permission roles. System roles (Super Admin,
 * Group Leader, Section Leader) cannot be deleted but can be edited.
 */
class RolesController extends Controller
{
    /**
     * GET /admin/roles — list all roles.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.read');
        if ($guard !== null) return $guard;

        $roles = $this->app->getDb()->fetchAll(
            "SELECT r.*, (SELECT COUNT(*) FROM role_assignments ra WHERE ra.role_id = r.id AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())) AS active_assignments
             FROM roles r ORDER BY r.is_system DESC, r.name ASC"
        );

        return $this->render('@permissions/roles/index.html.twig', [
            'roles' => $roles,
            'breadcrumbs' => [
                ['label' => $this->t('nav.settings'), 'url' => '#'],
                ['label' => $this->t('permissions.roles')],
            ],
        ]);
    }

    /**
     * GET /admin/roles/create — show role creation form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) return $guard;

        return $this->render('@permissions/roles/form.html.twig', [
            'role' => null,
            'module_permissions' => $this->getModulePermissions(),
            'breadcrumbs' => [
                ['label' => $this->t('permissions.roles'), 'url' => '/admin/roles'],
                ['label' => $this->t('common.add')],
            ],
        ]);
    }

    /**
     * POST /admin/roles — store a new role.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $data = $this->extractRoleData($request);

        if (empty($data['name'])) {
            $this->flash('error', $this->t('permissions.name_required'));
            return $this->render('@permissions/roles/form.html.twig', [
                'role' => $data,
                'module_permissions' => $this->getModulePermissions(),
            ]);
        }

        $this->app->getDb()->insert('roles', [
            'name' => $data['name'],
            'description' => $data['description'],
            'permissions' => json_encode($data['permissions']),
            'can_publish_events' => $data['can_publish_events'] ? 1 : 0,
            'can_access_medical' => $data['can_access_medical'] ? 1 : 0,
            'can_access_financial' => $data['can_access_financial'] ? 1 : 0,
        ]);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/roles');
    }

    /**
     * GET /admin/roles/{id}/edit — show role edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) return $guard;

        $role = $this->app->getDb()->fetchOne(
            "SELECT * FROM roles WHERE id = :id",
            ['id' => (int) $vars['id']]
        );

        if ($role === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        // Decode permissions JSON for the form
        $role['permissions'] = json_decode($role['permissions'], true) ?? [];

        return $this->render('@permissions/roles/form.html.twig', [
            'role' => $role,
            'module_permissions' => $this->getModulePermissions(),
            'breadcrumbs' => [
                ['label' => $this->t('permissions.roles'), 'url' => '/admin/roles'],
                ['label' => $role['name']],
            ],
        ]);
    }

    /**
     * POST /admin/roles/{id} — update an existing role.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $roleId = (int) $vars['id'];
        $existing = $this->app->getDb()->fetchOne(
            "SELECT * FROM roles WHERE id = :id",
            ['id' => $roleId]
        );

        if ($existing === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $data = $this->extractRoleData($request);

        if (empty($data['name'])) {
            $this->flash('error', $this->t('permissions.name_required'));
            $data['id'] = $roleId;
            $data['is_system'] = $existing['is_system'];
            return $this->render('@permissions/roles/form.html.twig', [
                'role' => $data,
                'module_permissions' => $this->getModulePermissions(),
            ]);
        }

        $this->app->getDb()->update('roles', [
            'name' => $data['name'],
            'description' => $data['description'],
            'permissions' => json_encode($data['permissions']),
            'can_publish_events' => $data['can_publish_events'] ? 1 : 0,
            'can_access_medical' => $data['can_access_medical'] ? 1 : 0,
            'can_access_financial' => $data['can_access_financial'] ? 1 : 0,
        ], ['id' => $roleId]);

        // Invalidate cached permissions for all users with this role
        // (they'll reload on next request)
        $this->app->getSession()->remove('_permissions');

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/roles');
    }

    /**
     * POST /admin/roles/{id}/delete — delete a non-system role.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $roleId = (int) $vars['id'];
        $role = $this->app->getDb()->fetchOne(
            "SELECT * FROM roles WHERE id = :id",
            ['id' => $roleId]
        );

        if ($role === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        if ((int) $role['is_system'] === 1) {
            $this->flash('error', $this->t('permissions.cannot_delete_system'));
            return $this->redirect('/admin/roles');
        }

        // Check for active assignments
        $activeCount = (int) $this->app->getDb()->fetchColumn(
            "SELECT COUNT(*) FROM role_assignments WHERE role_id = :id AND (end_date IS NULL OR end_date >= CURDATE())",
            ['id' => $roleId]
        );

        if ($activeCount > 0) {
            $this->flash('error', $this->t('permissions.cannot_delete_active', ['count' => (string) $activeCount]));
            return $this->redirect('/admin/roles');
        }

        $this->app->getDb()->delete('roles', ['id' => $roleId]);
        $this->flash('success', $this->t('flash.deleted'));
        return $this->redirect('/admin/roles');
    }

    /**
     * Extract role data from the request.
     */
    private function extractRoleData(Request $request): array
    {
        $permissions = [];
        $permKeys = $request->getParam('permissions', []);
        if (is_array($permKeys)) {
            foreach ($permKeys as $key) {
                $permissions[$key] = true;
            }
        }

        return [
            'name' => trim((string) $this->getParam('name', '')),
            'description' => trim((string) $this->getParam('description', '')),
            'permissions' => $permissions,
            'can_publish_events' => (bool) $this->getParam('can_publish_events', false),
            'can_access_medical' => (bool) $this->getParam('can_access_medical', false),
            'can_access_financial' => (bool) $this->getParam('can_access_financial', false),
        ];
    }

    /**
     * Get all available module permissions for the role form.
     *
     * @return array<string, string> Permission key => description
     */
    private function getModulePermissions(): array
    {
        return $this->app->getModuleRegistry()->getPermissionDefinitions();
    }

    /**
     * Translate a key using the app's i18n service.
     */
    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

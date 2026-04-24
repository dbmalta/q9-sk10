<?php

declare(strict_types=1);

namespace AppCore\Modules\Permissions\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Permissions\Services\RoleService;

class RoleController extends Controller
{
    private RoleService $roles;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->roles = new RoleService($app->getDb());
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.read');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@permissions/roles/index.html.twig', [
            'roles' => $this->roles->all(),
        ]);
    }

    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@permissions/roles/form.html.twig', [
            'role'        => null,
            'definitions' => $this->app->getModuleRegistry()->getPermissionDefinitions(),
        ]);
    }

    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->roles->create(
            (string) $this->getParam('name', ''),
            (string) $this->getParam('description', ''),
            $this->extractPermissions($request)
        );

        if (!$result['success']) {
            foreach ($result['errors'] as $err) {
                $this->flash('error', $err);
            }
            return $this->redirect('/admin/roles/create');
        }

        $this->flash('success', $this->t('permissions.role_created'));
        return $this->redirect('/admin/roles');
    }

    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }

        $role = $this->roles->find((int) $vars['id']);
        if ($role === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }
        $role['permissions_decoded'] = json_decode((string) $role['permissions'], true) ?? [];

        return $this->render('@permissions/roles/form.html.twig', [
            'role'        => $role,
            'definitions' => $this->app->getModuleRegistry()->getPermissionDefinitions(),
        ]);
    }

    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->roles->update(
            (int) $vars['id'],
            (string) $this->getParam('name', ''),
            (string) $this->getParam('description', ''),
            $this->extractPermissions($request)
        );

        if (!$result['success']) {
            foreach ($result['errors'] as $err) {
                $this->flash('error', $err);
            }
            return $this->redirect('/admin/roles/' . (int) $vars['id'] . '/edit');
        }

        $this->flash('success', $this->t('permissions.role_updated'));
        return $this->redirect('/admin/roles');
    }

    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }

        if ($this->roles->delete((int) $vars['id'])) {
            $this->flash('success', $this->t('permissions.role_deleted'));
        } else {
            $this->flash('error', $this->t('permissions.role_delete_failed'));
        }
        return $this->redirect('/admin/roles');
    }

    /**
     * @return array<string, bool>
     */
    private function extractPermissions(Request $request): array
    {
        $submitted = $request->getParam('permissions', []);
        if (!is_array($submitted)) {
            return [];
        }
        $out = [];
        foreach ($submitted as $key => $value) {
            if ($value) {
                $out[(string) $key] = true;
            }
        }
        return $out;
    }
}

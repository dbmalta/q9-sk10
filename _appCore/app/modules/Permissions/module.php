<?php

declare(strict_types=1);

use AppCore\Modules\Permissions\Controllers\RoleController;
use AppCore\Modules\Permissions\Controllers\AssignmentController;

return [
    'id'      => 'permissions',
    'name'    => 'Permissions',
    'version' => trim((string) @file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),

    'nav' => [
        'label'         => 'nav.roles',
        'icon'          => 'bi-shield-lock',
        'route'         => '/admin/roles',
        'group'         => 'admin',
        'order'         => 50,
        'requires_auth' => true,
    ],

    'routes' => function (\AppCore\Core\Router $router): void {
        $router->get('/admin/roles',               [RoleController::class, 'index'],  'permissions.roles');
        $router->get('/admin/roles/create',        [RoleController::class, 'create'], 'permissions.roles.create');
        $router->post('/admin/roles',              [RoleController::class, 'store'],  'permissions.roles.store');
        $router->get('/admin/roles/{id:\d+}/edit', [RoleController::class, 'edit'],   'permissions.roles.edit');
        $router->post('/admin/roles/{id:\d+}',     [RoleController::class, 'update'], 'permissions.roles.update');
        $router->post('/admin/roles/{id:\d+}/delete', [RoleController::class, 'delete'], 'permissions.roles.delete');

        $router->get('/admin/roles/assignments/{userId:\d+}',  [AssignmentController::class, 'forUser'], 'permissions.assignments');
        $router->post('/admin/roles/assignments/{userId:\d+}', [AssignmentController::class, 'store'],   'permissions.assignments.store');
        $router->post('/admin/roles/assignments/{id:\d+}/end', [AssignmentController::class, 'end'],     'permissions.assignments.end');
    },

    'permissions' => [
        'roles.read'  => 'View roles and assignments',
        'roles.write' => 'Create, edit, and delete roles; manage assignments',
    ],
];

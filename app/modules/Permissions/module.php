<?php

declare(strict_types=1);

use App\Modules\Permissions\Controllers\RolesController;
use App\Modules\Permissions\Controllers\AssignmentsController;

return [
    'id' => 'permissions',
    'name' => 'Permissions',
    'version' => '1.0.0',

    'nav' => [
        'label' => 'permissions.roles',
        'icon' => 'bi-shield-lock',
        'route' => '/admin/roles',
        'group' => 'administration',
        'order' => 20,
        'requires_auth' => true,
    ],

    'routes' => function (\App\Core\Router $router): void {
        // Roles CRUD
        $router->get('/admin/roles', [RolesController::class, 'index'], 'permissions.roles');
        $router->get('/admin/roles/create', [RolesController::class, 'create'], 'permissions.roles.create');
        $router->post('/admin/roles', [RolesController::class, 'store'], 'permissions.roles.store');
        $router->get('/admin/roles/{id:\d+}/edit', [RolesController::class, 'edit'], 'permissions.roles.edit');
        $router->post('/admin/roles/{id:\d+}', [RolesController::class, 'update'], 'permissions.roles.update');
        $router->post('/admin/roles/{id:\d+}/delete', [RolesController::class, 'delete'], 'permissions.roles.delete');

        // Assignments
        $router->get('/admin/roles/assignments/{userId:\d+}', [AssignmentsController::class, 'forUser'], 'permissions.assignments');
        $router->post('/admin/roles/assignments/{userId:\d+}', [AssignmentsController::class, 'store'], 'permissions.assignments.store');
        $router->post('/admin/roles/assignments/{id:\d+}/end', [AssignmentsController::class, 'end'], 'permissions.assignments.end');
    },

    'permissions' => [
        'roles.read' => 'View roles and assignments',
        'roles.write' => 'Create, edit, and delete roles; manage assignments',
    ],
];

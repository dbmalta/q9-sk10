<?php

declare(strict_types=1);

use App\Modules\OrgStructure\Controllers\OrgController;
use App\Modules\OrgStructure\Controllers\LevelTypesController;

return [
    'id' => 'org_structure',
    'name' => 'Org Structure',
    'version' => '0.1.6',

    'nav' => [
        'label' => 'nav.org_structure',
        'icon' => 'bi-diagram-3',
        'route' => '/admin/org',
        'group' => 'administration',
        'order' => 10,
        'requires_auth' => true,
    ],

    'routes' => function (\App\Core\Router $router): void {
        // Tree view
        $router->get('/admin/org', [OrgController::class, 'index'], 'org.index');

        // Node CRUD
        $router->get('/admin/org/nodes/create', [OrgController::class, 'create'], 'org.nodes.create');
        $router->post('/admin/org/nodes', [OrgController::class, 'store'], 'org.nodes.store');
        $router->get('/admin/org/nodes/{id:\d+}', [OrgController::class, 'show'], 'org.nodes.show');
        $router->get('/admin/org/nodes/{id:\d+}/edit', [OrgController::class, 'edit'], 'org.nodes.edit');
        $router->post('/admin/org/nodes/{id:\d+}', [OrgController::class, 'update'], 'org.nodes.update');
        $router->post('/admin/org/nodes/{id:\d+}/delete', [OrgController::class, 'delete'], 'org.nodes.delete');

        // Teams
        $router->post('/admin/org/nodes/{id:\d+}/teams', [OrgController::class, 'storeTeam'], 'org.teams.store');
        $router->post('/admin/org/teams/{id:\d+}/delete', [OrgController::class, 'deleteTeam'], 'org.teams.delete');

        // Level types
        $router->get('/admin/org/levels', [LevelTypesController::class, 'index'], 'org.levels');
        $router->post('/admin/org/levels', [LevelTypesController::class, 'store'], 'org.levels.store');
        $router->post('/admin/org/levels/{id:\d+}', [LevelTypesController::class, 'update'], 'org.levels.update');
        $router->post('/admin/org/levels/{id:\d+}/delete', [LevelTypesController::class, 'delete'], 'org.levels.delete');
    },

    'permissions' => [
        'org_structure.read' => 'View the organisational structure',
        'org_structure.write' => 'Create, edit, and delete org nodes, teams, and level types',
    ],
];

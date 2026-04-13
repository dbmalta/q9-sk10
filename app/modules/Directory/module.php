<?php

declare(strict_types=1);

use App\Modules\Directory\Controllers\DirectoryController;

/**
 * Directory module definition.
 *
 * Visual organogram showing the org structure with key role holders,
 * and a searchable flat contact directory.
 */
return [
    'id' => 'directory',
    'name' => 'Directory',
    'version' => '1.0.0',

    'nav' => [
        [
            'label' => 'nav.directory',
            'icon' => 'bi-diagram-3',
            'route' => '/directory',
            'group' => 'main',
            'order' => 50,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\App\Core\Router $router): void {
        $router->get('/directory', [DirectoryController::class, 'organogram'], 'directory.organogram');
        $router->get('/directory/contacts', [DirectoryController::class, 'contacts'], 'directory.contacts');
    },

    'permissions' => [
        'directory.read' => 'View the organisation directory and contact list',
    ],
];

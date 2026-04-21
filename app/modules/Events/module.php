<?php

declare(strict_types=1);

use App\Modules\Events\Controllers\EventController;
use App\Modules\Events\Controllers\ICalController;

return [
    'id' => 'events',
    'name' => 'Events',
    'version' => trim(@file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),

    'nav' => [
        [
            'label' => 'nav.events',
            'icon' => 'bi-calendar-event',
            'route' => '/events',
            'group' => 'communications',
            'order' => 10,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\App\Core\Router $router): void {
        // Public calendar and event views
        $router->get('/events', [EventController::class, 'calendar'], 'events.calendar');
        $router->get('/events/ical', [ICalController::class, 'manage'], 'events.ical.manage');
        $router->get('/events/{id:\d+}', [EventController::class, 'show'], 'events.show');

        // iCal feed (unauthenticated, token-based)
        $router->get('/ical/{token}', [ICalController::class, 'feed'], 'events.ical.feed');

        // iCal token management
        $router->post('/events/ical/generate', [ICalController::class, 'generate'], 'events.ical.generate');

        // Admin event management
        $router->get('/admin/events', [EventController::class, 'adminIndex'], 'events.admin_index');
        $router->get('/admin/events/create', [EventController::class, 'create'], 'events.create');
        $router->post('/admin/events', [EventController::class, 'store'], 'events.store');
        $router->get('/admin/events/{id:\d+}/edit', [EventController::class, 'edit'], 'events.edit');
        $router->post('/admin/events/{id:\d+}', [EventController::class, 'update'], 'events.update');
        $router->post('/admin/events/{id:\d+}/publish', [EventController::class, 'publish'], 'events.publish');
        $router->post('/admin/events/{id:\d+}/unpublish', [EventController::class, 'unpublish'], 'events.unpublish');
        $router->post('/admin/events/{id:\d+}/delete', [EventController::class, 'delete'], 'events.delete');
    },

    'permissions' => [
        'events.read' => 'View calendar events',
        'events.write' => 'Create, edit, and manage calendar events',
    ],
];

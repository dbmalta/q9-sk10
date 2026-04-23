<?php

declare(strict_types=1);

use App\Modules\Core\Controllers\ViewContextController;

return [
    'id' => 'core',
    'name' => 'Core',
    'version' => trim(@file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),
    'system' => true,

    'routes' => function (\App\Core\Router $router): void {
        $router->post('/context/mode',  [ViewContextController::class, 'setMode'],  'core.context.mode');
        $router->post('/context/scope', [ViewContextController::class, 'setScope'], 'core.context.scope');
    },

    'permissions' => [],
];

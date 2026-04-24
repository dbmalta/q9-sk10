<?php

declare(strict_types=1);

use AppCore\Modules\Auth\Controllers\AuthController;

return [
    'id'      => 'auth',
    'name'    => 'Authentication',
    'version' => trim((string) @file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),
    'system'  => true,

    'routes' => function (\AppCore\Core\Router $router): void {
        $router->get('/login',  [AuthController::class, 'showLogin'],  'auth.login');
        $router->post('/login', [AuthController::class, 'processLogin'], 'auth.login.process');

        $router->get('/login/mfa',  [AuthController::class, 'showMfa'],    'auth.mfa');
        $router->post('/login/mfa', [AuthController::class, 'processMfa'], 'auth.mfa.process');

        $router->get('/logout',  [AuthController::class, 'logout'], 'auth.logout.get');
        $router->post('/logout', [AuthController::class, 'logout'], 'auth.logout');

        $router->get('/account', [AuthController::class, 'account'], 'auth.account');

        $router->get('/forgot-password',  [AuthController::class, 'showForgotPassword'],    'auth.forgot');
        $router->post('/forgot-password', [AuthController::class, 'processForgotPassword'], 'auth.forgot.process');

        $router->get('/reset-password/{token:[a-f0-9]{64}}',  [AuthController::class, 'showResetPassword'],    'auth.reset');
        $router->post('/reset-password/{token:[a-f0-9]{64}}', [AuthController::class, 'processResetPassword'], 'auth.reset.process');
    },

    'permissions' => [],
];

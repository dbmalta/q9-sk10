<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthController;

return [
    'id' => 'auth',
    'name' => 'Authentication',
    'version' => '0.1.9',
    'system' => true,

    // No nav entry — auth is a system module
    // 'nav' => ...

    'routes' => function (\App\Core\Router $router): void {
        // Login
        $router->get('/login', [AuthController::class, 'showLogin'], 'auth.login');
        $router->post('/login', [AuthController::class, 'processLogin'], 'auth.login.process');

        // MFA verification (second step of login)
        $router->get('/login/mfa', [AuthController::class, 'showMfa'], 'auth.mfa');
        $router->post('/login/mfa', [AuthController::class, 'processMfa'], 'auth.mfa.process');

        // Logout
        $router->get('/logout', [AuthController::class, 'logout'], 'auth.logout.get');
        $router->post('/logout', [AuthController::class, 'logout'], 'auth.logout');

        // Forgot password
        $router->get('/forgot-password', [AuthController::class, 'showForgotPassword'], 'auth.forgot');
        $router->post('/forgot-password', [AuthController::class, 'processForgotPassword'], 'auth.forgot.process');

        // Reset password
        $router->get('/reset-password/{token:[a-f0-9]{64}}', [AuthController::class, 'showResetPassword'], 'auth.reset');
        $router->post('/reset-password/{token:[a-f0-9]{64}}', [AuthController::class, 'processResetPassword'], 'auth.reset.process');
    },

    'permissions' => [],
];

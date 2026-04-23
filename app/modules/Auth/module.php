<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthController;

return [
    'id' => 'auth',
    'name' => 'Authentication',
    'version' => trim(@file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),
    'system' => true,

    // Member-mode nav stub — keeps the sidebar useful until the full member
    // portal lands in Phase 5+. Points at /account which already exists.
    // No nav entry — member-mode nav now lives on the Members module so it
    // can share /me/* routes with the member dashboard.

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

        // Account / profile landing page
        $router->get('/account', [AuthController::class, 'account'], 'auth.account');

        // Forgot password
        $router->get('/forgot-password', [AuthController::class, 'showForgotPassword'], 'auth.forgot');
        $router->post('/forgot-password', [AuthController::class, 'processForgotPassword'], 'auth.forgot.process');

        // Reset password
        $router->get('/reset-password/{token:[a-f0-9]{64}}', [AuthController::class, 'showResetPassword'], 'auth.reset');
        $router->post('/reset-password/{token:[a-f0-9]{64}}', [AuthController::class, 'processResetPassword'], 'auth.reset.process');
    },

    'permissions' => [],
];

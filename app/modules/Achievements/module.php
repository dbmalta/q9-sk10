<?php

declare(strict_types=1);

use App\Modules\Achievements\Controllers\AchievementController;

/**
 * Achievements module definition.
 *
 * Achievement and training definition management, plus awarding
 * and revoking achievements on individual member profiles.
 */
return [
    'id' => 'achievements',
    'name' => 'Achievements',
    'version' => '1.0.0',

    'nav' => [
        [
            'label' => 'nav.achievements',
            'icon' => 'bi-trophy',
            'route' => '/admin/achievements',
            'group' => 'engagement',
            'order' => 40,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\App\Core\Router $router): void {
        // Admin definition CRUD
        $router->get('/admin/achievements', [AchievementController::class, 'index'], 'achievements.index');
        $router->get('/admin/achievements/create', [AchievementController::class, 'create'], 'achievements.create');
        $router->post('/admin/achievements', [AchievementController::class, 'store'], 'achievements.store');
        $router->get('/admin/achievements/{id:\d+}/edit', [AchievementController::class, 'edit'], 'achievements.edit');
        $router->post('/admin/achievements/{id:\d+}', [AchievementController::class, 'update'], 'achievements.update');
        $router->post('/admin/achievements/{id:\d+}/deactivate', [AchievementController::class, 'deactivate'], 'achievements.deactivate');
        $router->post('/admin/achievements/{id:\d+}/activate', [AchievementController::class, 'activate'], 'achievements.activate');

        // Member profile: award and revoke
        $router->post('/members/{memberId:\d+}/achievements', [AchievementController::class, 'award'], 'achievements.award');
        $router->post('/members/{memberId:\d+}/achievements/{id:\d+}/revoke', [AchievementController::class, 'revoke'], 'achievements.revoke');
    },

    'permissions' => [
        'achievements.read' => 'View achievement and training definitions',
        'achievements.write' => 'Create, edit, and manage achievements and training records',
    ],
];

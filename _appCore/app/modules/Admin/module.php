<?php

declare(strict_types=1);

use AppCore\Modules\Admin\Controllers\AuditController;
use AppCore\Modules\Admin\Controllers\BackupController;
use AppCore\Modules\Admin\Controllers\DashboardController;
use AppCore\Modules\Admin\Controllers\ExportController;
use AppCore\Modules\Admin\Controllers\LanguageController;
use AppCore\Modules\Admin\Controllers\LogController;
use AppCore\Modules\Admin\Controllers\MonitoringController;
use AppCore\Modules\Admin\Controllers\NoticeController;
use AppCore\Modules\Admin\Controllers\SettingsController;
use AppCore\Modules\Admin\Controllers\TermsController;
use AppCore\Modules\Admin\Controllers\UpdateController;

return [
    'id'      => 'admin',
    'name'    => 'Administration',
    'version' => trim((string) @file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),

    'nav' => [
        [
            'label' => 'nav.dashboard',
            'icon'  => 'bi-speedometer2',
            'route' => '/admin/dashboard',
            'group' => '_top',
            'order' => 10,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.notices',
            'icon'  => 'bi-bell',
            'route' => '/admin/notices',
            'group' => 'admin',
            'order' => 10,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.terms',
            'icon'  => 'bi-file-earmark-text',
            'route' => '/admin/terms',
            'group' => 'admin',
            'order' => 20,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.backups',
            'icon'  => 'bi-archive',
            'route' => '/admin/backups',
            'group' => 'admin',
            'order' => 30,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.audit_log',
            'icon'  => 'bi-shield-check',
            'route' => '/admin/audit',
            'group' => 'admin',
            'order' => 40,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.logs',
            'icon'  => 'bi-terminal',
            'route' => '/admin/logs',
            'group' => 'admin',
            'order' => 60,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.export',
            'icon'  => 'bi-download',
            'route' => '/admin/export',
            'group' => 'admin',
            'order' => 70,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.settings',
            'icon'  => 'bi-gear',
            'route' => '/admin/settings',
            'group' => 'config',
            'order' => 10,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.languages',
            'icon'  => 'bi-translate',
            'route' => '/admin/languages',
            'group' => 'config',
            'order' => 20,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.updates',
            'icon'  => 'bi-arrow-repeat',
            'route' => '/admin/updates',
            'group' => 'config',
            'order' => 30,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\AppCore\Core\Router $router): void {
        // Public monitoring endpoints
        $router->get('/api/health', [MonitoringController::class, 'health'], 'api.health');
        $router->get('/api/logs',   [MonitoringController::class, 'logs'],   'api.logs');

        // Home
        $router->get('/', [DashboardController::class, 'root'], 'home');

        // Dashboard
        $router->get('/admin/dashboard', [DashboardController::class, 'index'], 'admin.dashboard');

        // Settings
        $router->get('/admin/settings',  [SettingsController::class, 'index'],  'admin.settings');
        $router->post('/admin/settings', [SettingsController::class, 'update'], 'admin.settings.update');

        // Audit
        $router->get('/admin/audit', [AuditController::class, 'index'], 'admin.audit');

        // Logs
        $router->get('/admin/logs',              [LogController::class, 'index'], 'admin.logs');
        $router->post('/admin/logs/{type}/clear', [LogController::class, 'clear'], 'admin.logs.clear');

        // Backups
        $router->get('/admin/backups',                     [BackupController::class, 'index'],    'admin.backups');
        $router->post('/admin/backups/create',             [BackupController::class, 'create'],   'admin.backups.create');
        $router->get('/admin/backups/{filename}',          [BackupController::class, 'download'], 'admin.backups.download');
        $router->post('/admin/backups/{filename}/delete',  [BackupController::class, 'delete'],   'admin.backups.delete');

        // Export (generic: users CSV)
        $router->get('/admin/export',            [ExportController::class, 'index'],     'admin.export');
        $router->get('/admin/export/users/csv',  [ExportController::class, 'usersCsv'],  'admin.export.users_csv');

        // Notices
        $router->get('/admin/notices',                   [NoticeController::class, 'index'],       'admin.notices');
        $router->get('/admin/notices/create',            [NoticeController::class, 'create'],      'admin.notices.create');
        $router->post('/admin/notices',                  [NoticeController::class, 'store'],       'admin.notices.store');
        $router->get('/admin/notices/{id:\d+}/edit',     [NoticeController::class, 'edit'],        'admin.notices.edit');
        $router->post('/admin/notices/{id:\d+}',         [NoticeController::class, 'update'],      'admin.notices.update');
        $router->post('/admin/notices/{id:\d+}/deactivate', [NoticeController::class, 'deactivate'], 'admin.notices.deactivate');

        // Terms
        $router->get('/admin/terms',                       [TermsController::class, 'index'],   'admin.terms');
        $router->get('/admin/terms/create',                [TermsController::class, 'create'],  'admin.terms.create');
        $router->post('/admin/terms',                      [TermsController::class, 'store'],   'admin.terms.store');
        $router->get('/admin/terms/{id:\d+}/edit',         [TermsController::class, 'edit'],    'admin.terms.edit');
        $router->post('/admin/terms/{id:\d+}',             [TermsController::class, 'update'],  'admin.terms.update');
        $router->post('/admin/terms/{id:\d+}/publish',     [TermsController::class, 'publish'], 'admin.terms.publish');

        // Language switching + management
        $router->post('/language/switch',                    [LanguageController::class, 'switchLanguage'], 'language.switch');
        $router->get('/admin/languages',                     [LanguageController::class, 'index'],        'admin.languages');
        $router->get('/admin/languages/{code}/strings',      [LanguageController::class, 'strings'],      'admin.languages.strings');
        $router->post('/admin/languages/{code}/overrides',   [LanguageController::class, 'saveOverride'], 'admin.languages.save_override');
        $router->post('/admin/languages/{code}/activate',    [LanguageController::class, 'activate'],     'admin.languages.activate');
        $router->post('/admin/languages/{code}/deactivate',  [LanguageController::class, 'deactivate'],   'admin.languages.deactivate');

        // Updater
        $router->get('/admin/updates',         [UpdateController::class, 'index'],       'admin.updates');
        $router->get('/admin/updates/check',   [UpdateController::class, 'check'],       'admin.updates.check');
        $router->post('/admin/updates/apply',  [UpdateController::class, 'apply'],       'admin.updates.apply');
    },

    'permissions' => [
        'admin.dashboard'  => 'View admin dashboard',
        'admin.settings'   => 'Manage system settings',
        'admin.audit'      => 'View audit log',
        'admin.logs'       => 'View and clear system logs',
        'admin.backup'     => 'Create and manage backups',
        'admin.export'     => 'Export data',
        'admin.notices'    => 'Manage broadcast notices',
        'admin.terms'      => 'Manage terms and conditions',
        'admin.languages'  => 'Manage languages and translations',
        'admin.monitoring' => 'Access monitoring API',
        'admin.updates'    => 'Check for and apply updates',
    ],
];

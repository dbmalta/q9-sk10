<?php

declare(strict_types=1);

use App\Modules\Admin\Controllers\DashboardController;
use App\Modules\Admin\Controllers\ReportController;
use App\Modules\Admin\Controllers\TermsController;
use App\Modules\Admin\Controllers\NoticeController;
use App\Modules\Admin\Controllers\SettingsController;
use App\Modules\Admin\Controllers\AuditController;
use App\Modules\Admin\Controllers\LogController;
use App\Modules\Admin\Controllers\ExportController;
use App\Modules\Admin\Controllers\BackupController;
use App\Modules\Admin\Controllers\LanguageController;
use App\Modules\Admin\Controllers\MonitoringController;
use App\Modules\Admin\Controllers\UpdateController;
use App\Modules\Admin\Controllers\SearchController;

return [
    'id' => 'admin',
    'name' => 'Administration',
    'version' => trim(@file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),

    'nav' => [
        [
            'label' => 'nav.dashboard',
            'icon' => 'bi-speedometer2',
            'route' => '/admin/dashboard',
            'group' => '_top',
            'order' => 10,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.reports',
            'icon' => 'bi-graph-up',
            'route' => '/admin/reports',
            'group' => 'reporting',
            'order' => 10,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.notices',
            'icon' => 'bi-bell',
            'route' => '/admin/notices',
            'group' => 'communications',
            'order' => 40,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.terms',
            'icon' => 'bi-file-earmark-text',
            'route' => '/admin/terms',
            'group' => 'communications',
            'order' => 50,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.backups',
            'icon' => 'bi-archive',
            'route' => '/admin/backups',
            'group' => 'admin',
            'order' => 10,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.audit_log',
            'icon' => 'bi-shield-check',
            'route' => '/admin/audit',
            'group' => 'admin',
            'order' => 20,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.logs',
            'icon' => 'bi-terminal',
            'route' => '/admin/logs',
            'group' => 'admin',
            'order' => 30,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.export',
            'icon' => 'bi-download',
            'route' => '/admin/export',
            'group' => 'admin',
            'order' => 40,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.settings',
            'icon' => 'bi-gear',
            'route' => '/admin/settings',
            'group' => 'config',
            'order' => 10,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.languages',
            'icon' => 'bi-translate',
            'route' => '/admin/languages',
            'group' => 'config',
            'order' => 30,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.updates',
            'icon' => 'bi-arrow-repeat',
            'route' => '/admin/updates',
            'group' => 'config',
            'order' => 50,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\App\Core\Router $router): void {
        // Monitoring API (public health + API-key-protected logs)
        $router->get('/api/health', [MonitoringController::class, 'health'], 'api.health');
        $router->get('/api/logs', [MonitoringController::class, 'logs'], 'api.logs');

        // Root redirect
        $router->get('/', [DashboardController::class, 'root'], 'home');

        // Global topbar search (HTMX partial)
        $router->get('/search', [SearchController::class, 'index'], 'search');

        // Dashboard
        $router->get('/admin/dashboard', [DashboardController::class, 'index'], 'admin.dashboard');

        // Reports
        $router->get('/admin/reports', [ReportController::class, 'index'], 'admin.reports');
        $router->get('/admin/reports/growth', [ReportController::class, 'growth'], 'admin.reports.growth');
        $router->get('/admin/reports/status-changes', [ReportController::class, 'statusChanges'], 'admin.reports.status_changes');
        $router->get('/admin/reports/export/members', [ReportController::class, 'exportMembers'], 'admin.reports.export_members');

        // Terms & Conditions
        $router->get('/admin/terms', [TermsController::class, 'index'], 'admin.terms');
        $router->get('/admin/terms/create', [TermsController::class, 'create'], 'admin.terms.create');
        $router->post('/admin/terms', [TermsController::class, 'store'], 'admin.terms.store');
        $router->get('/admin/terms/{id:\d+}', [TermsController::class, 'show'], 'admin.terms.show');
        $router->get('/admin/terms/{id:\d+}/edit', [TermsController::class, 'edit'], 'admin.terms.edit');
        $router->post('/admin/terms/{id:\d+}', [TermsController::class, 'update'], 'admin.terms.update');
        $router->post('/admin/terms/{id:\d+}/publish', [TermsController::class, 'publish'], 'admin.terms.publish');

        // Notices
        $router->get('/admin/notices', [NoticeController::class, 'index'], 'admin.notices');
        $router->get('/admin/notices/create', [NoticeController::class, 'create'], 'admin.notices.create');
        $router->post('/admin/notices', [NoticeController::class, 'store'], 'admin.notices.store');
        $router->get('/admin/notices/{id:\d+}/edit', [NoticeController::class, 'edit'], 'admin.notices.edit');
        $router->post('/admin/notices/{id:\d+}', [NoticeController::class, 'update'], 'admin.notices.update');
        $router->post('/admin/notices/{id:\d+}/deactivate', [NoticeController::class, 'deactivate'], 'admin.notices.deactivate');
        $router->get('/admin/notices/{id:\d+}/acknowledgements', [NoticeController::class, 'acknowledgements'], 'admin.notices.acknowledgements');

        // Settings
        $router->get('/admin/settings', [SettingsController::class, 'index'], 'admin.settings');
        $router->post('/admin/settings', [SettingsController::class, 'update'], 'admin.settings.update');

        // Audit log
        $router->get('/admin/audit', [AuditController::class, 'index'], 'admin.audit');
        $router->get('/admin/audit/{entityType}/{entityId:\d+}', [AuditController::class, 'entityTrail'], 'admin.audit.entity');

        // System logs
        $router->get('/admin/logs', [LogController::class, 'index'], 'admin.logs');
        $router->post('/admin/logs/{type}/clear', [LogController::class, 'clear'], 'admin.logs.clear');

        // Data export
        $router->get('/admin/export', [ExportController::class, 'index'], 'admin.export');
        $router->get('/admin/export/members/csv', [ExportController::class, 'membersCsv'], 'admin.export.members_csv');
        $router->get('/admin/export/members/xml', [ExportController::class, 'membersXml'], 'admin.export.members_xml');
        $router->get('/admin/export/settings', [ExportController::class, 'settingsJson'], 'admin.export.settings');
        $router->get('/my-data/export', [ExportController::class, 'myData'], 'admin.export.my_data');

        // Backups
        $router->get('/admin/backups', [BackupController::class, 'index'], 'admin.backups');
        $router->post('/admin/backups/create', [BackupController::class, 'create'], 'admin.backups.create');
        $router->get('/admin/backups/{filename}', [BackupController::class, 'download'], 'admin.backups.download');
        $router->post('/admin/backups/{filename}/delete', [BackupController::class, 'delete'], 'admin.backups.delete');

        // Updates
        $router->get('/admin/updates', [UpdateController::class, 'index'], 'admin.updates');
        $router->get('/admin/updates/check', [UpdateController::class, 'check'], 'admin.updates.check');
        $router->post('/admin/updates/download', [UpdateController::class, 'download'], 'admin.updates.download');

        // User-facing language switch (no admin permission required)
        $router->post('/language/switch', [LanguageController::class, 'switchLanguage'], 'language.switch');

        // Languages
        $router->get('/admin/languages', [LanguageController::class, 'index'], 'admin.languages');
        $router->get('/admin/languages/upload', [LanguageController::class, 'upload'], 'admin.languages.upload');
        $router->post('/admin/languages/upload', [LanguageController::class, 'processUpload'], 'admin.languages.process_upload');
        $router->get('/admin/languages/export-master', [LanguageController::class, 'exportMaster'], 'admin.languages.export_master');
        $router->get('/admin/languages/{code}/strings', [LanguageController::class, 'strings'], 'admin.languages.strings');
        $router->post('/admin/languages/{code}/overrides', [LanguageController::class, 'saveOverride'], 'admin.languages.save_override');
        $router->post('/admin/languages/{code}/overrides/clear', [LanguageController::class, 'clearOverride'], 'admin.languages.clear_override');
        $router->post('/admin/languages/{code}/default', [LanguageController::class, 'setDefault'], 'admin.languages.set_default');
        $router->post('/admin/languages/{code}/activate', [LanguageController::class, 'activate'], 'admin.languages.activate');
        $router->post('/admin/languages/{code}/deactivate', [LanguageController::class, 'deactivate'], 'admin.languages.deactivate');
    },

    'permissions' => [
        'admin.dashboard' => 'View admin dashboard',
        'admin.reports' => 'View and export reports',
        'admin.terms' => 'Manage terms and conditions',
        'admin.notices' => 'Manage system notices',
        'admin.settings' => 'Manage system settings',
        'admin.audit' => 'View audit log',
        'admin.logs' => 'View and clear system logs',
        'admin.export' => 'Export data',
        'admin.backup' => 'Create and manage backups',
        'admin.languages' => 'Manage languages and translations',
        'admin.updates' => 'Manage system updates',
        'admin.monitoring' => 'View monitoring API and health status',
    ],
];

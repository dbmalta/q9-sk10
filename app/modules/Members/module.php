<?php

declare(strict_types=1);

use App\Modules\Members\Controllers\MembersController;
use App\Modules\Members\Controllers\MemberApiController;
use App\Modules\Members\Controllers\CustomFieldsController;
use App\Modules\Members\Controllers\TimelineController;
use App\Modules\Members\Controllers\AttachmentController;
use App\Modules\Members\Controllers\MemberTabsController;
use App\Modules\Members\Controllers\RegistrationController;
use App\Modules\Members\Controllers\PublicRegistrationController;

return [
    'id' => 'members',
    'name' => 'Members',
    'version' => '1.0.0',

    'nav' => [
        [
            'label' => 'nav.members',
            'icon' => 'bi-people',
            'route' => '/members',
            'group' => 'main',
            'order' => 20,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.registrations',
            'icon' => 'bi-person-plus',
            'route' => '/admin/registrations',
            'group' => 'main',
            'order' => 25,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.custom_fields',
            'icon' => 'bi-sliders',
            'route' => '/admin/custom-fields',
            'group' => 'administration',
            'order' => 60,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\App\Core\Router $router): void {
        // Member list
        $router->get('/members', [MembersController::class, 'index'], 'members.index');

        // Pending changes (before /{id} routes to avoid conflict)
        $router->get('/members/pending-changes', [MembersController::class, 'pendingChanges'], 'members.pending_changes');
        $router->post('/members/pending-changes/{id:\d+}/review', [MembersController::class, 'reviewChange'], 'members.review_change');

        // Member CRUD
        $router->get('/members/create', [MembersController::class, 'create'], 'members.create');
        $router->post('/members', [MembersController::class, 'store'], 'members.store');
        $router->get('/members/{id:\d+}', [MembersController::class, 'view'], 'members.view');
        $router->get('/members/{id:\d+}/edit', [MembersController::class, 'edit'], 'members.edit');
        $router->post('/members/{id:\d+}', [MembersController::class, 'update'], 'members.update');
        $router->post('/members/{id:\d+}/status', [MembersController::class, 'changeStatus'], 'members.change_status');

        // HTMX API partials
        $router->get('/members/api/search', [MemberApiController::class, 'searchResults'], 'members.api.search');
        $router->get('/members/api/{id:\d+}/card', [MemberApiController::class, 'memberCard'], 'members.api.card');
        $router->get('/members/api/{id:\d+}/status-badge', [MemberApiController::class, 'statusBadge'], 'members.api.status_badge');

        // Profile tabs (HTMX lazy-loaded)
        $router->get('/members/{id:\d+}/tab/personal', [MemberTabsController::class, 'personal'], 'members.tab.personal');
        $router->get('/members/{id:\d+}/tab/contact', [MemberTabsController::class, 'contact'], 'members.tab.contact');
        $router->get('/members/{id:\d+}/tab/medical', [MemberTabsController::class, 'medical'], 'members.tab.medical');
        $router->get('/members/{id:\d+}/tab/roles', [MemberTabsController::class, 'roles'], 'members.tab.roles');
        $router->get('/members/{id:\d+}/tab/timeline', [MemberTabsController::class, 'timeline'], 'members.tab.timeline');
        $router->get('/members/{id:\d+}/tab/documents', [MemberTabsController::class, 'documents'], 'members.tab.documents');
        $router->get('/members/{id:\d+}/tab/additional', [MemberTabsController::class, 'additional'], 'members.tab.additional');

        // Timeline entries
        $router->post('/members/{id:\d+}/timeline', [TimelineController::class, 'store'], 'members.timeline.store');
        $router->post('/members/{id:\d+}/timeline/{entryId:\d+}/delete', [TimelineController::class, 'delete'], 'members.timeline.delete');

        // Attachments
        $router->post('/members/{id:\d+}/attachments', [AttachmentController::class, 'upload'], 'members.attachments.upload');
        $router->get('/members/{id:\d+}/attachments/{attachmentId:\d+}/download', [AttachmentController::class, 'download'], 'members.attachments.download');
        $router->post('/members/{id:\d+}/attachments/{attachmentId:\d+}/delete', [AttachmentController::class, 'delete'], 'members.attachments.delete');

        // Public registration (no auth required)
        $router->get('/register', [PublicRegistrationController::class, 'showForm'], 'register.form');
        $router->post('/register', [PublicRegistrationController::class, 'register'], 'register.submit');
        $router->get('/register/invite/{token}', [PublicRegistrationController::class, 'showInvitationForm'], 'register.invite');
        $router->post('/register/invite/{token}', [PublicRegistrationController::class, 'processInvitation'], 'register.invite.submit');
        $router->get('/waiting-list', [PublicRegistrationController::class, 'showWaitingListForm'], 'waiting_list.form');
        $router->post('/waiting-list', [PublicRegistrationController::class, 'submitWaitingList'], 'waiting_list.submit');

        // Admin registration management
        $router->get('/admin/registrations', [RegistrationController::class, 'pending'], 'registrations.pending');
        $router->post('/admin/registrations/{id:\d+}/approve', [RegistrationController::class, 'approve'], 'registrations.approve');
        $router->post('/admin/registrations/{id:\d+}/reject', [RegistrationController::class, 'reject'], 'registrations.reject');

        // Invitations
        $router->get('/admin/invitations', [RegistrationController::class, 'invitations'], 'invitations.index');
        $router->post('/admin/invitations', [RegistrationController::class, 'createInvitation'], 'invitations.create');

        // Bulk import
        $router->get('/admin/bulk-import', [RegistrationController::class, 'bulkImportForm'], 'bulk_import.form');
        $router->get('/admin/bulk-import/template', [RegistrationController::class, 'downloadTemplate'], 'bulk_import.template');
        $router->post('/admin/bulk-import/upload', [RegistrationController::class, 'bulkImportUpload'], 'bulk_import.upload');
        $router->post('/admin/bulk-import/confirm', [RegistrationController::class, 'bulkImportConfirm'], 'bulk_import.confirm');

        // Waiting list admin
        $router->get('/admin/waiting-list', [RegistrationController::class, 'waitingList'], 'waiting_list.index');
        $router->post('/admin/waiting-list/{id:\d+}/status', [RegistrationController::class, 'waitingListStatus'], 'waiting_list.status');
        $router->post('/admin/waiting-list/{id:\d+}/convert', [RegistrationController::class, 'waitingListConvert'], 'waiting_list.convert');
        $router->post('/admin/waiting-list/{id:\d+}/delete', [RegistrationController::class, 'waitingListDelete'], 'waiting_list.delete');
        $router->post('/admin/waiting-list/reorder', [RegistrationController::class, 'waitingListReorder'], 'waiting_list.reorder');

        // Custom fields admin
        $router->get('/admin/custom-fields', [CustomFieldsController::class, 'index'], 'custom_fields.index');
        $router->get('/admin/custom-fields/create', [CustomFieldsController::class, 'create'], 'custom_fields.create');
        $router->post('/admin/custom-fields/create', [CustomFieldsController::class, 'store'], 'custom_fields.store');
        $router->get('/admin/custom-fields/{id:\d+}/edit', [CustomFieldsController::class, 'edit'], 'custom_fields.edit');
        $router->post('/admin/custom-fields/{id:\d+}/edit', [CustomFieldsController::class, 'update'], 'custom_fields.update');
        $router->post('/admin/custom-fields/{id:\d+}/deactivate', [CustomFieldsController::class, 'deactivate'], 'custom_fields.deactivate');
        $router->post('/admin/custom-fields/{id:\d+}/activate', [CustomFieldsController::class, 'activate'], 'custom_fields.activate');
        $router->post('/admin/custom-fields/reorder', [CustomFieldsController::class, 'reorder'], 'custom_fields.reorder');
    },

    'permissions' => [
        'members.read' => 'View member records',
        'members.write' => 'Create, edit, and manage member records',
        'custom_fields.write' => 'Manage custom field definitions',
        'registrations.manage' => 'Manage registrations, invitations, imports, and waiting list',
    ],
];

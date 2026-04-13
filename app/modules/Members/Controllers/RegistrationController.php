<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\RegistrationService;
use App\Modules\Members\Services\BulkImportService;
use App\Modules\Members\Services\WaitingListService;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Admin registration management controller.
 *
 * Handles: pending registration review (approve/reject), invitation
 * management, bulk CSV import, and waiting list administration.
 */
class RegistrationController extends Controller
{
    private RegistrationService $registrationService;
    private BulkImportService $bulkImportService;
    private WaitingListService $waitingListService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->registrationService = new RegistrationService($app->getDb());
        $this->bulkImportService = new BulkImportService($app->getDb());
        $this->waitingListService = new WaitingListService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    // ── Pending Registrations ────────────────────────────────────────

    /**
     * GET /admin/registrations — list pending registrations.
     */
    public function pending(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $scopeNodeIds = $this->app->getPermissionResolver()->getScopeNodeIds();
        $registrations = $this->registrationService->getPendingRegistrations($scopeNodeIds);

        return $this->render('@members/registration/pending.html.twig', [
            'registrations' => $registrations,
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $this->t('registration.pending')],
            ],
        ]);
    }

    /**
     * POST /admin/registrations/{id}/approve — approve a pending registration.
     */
    public function approve(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $memberId = (int) $vars['id'];
        $userId = (int) $this->app->getSession()->get('user')['id'];

        try {
            $this->registrationService->approveRegistration($memberId, $userId);
            $this->flash('success', $this->t('registration.approved'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/registrations');
    }

    /**
     * POST /admin/registrations/{id}/reject — reject a pending registration.
     */
    public function reject(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $memberId = (int) $vars['id'];
        $userId = (int) $this->app->getSession()->get('user')['id'];
        $reason = trim((string) $request->getParam('reason', ''));

        try {
            $this->registrationService->rejectRegistration($memberId, $userId, $reason ?: null);
            $this->flash('success', $this->t('registration.rejected'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/registrations');
    }

    // ── Invitations ──────────────────────────────────────────────────

    /**
     * GET /admin/invitations — list invitations.
     */
    public function invitations(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $nodeId = (int) $request->getParam('node_id', 0);
        $showAll = (bool) $request->getParam('show_all', false);
        $nodes = $this->orgService->getTree();

        $invitations = [];
        if ($nodeId > 0) {
            $invitations = $this->registrationService->getInvitations($nodeId, !$showAll);
        }

        return $this->render('@members/registration/invitations.html.twig', [
            'invitations' => $invitations,
            'nodes' => $nodes,
            'selected_node_id' => $nodeId,
            'show_all' => $showAll,
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $this->t('registration.invitations')],
            ],
        ]);
    }

    /**
     * POST /admin/invitations — create a new invitation.
     */
    public function createInvitation(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $nodeId = (int) $request->getParam('node_id', 0);
        $email = trim((string) $request->getParam('email', ''));
        $userId = (int) $this->app->getSession()->get('user')['id'];

        if ($nodeId <= 0) {
            $this->flash('error', $this->t('registration.node_required'));
            return $this->redirect('/admin/invitations');
        }

        $token = $this->registrationService->createInvitation(
            $nodeId,
            $userId,
            $email ?: null
        );

        $this->flash('success', $this->t('registration.invitation_created'));
        return $this->redirect('/admin/invitations?node_id=' . $nodeId);
    }

    // ── Bulk Import ──────────────────────────────────────────────────

    /**
     * GET /admin/bulk-import — show the bulk import form.
     */
    public function bulkImportForm(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $nodes = $this->orgService->getTree();

        return $this->render('@members/registration/bulk_import.html.twig', [
            'nodes' => $nodes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $this->t('registration.bulk_import')],
            ],
        ]);
    }

    /**
     * GET /admin/bulk-import/template — download CSV template.
     */
    public function downloadTemplate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $nodeId = (int) $request->getParam('node_id', 0);
        $csv = $this->bulkImportService->generateTemplate($nodeId);

        $response = new Response(200, $csv);
        $response->setHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->setHeader('Content-Disposition', 'attachment; filename="member_import_template.csv"');
        return $response;
    }

    /**
     * POST /admin/bulk-import/upload — parse uploaded CSV and show preview.
     */
    public function bulkImportUpload(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $nodeId = (int) $request->getParam('node_id', 0);
        if ($nodeId <= 0) {
            $this->flash('error', $this->t('registration.node_required'));
            return $this->redirect('/admin/bulk-import');
        }

        // Handle file upload
        $file = $_FILES['csv_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', $this->t('registration.upload_failed'));
            return $this->redirect('/admin/bulk-import');
        }

        try {
            $result = $this->bulkImportService->parseUpload($nodeId, $file['tmp_name']);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/bulk-import');
        }

        // Store validated rows in session for the confirm step
        $this->app->getSession()->set('bulk_import_data', [
            'node_id' => $nodeId,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
        ]);

        $nodes = $this->orgService->getTree();

        return $this->render('@members/registration/bulk_import_preview.html.twig', [
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'node_id' => $nodeId,
            'nodes' => $nodes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $this->t('registration.bulk_import'), 'url' => '/admin/bulk-import'],
                ['label' => $this->t('registration.preview')],
            ],
        ]);
    }

    /**
     * POST /admin/bulk-import/confirm — execute the import.
     */
    public function bulkImportConfirm(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $importData = $this->app->getSession()->get('bulk_import_data');
        if (!$importData || empty($importData['valid'])) {
            $this->flash('error', $this->t('registration.no_import_data'));
            return $this->redirect('/admin/bulk-import');
        }

        $userId = (int) $this->app->getSession()->get('user')['id'];

        $count = $this->bulkImportService->import(
            $importData['node_id'],
            $importData['valid'],
            $userId
        );

        // Clean up session
        $this->app->getSession()->remove('bulk_import_data');

        $this->flash('success', $this->t('registration.import_complete', ['count' => $count]));
        return $this->redirect('/members');
    }

    // ── Waiting List ─────────────────────────────────────────────────

    /**
     * GET /admin/waiting-list — view the waiting list.
     */
    public function waitingList(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $status = $request->getParam('status') ?: null;
        $nodeId = $request->getParam('node_id') ? (int) $request->getParam('node_id') : null;

        $entries = $this->waitingListService->getList($status, $nodeId);
        $counts = $this->waitingListService->getCountsByStatus();
        $nodes = $this->orgService->getTree();

        return $this->render('@members/registration/waiting_list.html.twig', [
            'entries' => $entries,
            'counts' => $counts,
            'nodes' => $nodes,
            'filters' => [
                'status' => $status,
                'node_id' => $nodeId,
            ],
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $this->t('registration.waiting_list')],
            ],
        ]);
    }

    /**
     * POST /admin/waiting-list/{id}/status — update waiting list entry status.
     */
    public function waitingListStatus(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $id = (int) $vars['id'];
        $newStatus = trim((string) $request->getParam('status', ''));

        try {
            $this->waitingListService->updateStatus($id, $newStatus);
            $this->flash('success', $this->t('flash.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/waiting-list');
    }

    /**
     * POST /admin/waiting-list/{id}/convert — convert entry to member registration.
     */
    public function waitingListConvert(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $id = (int) $vars['id'];

        try {
            $memberId = $this->waitingListService->convertToRegistration($id, $this->registrationService);
            $this->flash('success', $this->t('registration.converted_to_member'));
            return $this->redirect("/members/{$memberId}");
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/waiting-list');
    }

    /**
     * POST /admin/waiting-list/{id}/delete — delete a waiting list entry.
     */
    public function waitingListDelete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $id = (int) $vars['id'];

        try {
            $this->waitingListService->deleteEntry($id);
            $this->flash('success', $this->t('flash.deleted'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/waiting-list');
    }

    /**
     * POST /admin/waiting-list/reorder — reorder the waiting list (HTMX).
     */
    public function waitingListReorder(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $orderedIds = $request->getParam('order', []);
        if (is_array($orderedIds)) {
            $this->waitingListService->reorder($orderedIds);
        }

        return Response::html('', 204);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

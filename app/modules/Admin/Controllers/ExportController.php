<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\DataExportService;

/**
 * Data export controller.
 *
 * Provides admin data exports (members CSV/XML, settings JSON)
 * and GDPR self-export for authenticated users.
 */
class ExportController extends Controller
{
    private DataExportService $service;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->service = new DataExportService($app->getDb());
    }

    /**
     * GET /admin/export — shows export options page.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.export');
        if ($guard !== null) {
            return $guard;
        }

        return $this->render('@admin/admin/export/index.html.twig', [
            'breadcrumbs' => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('export.title')],
            ],
        ]);
    }

    /**
     * GET /admin/export/members/csv — returns CSV file download.
     */
    public function membersCsv(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.export');
        if ($guard !== null) {
            return $guard;
        }

        $ctx = $this->resolveViewContext();
        $memberSvc = new \App\Modules\Members\Services\MemberService($this->app->getDb());
        $scopeNodeIds = $memberSvc->expandNodeSubtree($ctx->scopeNodeIds());
        $csv = $this->service->exportMembersCsv($scopeNodeIds ?: null);
        $filename = 'members_' . date('Ymd_His') . '.csv';

        return (new Response(200, $csv))
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * GET /admin/export/members/xml — returns XML file download.
     */
    public function membersXml(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.export');
        if ($guard !== null) {
            return $guard;
        }

        $xml = $this->service->exportMembersXml();
        $filename = 'members_' . date('Ymd_His') . '.xml';

        return (new Response(200, $xml))
            ->setHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * GET /admin/export/settings — returns JSON file download.
     */
    public function settingsJson(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.export');
        if ($guard !== null) {
            return $guard;
        }

        $json = $this->service->exportSettingsJson();
        $filename = 'settings_' . date('Ymd_His') . '.json';

        return (new Response(200, $json))
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * GET /my-data/export — GDPR self-export (no admin permission, just auth check).
     */
    public function myData(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $user = $this->app->getSession()->get('user');
        $memberId = (int) ($user['member_id'] ?? 0);

        if ($memberId === 0) {
            $this->flash('error', $this->t('export.no_member_linked'));
            return $this->redirect('/account');
        }

        try {
            $csv = $this->service->exportMyDataCsv($memberId);
        } catch (\RuntimeException $e) {
            $this->flash('error', $this->t('export.my_data_failed'));
            return $this->redirect('/account');
        }

        $filename = 'my_data_' . date('Ymd_His') . '.csv';

        return (new Response(200, $csv))
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

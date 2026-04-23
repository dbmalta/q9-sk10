<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\ReportService;

/**
 * Report controller.
 *
 * Demographics, member growth trends, status change history,
 * and CSV export for the admin reporting section.
 */
class ReportController extends Controller
{
    private ReportService $reportService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->reportService = new ReportService($app->getDb());
    }

    /**
     * GET /admin/reports — demographics and roles summary.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.reports');
        if ($guard !== null) {
            return $guard;
        }

        $ctx = $this->resolveViewContext();
        $memberSvc = new \App\Modules\Members\Services\MemberService($this->app->getDb());
        $scopeNodeIds = $memberSvc->expandNodeSubtree($ctx->scopeNodeIds());

        $demographics = $this->reportService->getDemographics($scopeNodeIds);
        $roles = $this->reportService->getRolesSummary($scopeNodeIds);

        return $this->render('@admin/admin/reports/index.html.twig', [
            'demographics' => $demographics,
            'roles' => $roles,
            'breadcrumbs' => [
                ['label' => $this->t('nav.reports')],
            ],
        ]);
    }

    /**
     * GET /admin/reports/growth — member growth over time.
     */
    public function growth(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.reports');
        if ($guard !== null) {
            return $guard;
        }

        $interval = $request->getParam('interval', 'month');
        if (!in_array($interval, ['month', 'quarter', 'year'], true)) {
            $interval = 'month';
        }

        $startDate = $request->getParam('start_date') ?: null;
        $endDate = $request->getParam('end_date') ?: null;

        $ctx = $this->resolveViewContext();
        $memberSvc = new \App\Modules\Members\Services\MemberService($this->app->getDb());
        $scopeNodeIds = $memberSvc->expandNodeSubtree($ctx->scopeNodeIds());
        $growth = $this->reportService->getMemberGrowth($interval, $startDate, $endDate, $scopeNodeIds);

        return $this->render('@admin/admin/reports/growth.html.twig', [
            'growth' => $growth,
            'interval' => $interval,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'breadcrumbs' => [
                ['label' => $this->t('nav.reports'), 'url' => '/admin/reports'],
                ['label' => $this->t('reports.growth')],
            ],
        ]);
    }

    /**
     * GET /admin/reports/status-changes — member status change log.
     */
    public function statusChanges(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.reports');
        if ($guard !== null) {
            return $guard;
        }

        $startDate = $request->getParam('start_date') ?: null;
        $endDate = $request->getParam('end_date') ?: null;

        $ctx = $this->resolveViewContext();
        $memberSvc = new \App\Modules\Members\Services\MemberService($this->app->getDb());
        $scopeNodeIds = $memberSvc->expandNodeSubtree($ctx->scopeNodeIds());
        $changes = $this->reportService->getStatusChanges($startDate, $endDate, $scopeNodeIds);

        return $this->render('@admin/admin/reports/status_changes.html.twig', [
            'changes' => $changes,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'breadcrumbs' => [
                ['label' => $this->t('nav.reports'), 'url' => '/admin/reports'],
                ['label' => $this->t('reports.status_changes')],
            ],
        ]);
    }

    /**
     * GET /admin/reports/export/members — CSV export of all members.
     */
    public function exportMembers(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.reports');
        if ($guard !== null) {
            return $guard;
        }

        $ctx = $this->resolveViewContext();
        $memberSvc = new \App\Modules\Members\Services\MemberService($this->app->getDb());
        $scopeNodeIds = $memberSvc->expandNodeSubtree($ctx->scopeNodeIds());
        $csv = $this->reportService->exportMembersCsv($scopeNodeIds ?: null);

        return (new Response(200, $csv))
            ->setHeader('Content-Type', 'text/csv')
            ->setHeader('Content-Disposition', 'attachment; filename="members_export.csv"');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

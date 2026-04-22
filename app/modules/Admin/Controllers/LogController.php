<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\LogViewerService;

/**
 * System log viewer controller.
 *
 * Tabbed access to errors, slow queries, cron runs, and app info/debug logs
 * with per-tab filtering and the ability to clear individual log files.
 */
class LogController extends Controller
{
    private LogViewerService $service;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->service = new LogViewerService(ROOT_PATH);
    }

    /**
     * GET /admin/logs — tabs for errors/slow-queries/cron/app with filtering.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.logs');
        if ($guard !== null) {
            return $guard;
        }

        $validTabs = LogViewerService::types();
        $tab = (string) $request->getParam('tab', 'errors');
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'errors';
        }

        $page    = max(1, (int) $request->getParam('page', 1));
        $perPage = 50;

        $filters = [
            'level'  => (string) $request->getParam('level', ''),
            'status' => (string) $request->getParam('status', ''),
            'min_ms' => (float)  $request->getParam('min_ms', 0),
            'search' => (string) $request->getParam('q', ''),
        ];

        $counts = $this->service->getLogCounts();
        $result = $this->service->getEntries($tab, $page, $perPage, $filters);

        $stats = $tab === 'slow-queries'
            ? $this->service->getSlowQueryStats($filters)
            : null;

        return $this->render('@admin/admin/logs/index.html.twig', [
            'active_tab'  => $tab,
            'counts'      => $counts,
            'entries'     => $result['items'],
            'pagination'  => $result,
            'filters'     => $filters,
            'stats'       => $stats,
            'breadcrumbs' => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('logs.title')],
            ],
        ]);
    }

    /**
     * POST /admin/logs/{type}/clear — clears a log file.
     */
    public function clear(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.logs');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $type = (string) ($vars['type'] ?? '');

        if (!in_array($type, LogViewerService::types(), true)) {
            $this->flash('error', $this->t('logs.invalid_type'));
            return $this->redirect('/admin/logs');
        }

        try {
            $this->service->clearLog($type);
            $this->flash('success', $this->t('logs.cleared'));
        } catch (\Throwable $e) {
            $this->flash('error', $this->t('logs.clear_failed'));
        }

        return $this->redirect('/admin/logs?tab=' . $type);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

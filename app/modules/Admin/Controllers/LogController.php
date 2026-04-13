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
 * Provides tabbed access to error, slow-query, and cron logs
 * with the ability to clear individual log files.
 */
class LogController extends Controller
{
    private LogViewerService $service;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->service = new LogViewerService(
            $app->getConfigValue('data_path', ROOT_PATH . '/data')
        );
    }

    /**
     * GET /admin/logs — tabs for errors/slow-queries/cron, shows selected tab's log.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.logs');
        if ($guard !== null) {
            return $guard;
        }

        $tab     = $request->getParam('tab', 'errors');
        $page    = max(1, (int) $request->getParam('page', 1));
        $perPage = 50;

        // Validate tab
        $validTabs = ['errors', 'slow-queries', 'cron'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'errors';
        }

        // Get counts for all tabs
        $counts = $this->service->getLogCounts();

        // Get entries for the active tab
        $result = match ($tab) {
            'slow-queries' => $this->service->getSlowQueries($page, $perPage),
            'cron'         => $this->service->getCronLog($page, $perPage),
            default        => $this->service->getErrors($page, $perPage),
        };

        return $this->render('@admin/admin/logs/index.html.twig', [
            'active_tab'  => $tab,
            'counts'      => $counts,
            'entries'     => $result['items'],
            'pagination'  => $result,
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

        $validTypes = ['errors', 'slow-queries', 'cron'];
        if (!in_array($type, $validTypes, true)) {
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

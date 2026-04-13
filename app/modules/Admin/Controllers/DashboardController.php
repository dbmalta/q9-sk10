<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\DashboardService;

/**
 * Admin dashboard controller.
 *
 * Displays aggregate statistics, recent registrations, upcoming events,
 * and system health indicators.
 */
class DashboardController extends Controller
{
    private DashboardService $dashboardService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->dashboardService = new DashboardService($app->getDb());
    }

    /**
     * GET /admin/dashboard — main dashboard view.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.dashboard');
        if ($guard !== null) return $guard;

        $stats = $this->dashboardService->getStats();
        $health = $this->dashboardService->getSystemHealth();

        return $this->render('@admin/admin/dashboard.html.twig', [
            'stats' => $stats,
            'health' => $health,
            'breadcrumbs' => [
                ['label' => $this->t('nav.dashboard')],
            ],
        ]);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

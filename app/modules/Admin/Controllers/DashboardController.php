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
     * GET / — mode-aware landing page.
     *
     * Unauthenticated users go to /login. Authenticated users in admin mode
     * land on the admin dashboard; in member mode they land on their own
     * member profile (fallback: /account).
     */
    public function root(Request $request, array $vars): Response
    {
        if (!$this->app->getSession()->isAuthenticated()) {
            return $this->redirect('/login');
        }

        $ctx = $this->resolveViewContext();
        return $ctx->isAdmin()
            ? $this->redirect('/admin/dashboard')
            : $this->redirect('/me');
    }

    /**
     * GET /admin/dashboard — main dashboard view.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requireAuth();
        if ($guard !== null) {
            return $guard;
        }

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

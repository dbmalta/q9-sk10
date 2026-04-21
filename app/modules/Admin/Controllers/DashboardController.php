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
     * GET / — role-aware landing page.
     *
     * Members linked to a member record land on their own profile; everyone
     * else goes to the admin dashboard. Unauthenticated users fall through to
     * requireAuth() downstream on /admin/dashboard.
     */
    public function root(Request $request, array $vars): Response
    {
        $user = $this->app->getSession()->get('user');
        if (is_array($user) && empty($user['is_super_admin'])) {
            $memberId = (int) ($user['member_id'] ?? 0);
            if ($memberId > 0) {
                return $this->redirect('/members/' . $memberId);
            }
            $row = $this->app->getDb()->fetchOne(
                "SELECT id FROM members WHERE user_id = :uid LIMIT 1",
                ['uid' => (int) ($user['id'] ?? 0)]
            );
            if ($row && (int) $row['id'] > 0) {
                return $this->redirect('/members/' . (int) $row['id']);
            }
        }

        return $this->redirect('/admin/dashboard');
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

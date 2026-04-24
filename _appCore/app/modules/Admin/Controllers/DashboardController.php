<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\DashboardService;

class DashboardController extends Controller
{
    private DashboardService $service;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->service = new DashboardService($app->getDb());
    }

    public function root(Request $request, array $vars): Response
    {
        if (!$this->app->getSession()->isAuthenticated()) {
            return $this->redirect('/login');
        }
        return $this->redirect('/admin/dashboard');
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requireAuth();
        if ($guard !== null) {
            return $guard;
        }

        return $this->render('@admin/admin/dashboard.html.twig', [
            'stats'  => $this->service->getStats(),
            'health' => $this->service->getSystemHealth(),
            'breadcrumbs' => [
                ['label' => $this->t('nav.dashboard')],
            ],
        ]);
    }
}

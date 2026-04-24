<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\AuditService;

class AuditController extends Controller
{
    private AuditService $audit;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->audit = new AuditService($app->getDb());
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.audit');
        if ($guard !== null) {
            return $guard;
        }

        $page = max(1, (int) $this->getParam('page', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        return $this->render('@admin/admin/audit.html.twig', [
            'entries' => $this->audit->recent($perPage, $offset),
            'total'   => $this->audit->count(),
            'page'    => $page,
            'per_page' => $perPage,
        ]);
    }
}

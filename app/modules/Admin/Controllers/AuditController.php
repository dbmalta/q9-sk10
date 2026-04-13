<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\AuditService;

/**
 * Audit log controller.
 *
 * Provides paginated, filterable access to the system audit trail
 * and per-entity history views.
 */
class AuditController extends Controller
{
    private AuditService $service;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->service = new AuditService($app->getDb());
    }

    /**
     * GET /admin/audit — paginated, filterable audit log.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.audit');
        if ($guard !== null) {
            return $guard;
        }

        $page    = max(1, (int) $request->getParam('page', 1));
        $perPage = 25;

        // Collect filters
        $action     = $request->getParam('action') ?: null;
        $entityType = $request->getParam('entity_type') ?: null;
        $userId     = $request->getParam('user_id') ? (int) $request->getParam('user_id') : null;
        $dateFrom   = $request->getParam('date_from') ?: null;
        $dateTo     = $request->getParam('date_to') ?: null;

        $result = $this->service->getLog($page, $perPage, $entityType, $userId, $action, $dateFrom, $dateTo);

        // Fetch filter options
        $actions     = $this->service->getActions();
        $entityTypes = $this->service->getEntityTypes();

        return $this->render('@admin/admin/audit/index.html.twig', [
            'entries'      => $result['items'],
            'pagination'   => $result,
            'actions'      => $actions,
            'entity_types' => $entityTypes,
            'filters'      => [
                'action'      => $action,
                'entity_type' => $entityType,
                'user_id'     => $userId,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
            ],
            'breadcrumbs' => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('audit.title')],
            ],
        ]);
    }

    /**
     * GET /admin/audit/{entityType}/{entityId} — trail for one entity.
     */
    public function entityTrail(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.audit');
        if ($guard !== null) {
            return $guard;
        }

        $entityType = (string) ($vars['entityType'] ?? '');
        $entityId   = (int) ($vars['entityId'] ?? 0);

        $entries = $this->service->getEntityTrail($entityType, $entityId);

        return $this->render('@admin/admin/audit/entity_trail.html.twig', [
            'entries'     => $entries,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'breadcrumbs' => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('audit.title'), 'url' => '/admin/audit'],
                ['label' => $entityType . ' #' . $entityId],
            ],
        ]);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

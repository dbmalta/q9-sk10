<?php

declare(strict_types=1);

namespace App\Modules\OrgStructure\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Level types controller.
 *
 * CRUD for org level type definitions (e.g. National, Region, District,
 * Group, Section). Level names are user-defined to support any Scout
 * organisation's structure.
 */
class LevelTypesController extends Controller
{
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->orgService = new OrgService($app->getDb());
    }

    /**
     * GET /admin/org/levels — list level types.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) return $guard;

        $levels = $this->orgService->getLevelTypes();

        return $this->render('@orgstructure/levels/index.html.twig', [
            'levels' => $levels,
            'breadcrumbs' => [
                ['label' => $this->t('nav.org_structure'), 'url' => '/admin/org'],
                ['label' => $this->t('org.level_types')],
            ],
        ]);
    }

    /**
     * POST /admin/org/levels — create a level type.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) return $guard;

        $name = trim((string) $this->getParam('name', ''));
        if ($name === '') {
            $this->flash('error', $this->t('org.name_required'));
            return $this->redirect('/admin/org/levels');
        }

        $this->orgService->createLevelType([
            'name' => $name,
            'depth' => (int) $this->getParam('depth', 0),
            'is_leaf' => (int) $this->getParam('is_leaf', 0),
            'sort_order' => (int) $this->getParam('sort_order', 0),
        ]);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/org/levels');
    }

    /**
     * POST /admin/org/levels/{id} — update a level type.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) return $guard;

        $levelId = (int) $vars['id'];
        $level = $this->orgService->getLevelType($levelId);
        if ($level === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $name = trim((string) $this->getParam('name', ''));
        if ($name === '') {
            $this->flash('error', $this->t('org.name_required'));
            return $this->redirect('/admin/org/levels');
        }

        $this->orgService->updateLevelType($levelId, [
            'name' => $name,
            'depth' => (int) $this->getParam('depth', 0),
            'is_leaf' => (int) $this->getParam('is_leaf', 0),
            'sort_order' => (int) $this->getParam('sort_order', 0),
        ]);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/org/levels');
    }

    /**
     * POST /admin/org/levels/{id}/delete — delete a level type.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) return $guard;

        try {
            $this->orgService->deleteLevelType((int) $vars['id']);
            $this->flash('success', $this->t('flash.deleted'));
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/org/levels');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

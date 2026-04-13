<?php

declare(strict_types=1);

namespace App\Modules\Achievements\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Achievements\Services\AchievementService;

/**
 * Achievement and training definition management controller.
 *
 * Admin: list, create, edit, activate/deactivate definitions.
 * Member profile: award and revoke achievements from a member.
 */
class AchievementController extends Controller
{
    private AchievementService $achievementService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->achievementService = new AchievementService($app->getDb());
    }

    /**
     * GET /admin/achievements — list all definitions grouped by category.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.read');
        if ($guard !== null) return $guard;

        $achievements = $this->achievementService->getDefinitions('achievement', false);
        $training = $this->achievementService->getDefinitions('training', false);

        return $this->render('@achievements/achievements/index.html.twig', [
            'achievements' => $achievements,
            'training' => $training,
            'breadcrumbs' => [
                ['label' => $this->t('nav.achievements')],
            ],
        ]);
    }

    /**
     * GET /admin/achievements/create — show create form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        return $this->render('@achievements/achievements/form.html.twig', [
            'definition' => null,
            'breadcrumbs' => [
                ['label' => $this->t('nav.achievements'), 'url' => '/admin/achievements'],
                ['label' => $this->t('achievements.create')],
            ],
        ]);
    }

    /**
     * POST /admin/achievements — store a new definition.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $data = [
            'name' => trim((string) $request->getParam('name', '')),
            'description' => $request->getParam('description') ?: null,
            'category' => (string) $request->getParam('category', 'achievement'),
            'is_active' => $request->getParam('is_active') ? 1 : 0,
        ];

        if (empty($data['name'])) {
            $this->flash('error', $this->t('achievements.name_required'));
            return $this->redirect('/admin/achievements/create');
        }

        try {
            $this->achievementService->createDefinition($data);
            $this->flash('success', $this->t('flash.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/achievements/create');
        }

        return $this->redirect('/admin/achievements');
    }

    /**
     * GET /admin/achievements/{id}/edit — show edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        $id = (int) $vars['id'];
        $definition = $this->achievementService->getDefinitionById($id);

        if (!$definition) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        return $this->render('@achievements/achievements/form.html.twig', [
            'definition' => $definition,
            'breadcrumbs' => [
                ['label' => $this->t('nav.achievements'), 'url' => '/admin/achievements'],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    /**
     * POST /admin/achievements/{id} — update a definition.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $id = (int) $vars['id'];

        $data = [
            'name' => trim((string) $request->getParam('name', '')),
            'description' => $request->getParam('description') ?: null,
            'category' => (string) $request->getParam('category', 'achievement'),
            'is_active' => $request->getParam('is_active') ? 1 : 0,
        ];

        if (empty($data['name'])) {
            $this->flash('error', $this->t('achievements.name_required'));
            return $this->redirect("/admin/achievements/{$id}/edit");
        }

        try {
            $this->achievementService->updateDefinition($id, $data);
            $this->flash('success', $this->t('flash.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect("/admin/achievements/{$id}/edit");
        }

        return $this->redirect('/admin/achievements');
    }

    /**
     * POST /admin/achievements/{id}/deactivate — soft-delete a definition.
     */
    public function deactivate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $this->achievementService->deactivateDefinition((int) $vars['id']);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/achievements');
    }

    /**
     * POST /admin/achievements/{id}/activate — re-activate a definition.
     */
    public function activate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $this->achievementService->activateDefinition((int) $vars['id']);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/achievements');
    }

    /**
     * POST /members/{memberId}/achievements — award an achievement to a member.
     */
    public function award(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $memberId = (int) $vars['memberId'];
        $achievementId = (int) $request->getParam('achievement_id', 0);
        $awardedDate = trim((string) $request->getParam('awarded_date', ''));
        $notes = $request->getParam('notes') ?: null;

        if ($achievementId === 0 || $awardedDate === '') {
            $this->flash('error', $this->t('achievements.award_fields_required'));
            return $this->redirect("/members/{$memberId}");
        }

        $userId = (int) $this->app->getSession()->get('user')['id'];

        $this->achievementService->awardToMember($memberId, $achievementId, $awardedDate, $userId, $notes);
        $this->flash('success', $this->t('achievements.awarded'));
        return $this->redirect("/members/{$memberId}");
    }

    /**
     * POST /members/{memberId}/achievements/{id}/revoke — revoke an award.
     */
    public function revoke(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('achievements.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $memberId = (int) $vars['memberId'];
        $id = (int) $vars['id'];

        $this->achievementService->revokeFromMember($id);
        $this->flash('success', $this->t('achievements.revoked'));
        return $this->redirect("/members/{$memberId}");
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

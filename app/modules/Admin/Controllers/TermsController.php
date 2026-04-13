<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\TermsService;

/**
 * Terms & Conditions management controller.
 *
 * CRUD for terms versions with publish/unpublish workflow
 * and acceptance tracking.
 */
class TermsController extends Controller
{
    private TermsService $termsService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->termsService = new TermsService($app->getDb());
    }

    /**
     * GET /admin/terms — list all terms versions.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $versions = $this->termsService->getVersions();

        return $this->render('@admin/admin/terms/index.html.twig', [
            'versions' => $versions,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms')],
            ],
        ]);
    }

    /**
     * GET /admin/terms/create — show create form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        return $this->render('@admin/admin/terms/form.html.twig', [
            'version' => null,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $this->t('terms.create')],
            ],
        ]);
    }

    /**
     * POST /admin/terms — store new terms version.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $userId = (int) $this->app->getSession()->get('user')['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'content' => (string) $request->getParam('content', ''),
            'version_number' => trim((string) $request->getParam('version_number', '')),
            'grace_period_days' => (int) $request->getParam('grace_period_days', 14),
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('terms.title_required'));
            return $this->redirect('/admin/terms/create');
        }

        if (empty(trim($data['content']))) {
            $this->flash('error', $this->t('terms.content_required'));
            return $this->redirect('/admin/terms/create');
        }

        if (empty($data['version_number'])) {
            $this->flash('error', $this->t('terms.version_required'));
            return $this->redirect('/admin/terms/create');
        }

        $this->termsService->createVersion($data, $userId);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/terms');
    }

    /**
     * GET /admin/terms/{id}/edit — show edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $version = $this->termsService->getVersionById($id);

        if (!$version) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        return $this->render('@admin/admin/terms/form.html.twig', [
            'version' => $version,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    /**
     * POST /admin/terms/{id} — update terms version.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $id = (int) $vars['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'content' => (string) $request->getParam('content', ''),
            'version_number' => trim((string) $request->getParam('version_number', '')),
            'grace_period_days' => (int) $request->getParam('grace_period_days', 14),
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('terms.title_required'));
            return $this->redirect("/admin/terms/{$id}/edit");
        }

        if (empty(trim($data['content']))) {
            $this->flash('error', $this->t('terms.content_required'));
            return $this->redirect("/admin/terms/{$id}/edit");
        }

        if (empty($data['version_number'])) {
            $this->flash('error', $this->t('terms.version_required'));
            return $this->redirect("/admin/terms/{$id}/edit");
        }

        $this->termsService->updateVersion($id, $data);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/terms');
    }

    /**
     * POST /admin/terms/{id}/publish — publish a terms version.
     */
    public function publish(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $this->termsService->publishVersion((int) $vars['id']);
        $this->flash('success', $this->t('terms.published'));
        return $this->redirect('/admin/terms');
    }

    /**
     * GET /admin/terms/{id} — view terms content and acceptance stats.
     */
    public function show(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $version = $this->termsService->getVersionById($id);

        if (!$version) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $acceptances = $this->termsService->getAcceptanceReport($id);

        return $this->render('@admin/admin/terms/show.html.twig', [
            'version' => $version,
            'acceptances' => $acceptances,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $version['title']],
            ],
        ]);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\NoticeService;

/**
 * Notice management controller.
 *
 * CRUD for system-wide notices with acknowledgement tracking
 * and reporting.
 */
class NoticeController extends Controller
{
    private NoticeService $noticeService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->noticeService = new NoticeService($app->getDb());
    }

    /**
     * GET /admin/notices — list all notices.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) return $guard;

        $notices = $this->noticeService->getAll();

        return $this->render('@admin/admin/notices/index.html.twig', [
            'notices' => $notices,
            'breadcrumbs' => [
                ['label' => $this->t('nav.notices')],
            ],
        ]);
    }

    /**
     * GET /admin/notices/create — show create form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) return $guard;

        return $this->render('@admin/admin/notices/form.html.twig', [
            'notice' => null,
            'breadcrumbs' => [
                ['label' => $this->t('nav.notices'), 'url' => '/admin/notices'],
                ['label' => $this->t('notices.create')],
            ],
        ]);
    }

    /**
     * POST /admin/notices — store new notice.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $userId = (int) $this->app->getSession()->get('user')['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'content' => (string) $request->getParam('content', ''),
            'type' => (string) $request->getParam('type', 'informational'),
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('notices.title_required'));
            return $this->redirect('/admin/notices/create');
        }

        if (empty(trim($data['content']))) {
            $this->flash('error', $this->t('notices.content_required'));
            return $this->redirect('/admin/notices/create');
        }

        $this->noticeService->create($data, $userId);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/notices');
    }

    /**
     * GET /admin/notices/{id}/edit — show edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) return $guard;

        $id = (int) $vars['id'];
        $notice = $this->noticeService->getById($id);

        if (!$notice) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        return $this->render('@admin/admin/notices/form.html.twig', [
            'notice' => $notice,
            'breadcrumbs' => [
                ['label' => $this->t('nav.notices'), 'url' => '/admin/notices'],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    /**
     * POST /admin/notices/{id} — update notice.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $id = (int) $vars['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'content' => (string) $request->getParam('content', ''),
            'type' => (string) $request->getParam('type', 'informational'),
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('notices.title_required'));
            return $this->redirect("/admin/notices/{$id}/edit");
        }

        if (empty(trim($data['content']))) {
            $this->flash('error', $this->t('notices.content_required'));
            return $this->redirect("/admin/notices/{$id}/edit");
        }

        $this->noticeService->update($id, $data);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/notices');
    }

    /**
     * POST /admin/notices/{id}/deactivate — deactivate a notice.
     */
    public function deactivate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $this->noticeService->deactivate((int) $vars['id']);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/notices');
    }

    /**
     * GET /admin/notices/{id}/acknowledgements — view who acknowledged a notice.
     */
    public function acknowledgements(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) return $guard;

        $id = (int) $vars['id'];
        $notice = $this->noticeService->getById($id);

        if (!$notice) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $acknowledgements = $this->noticeService->getAcknowledgementReport($id);

        return $this->render('@admin/admin/notices/acknowledgements.html.twig', [
            'notice' => $notice,
            'acknowledgements' => $acknowledgements,
            'breadcrumbs' => [
                ['label' => $this->t('nav.notices'), 'url' => '/admin/notices'],
                ['label' => $notice['title'], 'url' => '/admin/notices'],
                ['label' => $this->t('notices.acknowledgements')],
            ],
        ]);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

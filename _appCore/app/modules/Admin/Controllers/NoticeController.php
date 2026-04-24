<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\NoticeService;

class NoticeController extends Controller
{
    private NoticeService $notices;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->notices = new NoticeService($app->getDb());
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/notices.html.twig', [
            'notices' => $this->notices->all(),
        ]);
    }

    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/notice_form.html.twig', ['notice' => null]);
    }

    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) {
            return $guard;
        }

        $user = $this->app->getSession()->getUser();
        $this->notices->create(
            (string) $this->getParam('title', ''),
            (string) $this->getParam('content', ''),
            (string) $this->getParam('type', 'informational'),
            $user ? (int) $user['id'] : 0,
        );
        $this->flash('success', $this->t('notices.created'));
        return $this->redirect('/admin/notices');
    }

    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) {
            return $guard;
        }
        $notice = $this->notices->find((int) $vars['id']);
        if ($notice === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }
        return $this->render('@admin/admin/notice_form.html.twig', ['notice' => $notice]);
    }

    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) {
            return $guard;
        }

        $this->notices->update(
            (int) $vars['id'],
            (string) $this->getParam('title', ''),
            (string) $this->getParam('content', ''),
            (string) $this->getParam('type', 'informational'),
        );
        $this->flash('success', $this->t('notices.updated'));
        return $this->redirect('/admin/notices');
    }

    public function deactivate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.notices');
        if ($guard !== null) {
            return $guard;
        }
        $this->notices->deactivate((int) $vars['id']);
        $this->flash('success', $this->t('notices.deactivated'));
        return $this->redirect('/admin/notices');
    }
}

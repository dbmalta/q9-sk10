<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\TermsService;

class TermsController extends Controller
{
    private TermsService $terms;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->terms = new TermsService($app->getDb());
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/terms.html.twig', [
            'versions' => $this->terms->all(),
        ]);
    }

    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/terms_form.html.twig', ['version' => null]);
    }

    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $user = $this->app->getSession()->getUser();
        $this->terms->create(
            (string) $this->getParam('title', ''),
            (string) $this->getParam('content', ''),
            (string) $this->getParam('version_number', '1.0'),
            (int) $this->getParam('grace_period_days', 14),
            $user ? (int) $user['id'] : 0,
        );
        $this->flash('success', $this->t('terms.created'));
        return $this->redirect('/admin/terms');
    }

    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $version = $this->terms->find((int) $vars['id']);
        if ($version === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }
        return $this->render('@admin/admin/terms_form.html.twig', ['version' => $version]);
    }

    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $this->terms->update(
            (int) $vars['id'],
            (string) $this->getParam('title', ''),
            (string) $this->getParam('content', ''),
            (string) $this->getParam('version_number', '1.0'),
            (int) $this->getParam('grace_period_days', 14),
        );
        $this->flash('success', $this->t('terms.updated'));
        return $this->redirect('/admin/terms');
    }

    public function publish(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $this->terms->publish((int) $vars['id']);
        $this->flash('success', $this->t('terms.published'));
        return $this->redirect('/admin/terms');
    }
}

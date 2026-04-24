<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\BackupService;

class BackupController extends Controller
{
    private BackupService $backups;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->backups = new BackupService(
            ROOT_PATH . '/data/backups',
            (array) $app->getConfigValue('db', [])
        );
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/backups.html.twig', [
            'backups' => $this->backups->list(),
        ]);
    }

    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) {
            return $guard;
        }

        $result = $this->backups->create();
        if ($result['success']) {
            $this->flash('success', $this->t('backups.created'));
        } else {
            $this->flash('error', $result['error'] ?? $this->t('backups.create_failed'));
        }
        return $this->redirect('/admin/backups');
    }

    public function download(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) {
            return $guard;
        }

        $path = $this->backups->pathFor((string) $vars['filename']);
        if ($path === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }
        return Response::file($path, basename($path), 'application/sql');
    }

    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) {
            return $guard;
        }

        if ($this->backups->delete((string) $vars['filename'])) {
            $this->flash('success', $this->t('backups.deleted'));
        } else {
            $this->flash('error', $this->t('backups.delete_failed'));
        }
        return $this->redirect('/admin/backups');
    }
}

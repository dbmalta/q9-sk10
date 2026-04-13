<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\BackupService;

/**
 * Backup management controller.
 *
 * Create, list, download, and delete system backups.
 */
class BackupController extends Controller
{
    private BackupService $service;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->service = new BackupService(
            $app->getDb(),
            $app->getConfigValue('data_path', ROOT_PATH . '/data')
        );
    }

    /**
     * GET /admin/backups — list backups.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) return $guard;

        $backups = $this->service->listBackups();

        return $this->render('@admin/admin/backup/index.html.twig', [
            'backups' => $backups,
            'breadcrumbs' => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('backup.title')],
            ],
        ]);
    }

    /**
     * POST /admin/backups/create — create new backup.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        try {
            $filename = $this->service->createBackup();
            $this->flash('success', $this->t('backup.created', ['filename' => $filename]));
        } catch (\Throwable $e) {
            $this->flash('error', $this->t('backup.create_failed'));
        }

        return $this->redirect('/admin/backups');
    }

    /**
     * GET /admin/backups/{filename} — download backup file.
     */
    public function download(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) return $guard;

        $filename = (string) ($vars['filename'] ?? '');

        try {
            $path = $this->service->getBackupPath($filename);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->t('backup.invalid_filename'));
            return $this->redirect('/admin/backups');
        }

        if ($path === null) {
            $this->flash('error', $this->t('backup.not_found'));
            return $this->redirect('/admin/backups');
        }

        return Response::file($path, $filename, 'application/zip');
    }

    /**
     * POST /admin/backups/{filename}/delete — delete backup.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.backup');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $filename = (string) ($vars['filename'] ?? '');

        try {
            $this->service->deleteBackup($filename);
            $this->flash('success', $this->t('flash.deleted'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->t('backup.invalid_filename'));
        } catch (\RuntimeException $e) {
            $this->flash('error', $this->t('backup.delete_failed'));
        }

        return $this->redirect('/admin/backups');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

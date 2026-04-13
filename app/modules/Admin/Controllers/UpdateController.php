<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use Updater\UpdateManager;

/**
 * System update controller.
 *
 * Provides a UI for checking GitHub releases and triggering the two-phase
 * update process (download → run.php apply).
 */
class UpdateController extends Controller
{
    private UpdateManager $updater;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->updater = new UpdateManager(ROOT_PATH);
    }

    /**
     * GET /admin/updates — show current version and update UI.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.updates');
        if ($guard !== null) {
            return $guard;
        }

        $currentVersion = $this->updater->getCurrentVersion();

        if ($request->getParam('update_failed') === '1') {
            $this->flash('error', $this->t('update.failed'));
        }

        return $this->render('@admin/admin/updates/index.html.twig', [
            'current_version' => $currentVersion,
            'breadcrumbs' => [
                ['label' => $this->t('nav.settings'), 'url' => '/admin/settings'],
                ['label' => $this->t('nav.updates')],
            ],
        ]);
    }

    /**
     * GET /admin/updates/check — HTMX partial: check GitHub for a newer release.
     */
    public function check(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.updates');
        if ($guard !== null) {
            return $guard;
        }

        $currentVersion = $this->updater->getCurrentVersion();
        $available = $this->updater->checkForUpdate($currentVersion);

        return $this->render('@admin/admin/updates/_check_result.html.twig', [
            'current_version' => $currentVersion,
            'available' => $available,
        ]);
    }

    /**
     * POST /admin/updates/download — download the release zip, verify its
     * signature, then redirect to run.php with a single-use token.
     */
    public function download(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.updates');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $downloadUrl = (string) $request->getParam('download_url', '');
        $signatureUrl = (string) $request->getParam('signature_url', '');
        $version = (string) $request->getParam('version', '');

        if ($downloadUrl === '' || $version === '') {
            $this->flash('error', $this->t('update.download_failed'));
            return $this->redirect('/admin/updates');
        }

        $safeVersion = preg_replace('/[^0-9.]/', '', $version);
        $zipPath = ROOT_PATH . '/var/updates/scoutkeeper-' . $safeVersion . '.zip';

        if (!$this->updater->downloadRelease($downloadUrl, $zipPath)) {
            $this->flash('error', $this->t('update.download_failed'));
            return $this->redirect('/admin/updates');
        }

        // Optionally verify signature
        if ($signatureUrl !== '') {
            $sigPath = $zipPath . '.sig';
            $this->updater->downloadRelease($signatureUrl, $sigPath);

            if (!$this->updater->verifySignature($zipPath, $sigPath)) {
                @unlink($zipPath);
                @unlink($sigPath);
                $this->flash('error', $this->t('update.signature_invalid'));
                return $this->redirect('/admin/updates');
            }
        }

        $token = $this->updater->prepareDownload($zipPath);

        $baseUrl = rtrim($this->app->getConfigValue('app.url', ''), '/');
        return $this->redirect($baseUrl . '/updater/run.php?token=' . urlencode($token));
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

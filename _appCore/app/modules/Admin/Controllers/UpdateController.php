<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;

/**
 * Auto-update admin UI.
 *
 * Shows the currently installed version and exposes controls for
 * generating a single-use token that /updater/run.php consumes to apply
 * an uploaded release. Actual unpack/migration logic lives in
 * /updater/UpdateManager.php.
 */
class UpdateController extends Controller
{
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.updates');
        if ($guard !== null) {
            return $guard;
        }

        $version = trim((string) @file_get_contents(ROOT_PATH . '/VERSION') ?: '');

        return $this->render('@admin/admin/updates.html.twig', [
            'current_version' => $version,
        ]);
    }

    public function check(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.updates');
        if ($guard !== null) {
            return $guard;
        }

        // Project wiring point: hit the release feed URL of your choice and
        // return { current_version, latest_version, release_notes_url } here.
        return $this->json([
            'current_version' => trim((string) @file_get_contents(ROOT_PATH . '/VERSION') ?: ''),
            'latest_version'  => null,
            'message'         => 'Configure an update source for this installation.',
        ]);
    }

    public function apply(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.updates');
        if ($guard !== null) {
            return $guard;
        }

        $tokenFile = ROOT_PATH . '/var/update_token.txt';
        $token = bin2hex(random_bytes(16));
        @file_put_contents($tokenFile, $token, LOCK_EX);
        @chmod($tokenFile, 0600);

        return $this->redirect('/updater/run.php?token=' . urlencode($token));
    }
}

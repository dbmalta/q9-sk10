<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;

/**
 * Data export controller — generic user CSV. Project-specific exports are
 * layered on top in their own module.
 */
class ExportController extends Controller
{
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.export');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/export.html.twig');
    }

    public function usersCsv(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.export');
        if ($guard !== null) {
            return $guard;
        }

        $users = $this->app->getDb()->fetchAll(
            "SELECT id, email, is_active, is_super_admin, mfa_enabled, last_login_at, created_at
             FROM users ORDER BY id"
        );

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['id', 'email', 'is_active', 'is_super_admin', 'mfa_enabled', 'last_login_at', 'created_at']);
        foreach ($users as $user) {
            fputcsv($csv, [
                $user['id'], $user['email'], $user['is_active'], $user['is_super_admin'],
                $user['mfa_enabled'], $user['last_login_at'], $user['created_at'],
            ]);
        }
        rewind($csv);
        $body = stream_get_contents($csv) ?: '';
        fclose($csv);

        $response = new Response(200, $body);
        $response->setHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->setHeader('Content-Disposition', 'attachment; filename="users-' . gmdate('Ymd') . '.csv"');
        return $response;
    }
}

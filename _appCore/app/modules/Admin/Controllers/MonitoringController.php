<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\LogViewerService;
use AppCore\Modules\Admin\Services\SettingsService;

/**
 * Public/API monitoring endpoints.
 *
 * /api/health — public, returns basic liveness status.
 * /api/logs   — API-key protected (key in `settings.api_key`).
 */
class MonitoringController extends Controller
{
    public function health(Request $request, array $vars): Response
    {
        $ok = true;
        $dbOk = true;
        try {
            $this->app->getDb()->fetchColumn('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
            $ok = false;
        }

        return $this->json([
            'status'  => $ok ? 'ok' : 'degraded',
            'time'    => gmdate('c'),
            'php'     => PHP_VERSION,
            'db'      => $dbOk ? 'ok' : 'error',
            'version' => trim((string) @file_get_contents(ROOT_PATH . '/VERSION') ?: ''),
        ], $ok ? 200 : 503);
    }

    public function logs(Request $request, array $vars): Response
    {
        $settings = new SettingsService($this->app->getDb());
        $configuredKey = (string) ($settings->get('api_key')
            ?? $this->app->getConfigValue('monitoring.api_key', '')
            ?? '');

        $provided = (string) ($request->getHeader('X-API-Key') ?? $request->getParam('api_key', ''));

        if ($configuredKey === '' || !hash_equals($configuredKey, $provided)) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $type = (string) $request->getParam('type', 'errors');
        $limit = min(500, max(1, (int) $request->getParam('limit', 100)));
        $logs = new LogViewerService(ROOT_PATH . '/var/logs');

        return $this->json([
            'type'    => $type,
            'entries' => $logs->read($type, $limit),
        ]);
    }
}

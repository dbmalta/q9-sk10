<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\LogViewerService;

class LogController extends Controller
{
    private LogViewerService $logs;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->logs = new LogViewerService(ROOT_PATH . '/var/logs');
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.logs');
        if ($guard !== null) {
            return $guard;
        }

        $type = (string) $this->getParam('type', 'errors');
        if (!in_array($type, LogViewerService::knownTypes(), true)) {
            $type = 'errors';
        }

        return $this->render('@admin/admin/logs.html.twig', [
            'types'   => LogViewerService::knownTypes(),
            'type'    => $type,
            'entries' => $this->logs->read($type, 500),
        ]);
    }

    public function clear(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.logs');
        if ($guard !== null) {
            return $guard;
        }

        $type = (string) ($vars['type'] ?? '');
        if ($this->logs->clear($type)) {
            $this->flash('success', $this->t('logs.cleared'));
        } else {
            $this->flash('error', $this->t('logs.clear_failed'));
        }

        return $this->redirect('/admin/logs?type=' . urlencode($type));
    }
}

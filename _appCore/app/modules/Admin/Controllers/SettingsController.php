<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\SettingsService;

class SettingsController extends Controller
{
    private SettingsService $settings;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->settings = new SettingsService($app->getDb());
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.settings');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/settings.html.twig', [
            'settings' => $this->settings->all(),
        ]);
    }

    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.settings');
        if ($guard !== null) {
            return $guard;
        }

        $submitted = $request->getParam('settings', []);
        if (is_array($submitted)) {
            foreach ($submitted as $key => $value) {
                $this->settings->set((string) $key, (string) $value);
            }
        }

        $this->flash('success', $this->t('settings.saved'));
        return $this->redirect('/admin/settings');
    }
}

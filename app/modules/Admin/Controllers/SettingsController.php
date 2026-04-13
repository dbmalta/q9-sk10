<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\SettingsService;

/**
 * System settings controller.
 *
 * Displays and persists application settings grouped by category
 * (general, registration, security, gdpr, cron).
 */
class SettingsController extends Controller
{
    private SettingsService $settingsService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->settingsService = new SettingsService($app->getDb());
    }

    /**
     * GET /admin/settings — show all settings grouped by tabs.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.settings');
        if ($guard !== null) {
            return $guard;
        }

        $settings = $this->settingsService->getAll();
        $activeTab = $request->getParam('tab', 'general');

        return $this->render('@admin/admin/settings/index.html.twig', [
            'settings' => $settings,
            'active_tab' => $activeTab,
            'breadcrumbs' => [
                ['label' => $this->t('nav.settings')],
            ],
        ]);
    }

    /**
     * POST /admin/settings — save settings from form.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.settings');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $group = (string) $request->getParam('group', 'general');
        $allowedGroups = ['general', 'registration', 'security', 'gdpr', 'cron'];

        if (!in_array($group, $allowedGroups, true)) {
            $this->flash('error', $this->t('settings.invalid_group'));
            return $this->redirect('/admin/settings');
        }

        $settings = (array) $request->getParam('settings', []);

        // Handle checkboxes: if the key wasn't submitted, it means unchecked
        $checkboxKeys = $this->getCheckboxKeys($group);
        foreach ($checkboxKeys as $key) {
            if (!array_key_exists($key, $settings)) {
                $settings[$key] = '0';
            }
        }

        $this->settingsService->setMultiple($settings, $group);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/settings?tab=' . $group);
    }

    /**
     * Get known checkbox keys for a settings group.
     *
     * Checkboxes that are unchecked are not submitted in the POST data,
     * so we need to explicitly set them to '0'.
     *
     * @param string $group Settings group name
     * @return string[] List of checkbox setting keys
     */
    private function getCheckboxKeys(string $group): array
    {
        return match ($group) {
            'registration' => ['self_registration', 'waiting_list', 'admin_approval'],
            'gdpr' => ['gdpr_enabled'],
            default => [],
        };
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

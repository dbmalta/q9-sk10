<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\SettingsService;
use App\Modules\Communications\Services\EmailService;

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

        if ($request->getParam('updated') === '1') {
            $this->flash('success', $this->t('update.completed'));
        }

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
        $allowedGroups = ['general', 'registration', 'security', 'gdpr', 'cron', 'smtp'];

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

        // SMTP password: keep existing value if the field was left blank
        if ($group === 'smtp' && ($settings['smtp_password'] ?? '') === '') {
            unset($settings['smtp_password']);
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

    /**
     * POST /admin/settings/smtp/test — send a test email to the current user.
     */
    public function sendTestEmail(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.settings');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $user = $this->app->getSession()->getUser();
        $to = $user['email'] ?? '';

        if ($to === '') {
            $this->flash('error', $this->t('settings.smtp_test_no_email'));
            return $this->redirect('/admin/settings?tab=smtp');
        }

        $smtpConfig = $this->resolveSmtpConfig();
        $emailService = new EmailService($this->app->getDb(), $smtpConfig);

        try {
            $ok = $emailService->sendEmail(
                $to,
                $this->t('settings.smtp_test_subject'),
                '<p>' . htmlspecialchars($this->t('settings.smtp_test_body'), ENT_QUOTES, 'UTF-8') . '</p>',
                $this->t('settings.smtp_test_body'),
                $user['name'] ?? null,
            );

            if ($ok) {
                $this->flash('success', $this->t('settings.smtp_test_sent', ['email' => $to]));
            } else {
                $this->flash('error', $this->t('settings.smtp_test_failed'));
            }
        } catch (\Throwable $e) {
            $this->flash('error', $this->t('settings.smtp_test_failed') . ' ' . $e->getMessage());
        }

        return $this->redirect('/admin/settings?tab=smtp');
    }

    /**
     * Build SMTP config by overlaying DB `smtp` settings onto config.php values.
     *
     * @return array{host:string,port:int,username:string,password:string,encryption:string,from_email:string,from_name:string}
     */
    private function resolveSmtpConfig(): array
    {
        $fileConfig = $this->app->getConfig()['smtp'] ?? [];
        $dbConfig = $this->settingsService->getGroup('smtp');

        return [
            'host' => (string) ($dbConfig['smtp_host'] ?? $fileConfig['host'] ?? ''),
            'port' => (int) ($dbConfig['smtp_port'] ?? $fileConfig['port'] ?? 587),
            'username' => (string) ($dbConfig['smtp_username'] ?? $fileConfig['username'] ?? ''),
            'password' => (string) ($dbConfig['smtp_password'] ?? $fileConfig['password'] ?? ''),
            'encryption' => (string) ($dbConfig['smtp_encryption'] ?? $fileConfig['encryption'] ?? 'tls'),
            'from_email' => (string) ($dbConfig['smtp_from_email'] ?? $fileConfig['from_email'] ?? 'noreply@localhost'),
            'from_name' => (string) ($dbConfig['smtp_from_name'] ?? $fileConfig['from_name'] ?? 'ScoutKeeper'),
        ];
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

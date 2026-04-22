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

        $settingsService = new \App\Modules\Admin\Services\SettingsService($this->app->getDb());
        $orgName = (string) ($settingsService->get('org_name') ?: 'ScoutKeeper');
        $recipientName = (string) ($user['name'] ?? explode('@', $to)[0]);
        $sentAt = date('Y-m-d H:i:s T');
        $appVersion = trim(@file_get_contents(ROOT_PATH . '/VERSION') ?: '');
        $host = (string) ($smtpConfig['host'] ?? '');
        $port = (int) ($smtpConfig['port'] ?? 0);
        $encryption = strtoupper((string) ($smtpConfig['encryption'] ?? 'none'));

        $html = $this->buildTestEmailHtml($orgName, $recipientName, $sentAt, $appVersion, $host, $port, $encryption);
        $text = $this->buildTestEmailText($orgName, $recipientName, $sentAt, $appVersion, $host, $port, $encryption);

        try {
            $ok = $emailService->sendEmail(
                $to,
                $this->t('settings.smtp_test_subject'),
                $html,
                $text,
                $recipientName,
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

    private function buildTestEmailHtml(
        string $orgName,
        string $recipientName,
        string $sentAt,
        string $appVersion,
        string $host,
        int $port,
        string $encryption
    ): string {
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $org = $e($orgName);
        $name = $e($recipientName);
        $when = $e($sentAt);
        $ver = $e($appVersion !== '' ? 'v' . $appVersion : '');
        $hostStr = $e($host !== '' ? $host . ':' . $port : '(not configured)');
        $enc = $e($encryption);

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#212529;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f8;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
          <tr>
            <td style="background:#0d6efd;padding:24px 32px;color:#ffffff;">
              <div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;opacity:0.85;">{$org}</div>
              <div style="font-size:22px;font-weight:600;margin-top:4px;">SMTP test email</div>
            </td>
          </tr>
          <tr>
            <td style="padding:32px;">
              <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">Hi {$name},</p>
              <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">
                This is a test message sent from the ScoutKeeper admin panel to confirm that your SMTP configuration is delivering mail successfully.
              </p>
              <p style="margin:0 0 24px;font-size:15px;line-height:1.5;">
                If this landed in your inbox, outgoing email is good to go — password resets, registration confirmations, and bulk comms will all use these settings.
              </p>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;font-size:13px;">
                <tr><td style="padding:10px 16px;color:#6c757d;width:35%;">Sent at</td><td style="padding:10px 16px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{$when}</td></tr>
                <tr><td style="padding:10px 16px;color:#6c757d;border-top:1px solid #e9ecef;">SMTP host</td><td style="padding:10px 16px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;border-top:1px solid #e9ecef;">{$hostStr}</td></tr>
                <tr><td style="padding:10px 16px;color:#6c757d;border-top:1px solid #e9ecef;">Encryption</td><td style="padding:10px 16px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;border-top:1px solid #e9ecef;">{$enc}</td></tr>
                <tr><td style="padding:10px 16px;color:#6c757d;border-top:1px solid #e9ecef;">Version</td><td style="padding:10px 16px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;border-top:1px solid #e9ecef;">{$ver}</td></tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 32px;background:#f8f9fa;color:#6c757d;font-size:12px;text-align:center;border-top:1px solid #e9ecef;">
              Automated message from {$org} · ScoutKeeper. Reply not monitored.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildTestEmailText(
        string $orgName,
        string $recipientName,
        string $sentAt,
        string $appVersion,
        string $host,
        int $port,
        string $encryption
    ): string {
        $hostStr = $host !== '' ? $host . ':' . $port : '(not configured)';
        $ver = $appVersion !== '' ? 'v' . $appVersion : '';
        return "Hi {$recipientName},\n\n"
            . "This is a test message from the ScoutKeeper admin panel, confirming your SMTP configuration is delivering mail successfully.\n\n"
            . "If you received this, outgoing email is good to go.\n\n"
            . "Details\n-------\n"
            . "Sent at:    {$sentAt}\n"
            . "SMTP host:  {$hostStr}\n"
            . "Encryption: {$encryption}\n"
            . "Version:    {$ver}\n\n"
            . "— {$orgName} · ScoutKeeper";
    }
}

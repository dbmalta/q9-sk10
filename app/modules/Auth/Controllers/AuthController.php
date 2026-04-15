<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Response;
use App\Core\Request;
use App\Core\Logger;
use App\Modules\Auth\Services\AuthService;

/**
 * Authentication controller.
 *
 * Handles login, logout, password reset, and MFA verification flows.
 * All routes use the auth layout (centered card, no sidebar).
 */
class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->authService = new AuthService(
            $app->getDb(),
            $this->getEncryption()
        );
    }

    /**
     * Get the Encryption instance if available.
     */
    private function getEncryption(): ?\App\Core\Encryption
    {
        $keyFile = ROOT_PATH . '/config/encryption.key';
        if (!file_exists($keyFile) || !is_readable($keyFile)) {
            return null;
        }

        try {
            return new \App\Core\Encryption($keyFile);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * GET /login — show the login form.
     */
    public function showLogin(Request $request, array $vars): Response
    {
        // Already logged in? Go to dashboard
        if ($this->app->getSession()->isAuthenticated()) {
            return $this->redirect('/');
        }

        return $this->render('@auth/auth/login.html.twig');
    }

    /**
     * POST /login — process the login form.
     */
    public function processLogin(Request $request, array $vars): Response
    {
        $email = trim((string) $this->getParam('email', ''));
        $password = (string) $this->getParam('password', '');

        if ($email === '' || $password === '') {
            $this->flash('error', $this->t('auth.login_failed'));
            return $this->render('@auth/auth/login.html.twig', [
                'email' => $email,
            ]);
        }

        // Check for locked account first (for better UX messaging)
        $user = $this->app->getDb()->fetchOne(
            "SELECT * FROM users WHERE email = :email",
            ['email' => strtolower($email)]
        );

        if ($user !== null && $this->authService->isLocked($user)) {
            $this->flash('error', $this->t('auth.login_locked'));
            return $this->render('@auth/auth/login.html.twig', [
                'email' => $email,
            ]);
        }

        $authenticatedUser = $this->authService->authenticate($email, $password);

        if ($authenticatedUser === null) {
            $this->flash('error', $this->t('auth.login_failed'));
            return $this->render('@auth/auth/login.html.twig', [
                'email' => $email,
            ]);
        }

        // Check if MFA is enabled
        if ($authenticatedUser['mfa_enabled']) {
            // Store user ID in session for MFA verification step
            $this->app->getSession()->set('mfa_pending_user_id', $authenticatedUser['id']);
            return $this->redirect('/login/mfa');
        }

        // Login successful — set session and redirect
        $this->completeLogin($authenticatedUser);

        Logger::info('User logged in', ['user_id' => $authenticatedUser['id']]);

        return $this->redirect('/');
    }

    /**
     * GET /login/mfa — show the MFA verification form.
     */
    public function showMfa(Request $request, array $vars): Response
    {
        $pendingUserId = $this->app->getSession()->get('mfa_pending_user_id');
        if ($pendingUserId === null) {
            return $this->redirect('/login');
        }

        return $this->render('@auth/auth/mfa_verify.html.twig');
    }

    /**
     * POST /login/mfa — verify the MFA code.
     */
    public function processMfa(Request $request, array $vars): Response
    {
        $pendingUserId = $this->app->getSession()->get('mfa_pending_user_id');
        if ($pendingUserId === null) {
            return $this->redirect('/login');
        }

        $code = trim((string) $this->getParam('code', ''));

        if ($code === '' || !$this->authService->verifyMfaCode((int) $pendingUserId, $code)) {
            $this->flash('error', $this->t('auth.mfa_invalid'));
            return $this->render('@auth/auth/mfa_verify.html.twig');
        }

        // MFA verified — complete login
        $this->app->getSession()->remove('mfa_pending_user_id');
        $user = $this->authService->getUserById((int) $pendingUserId);

        if ($user === null) {
            return $this->redirect('/login');
        }

        $this->completeLogin($user);

        Logger::info('User logged in with MFA', ['user_id' => $user['id']]);

        return $this->redirect('/');
    }

    /**
     * POST /logout — log the user out.
     */
    public function logout(Request $request, array $vars): Response
    {
        $userId = $this->app->getSession()->get('user')['id'] ?? null;

        $this->app->getSession()->destroy();
        $this->app->getSession()->start();

        if ($userId !== null) {
            Logger::info('User logged out', ['user_id' => $userId]);
        }

        $this->flash('success', $this->t('auth.logged_out'));
        return $this->redirect('/login');
    }

    /**
     * GET /account — landing page for the logged-in user.
     *
     * If the user has a linked member record, redirects to the member view.
     * Otherwise renders a minimal account page with email and logout.
     */
    public function account(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $user = $this->app->getSession()->get('user');
        $memberId = (int) ($user['member_id'] ?? 0);

        // If the user is linked to a member record, send them there
        if ($memberId > 0) {
            return $this->redirect('/members/' . $memberId);
        }

        // Otherwise look it up in the DB (session may be stale)
        $row = $this->app->getDb()->fetchOne(
            "SELECT id FROM members WHERE user_id = :uid LIMIT 1",
            ['uid' => (int) $user['id']]
        );
        if ($row && (int) $row['id'] > 0) {
            return $this->redirect('/members/' . (int) $row['id']);
        }

        // No linked member — render a minimal account page
        return $this->render('@auth/auth/account.html.twig', [
            'email' => $user['email'] ?? '',
        ]);
    }

    /**
     * GET /forgot-password — show the forgot password form.
     */
    public function showForgotPassword(Request $request, array $vars): Response
    {
        if ($this->app->getSession()->isAuthenticated()) {
            return $this->redirect('/');
        }

        return $this->render('@auth/auth/forgot_password.html.twig');
    }

    /**
     * POST /forgot-password — process the forgot password form.
     */
    public function processForgotPassword(Request $request, array $vars): Response
    {
        $email = trim((string) $this->getParam('email', ''));

        if ($email !== '') {
            $token = $this->authService->createPasswordResetToken($email);

            if ($token !== null) {
                // TODO: Send reset email via PHPMailer (Step 2.1 follow-up)
                // For now, log the token for development/testing
                Logger::info('Password reset token generated', [
                    'email' => $email,
                    'token' => $token,
                ]);
            }
        }

        // Always show the same message to prevent email enumeration
        $this->flash('success', $this->t('auth.reset_sent'));
        return $this->redirect('/forgot-password');
    }

    /**
     * GET /reset-password/{token} — show the reset password form.
     */
    public function showResetPassword(Request $request, array $vars): Response
    {
        $token = $vars['token'] ?? '';
        $resetData = $this->authService->validateResetToken($token);

        if ($resetData === null) {
            $this->flash('error', $this->t('auth.reset_expired'));
            return $this->redirect('/forgot-password');
        }

        return $this->render('@auth/auth/reset_password.html.twig', [
            'token' => $token,
            'email' => $resetData['email'],
        ]);
    }

    /**
     * POST /reset-password/{token} — process the password reset.
     */
    public function processResetPassword(Request $request, array $vars): Response
    {
        $token = $vars['token'] ?? '';
        $resetData = $this->authService->validateResetToken($token);

        if ($resetData === null) {
            $this->flash('error', $this->t('auth.reset_expired'));
            return $this->redirect('/forgot-password');
        }

        $password = (string) $this->getParam('password', '');
        $passwordConfirm = (string) $this->getParam('password_confirm', '');

        $errors = $this->validatePassword($password, $passwordConfirm);

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->flash('error', $error);
            }
            return $this->render('@auth/auth/reset_password.html.twig', [
                'token' => $token,
                'email' => $resetData['email'],
            ]);
        }

        try {
            $this->authService->updatePassword($resetData['user_id'], $password, $token);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->render('@auth/auth/reset_password.html.twig', [
                'token' => $token,
                'email' => $resetData['email'],
            ]);
        }

        $this->flash('success', $this->t('auth.password_changed'));
        return $this->redirect('/login');
    }

    /**
     * Validate password and confirmation.
     *
     * @return array<string> Validation errors (empty if valid)
     */
    private function validatePassword(string $password, string $confirm): array
    {
        $errors = [];

        if (strlen($password) < AuthService::MIN_PASSWORD_LENGTH) {
            $errors[] = $this->t('auth.password_too_short', [
                'min' => (string) AuthService::MIN_PASSWORD_LENGTH,
            ]);
        }

        if ($password !== $confirm) {
            $errors[] = $this->t('auth.passwords_no_match');
        }

        return $errors;
    }

    /**
     * Complete the login process — set session data and record the session.
     */
    private function completeLogin(array $user): void
    {
        $this->app->getSession()->setUser($user);

        // Record session in user_sessions table
        $sessionId = session_id();
        if ($sessionId !== false && $sessionId !== '') {
            $request = $this->app->getRequest();
            try {
                $this->app->getDb()->insert('user_sessions', [
                    'id' => $sessionId,
                    'user_id' => $user['id'],
                    'ip_address' => $request->getClientIp(),
                    'user_agent' => substr($request->getHeader('User-Agent') ?? '', 0, 512),
                ]);
            } catch (\Throwable $e) {
                // Non-critical — log but don't block login
                Logger::warning('Failed to record user session', [
                    'user_id' => $user['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Translate a key using the app's i18n service.
     */
    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

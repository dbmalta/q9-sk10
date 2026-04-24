<?php

declare(strict_types=1);

namespace AppCore\Modules\Auth\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Encryption;
use AppCore\Core\Logger;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Auth\Services\AuthService;
use AppCore\Modules\Auth\Services\MfaService;

/**
 * Authentication controller.
 *
 * Handles login, logout, MFA verification, forgotten-password request,
 * and password reset. All routes render via the auth layout (centered card).
 */
class AuthController extends Controller
{
    private AuthService $authService;
    private MfaService $mfaService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $encryption = $this->loadEncryption();
        $this->authService = new AuthService($app->getDb(), $encryption);
        $this->mfaService  = new MfaService($app->getDb(), $encryption, (string) $app->getConfigValue('app.name', 'appCore'));
    }

    private function loadEncryption(): ?Encryption
    {
        $keyFile = ROOT_PATH . '/config/encryption.key';
        if (!file_exists($keyFile) || !is_readable($keyFile)) {
            return null;
        }
        try {
            return new Encryption($keyFile);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function showLogin(Request $request, array $vars): Response
    {
        if ($this->app->getSession()->isAuthenticated()) {
            return $this->redirect('/');
        }
        return $this->render('@auth/auth/login.html.twig');
    }

    public function processLogin(Request $request, array $vars): Response
    {
        $email = trim((string) $this->getParam('email', ''));
        $password = (string) $this->getParam('password', '');

        if ($email === '' || $password === '') {
            $this->flash('error', $this->t('auth.login_failed'));
            return $this->render('@auth/auth/login.html.twig', ['email' => $email]);
        }

        $user = $this->app->getDb()->fetchOne(
            "SELECT * FROM users WHERE email = :email",
            ['email' => strtolower($email)]
        );

        if ($user !== null && $this->authService->isLocked($user)) {
            $this->flash('error', $this->t('auth.login_locked'));
            return $this->render('@auth/auth/login.html.twig', ['email' => $email]);
        }

        $authenticated = $this->authService->authenticate($email, $password);
        if ($authenticated === null) {
            $this->flash('error', $this->t('auth.login_failed'));
            return $this->render('@auth/auth/login.html.twig', ['email' => $email]);
        }

        if ($authenticated['mfa_enabled']) {
            $this->app->getSession()->set('mfa_pending_user_id', $authenticated['id']);
            return $this->redirect('/login/mfa');
        }

        $this->app->getSession()->setUser($authenticated);
        Logger::info('User logged in', ['user_id' => $authenticated['id']]);

        return $this->redirect('/');
    }

    public function showMfa(Request $request, array $vars): Response
    {
        if ($this->app->getSession()->get('mfa_pending_user_id') === null) {
            return $this->redirect('/login');
        }
        return $this->render('@auth/auth/mfa.html.twig');
    }

    public function processMfa(Request $request, array $vars): Response
    {
        $pendingUserId = $this->app->getSession()->get('mfa_pending_user_id');
        if ($pendingUserId === null) {
            return $this->redirect('/login');
        }

        $code = trim((string) $this->getParam('code', ''));
        if ($code === '' || !$this->mfaService->verifyCode((int) $pendingUserId, $code)) {
            $this->flash('error', $this->t('auth.mfa_invalid'));
            return $this->render('@auth/auth/mfa.html.twig');
        }

        $this->app->getSession()->remove('mfa_pending_user_id');
        $user = $this->authService->getUserById((int) $pendingUserId);
        if ($user === null) {
            return $this->redirect('/login');
        }

        $this->app->getSession()->setUser($user);
        Logger::info('User logged in with MFA', ['user_id' => $user['id']]);

        return $this->redirect('/');
    }

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

    public function account(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }
        $user = $this->app->getSession()->get('user');
        return $this->render('@auth/auth/account.html.twig', [
            'email' => $user['email'] ?? '',
        ]);
    }

    public function showForgotPassword(Request $request, array $vars): Response
    {
        if ($this->app->getSession()->isAuthenticated()) {
            return $this->redirect('/');
        }
        return $this->render('@auth/auth/forgot.html.twig');
    }

    public function processForgotPassword(Request $request, array $vars): Response
    {
        $email = trim((string) $this->getParam('email', ''));

        if ($email !== '') {
            $token = $this->authService->createPasswordResetToken($email);
            if ($token !== null) {
                Logger::info('Password reset token generated', [
                    'email' => $email,
                    'token' => $token,
                ]);
                // Operators wire email dispatch via PHPMailer at the project level.
            }
        }

        $this->flash('success', $this->t('auth.reset_sent'));
        return $this->redirect('/forgot-password');
    }

    public function showResetPassword(Request $request, array $vars): Response
    {
        $token = $vars['token'] ?? '';
        $data = $this->authService->validateResetToken($token);
        if ($data === null) {
            $this->flash('error', $this->t('auth.reset_expired'));
            return $this->redirect('/forgot-password');
        }
        return $this->render('@auth/auth/reset.html.twig', [
            'token' => $token,
            'email' => $data['email'],
        ]);
    }

    public function processResetPassword(Request $request, array $vars): Response
    {
        $token = $vars['token'] ?? '';
        $data = $this->authService->validateResetToken($token);
        if ($data === null) {
            $this->flash('error', $this->t('auth.reset_expired'));
            return $this->redirect('/forgot-password');
        }

        $password = (string) $this->getParam('password', '');
        $confirm  = (string) $this->getParam('password_confirm', '');

        $errors = [];
        if (strlen($password) < AuthService::MIN_PASSWORD_LENGTH) {
            $errors[] = $this->t('auth.password_too_short', ['min' => (string) AuthService::MIN_PASSWORD_LENGTH]);
        }
        if ($password !== $confirm) {
            $errors[] = $this->t('auth.passwords_no_match');
        }

        if (!empty($errors)) {
            foreach ($errors as $err) {
                $this->flash('error', $err);
            }
            return $this->render('@auth/auth/reset.html.twig', ['token' => $token, 'email' => $data['email']]);
        }

        try {
            $this->authService->updatePassword($data['user_id'], $password, $token);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->render('@auth/auth/reset.html.twig', ['token' => $token, 'email' => $data['email']]);
        }

        $this->flash('success', $this->t('auth.password_changed'));
        return $this->redirect('/login');
    }
}

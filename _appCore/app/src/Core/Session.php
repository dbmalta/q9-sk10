<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Session manager with secure defaults.
 *
 * File-based sessions stored in /var/sessions/. HttpOnly, SameSite=Lax,
 * Secure-if-HTTPS cookies. Timeout enforced from config.security.session_timeout.
 */
class Session
{
    private array $config;
    private bool $started = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $sessionPath = ROOT_PATH . '/var/sessions';
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0700, true);
        }

        ini_set('session.save_path', $sessionPath);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) ($this->config['security']['session_timeout'] ?? 7200));

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
        $this->started = true;

        $this->checkTimeout();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->started = false;
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /**
     * @return array<string, array<string>>
     */
    public function getFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user']) && isset($_SESSION['user']['id']);
    }

    public function getUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function setUser(array $user): void
    {
        $_SESSION['user'] = $user;
        $_SESSION['_last_activity'] = time();
        $this->regenerate();
    }

    public function regenerate(): void
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function checkTimeout(): void
    {
        $timeout = (int) ($this->config['security']['session_timeout'] ?? 7200);
        $lastActivity = $_SESSION['_last_activity'] ?? null;

        if ($lastActivity !== null && (time() - $lastActivity) > $timeout) {
            $this->destroy();
            $this->start();
            return;
        }

        if ($this->isAuthenticated()) {
            $_SESSION['_last_activity'] = time();
        }
    }
}

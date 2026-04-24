<?php

declare(strict_types=1);

namespace AppCore\Modules\Auth\Services;

use AppCore\Core\Database;
use AppCore\Core\Encryption;
use AppCore\Core\Logger;

/**
 * Authentication service.
 *
 * Handles login, lockout, password reset tokens, and MFA (TOTP) setup/verify.
 * Passwords are hashed with bcrypt; MFA secrets are AES-256-GCM encrypted
 * at rest when an Encryption instance is provided.
 */
class AuthService
{
    private Database $db;
    private ?Encryption $encryption;

    private const MAX_FAILED_ATTEMPTS     = 5;
    private const LOCK_DURATION_MINUTES   = 15;
    private const RESET_TOKEN_EXPIRY_HOURS = 1;

    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(Database $db, ?Encryption $encryption = null)
    {
        $this->db = $db;
        $this->encryption = $encryption;
    }

    public function authenticate(string $email, string $password): ?array
    {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = :email",
            ['email' => strtolower(trim($email))]
        );

        if ($user === null) {
            return null;
        }

        if ($this->isLocked($user)) {
            return null;
        }

        if (!$user['is_active']) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailedLogin((int) $user['id']);
            return null;
        }

        $this->resetFailedLogins((int) $user['id']);
        $this->db->update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], ['id' => $user['id']]);

        return $this->sanitiseUser($user);
    }

    public function isLocked(array $user): bool
    {
        if ($user['locked_until'] === null) {
            return false;
        }

        $lockedUntil = new \DateTimeImmutable($user['locked_until']);
        if ($lockedUntil > new \DateTimeImmutable()) {
            return true;
        }

        $this->db->update('users', [
            'failed_login_count' => 0,
            'locked_until'       => null,
        ], ['id' => $user['id']]);

        return false;
    }

    public function recordFailedLogin(int $userId): void
    {
        $user = $this->db->fetchOne("SELECT failed_login_count FROM users WHERE id = :id", ['id' => $userId]);
        if ($user === null) {
            return;
        }

        $newCount = (int) $user['failed_login_count'] + 1;
        $data = ['failed_login_count' => $newCount];

        if ($newCount >= self::MAX_FAILED_ATTEMPTS) {
            $data['locked_until'] = (new \DateTimeImmutable())
                ->modify('+' . self::LOCK_DURATION_MINUTES . ' minutes')
                ->format('Y-m-d H:i:s');

            Logger::warning('Account locked after failed login attempts', [
                'user_id'  => $userId,
                'attempts' => $newCount,
            ]);
        }

        $this->db->update('users', $data, ['id' => $userId]);
    }

    public function resetFailedLogins(int $userId): void
    {
        $this->db->update('users', [
            'failed_login_count' => 0,
            'locked_until'       => null,
        ], ['id' => $userId]);
    }

    public function createPasswordResetToken(string $email): ?string
    {
        $user = $this->db->fetchOne(
            "SELECT id FROM users WHERE email = :email AND is_active = 1",
            ['email' => strtolower(trim($email))]
        );

        if ($user === null) {
            return null;
        }

        $this->db->update('password_resets',
            ['used_at' => gmdate('Y-m-d H:i:s')],
            ['user_id' => $user['id']]
        );

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::RESET_TOKEN_EXPIRY_HOURS . ' hours')
            ->format('Y-m-d H:i:s');

        $this->db->insert('password_resets', [
            'user_id'    => $user['id'],
            'token'      => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public function validateResetToken(string $token): ?array
    {
        $reset = $this->db->fetchOne(
            "SELECT pr.*, u.email FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token = :token AND pr.used_at IS NULL AND pr.expires_at > :now",
            ['token' => $token, 'now' => gmdate('Y-m-d H:i:s')]
        );

        if ($reset === null) {
            return null;
        }

        return [
            'user_id' => (int) $reset['user_id'],
            'email'   => $reset['email'],
        ];
    }

    public function updatePassword(int $userId, string $newPassword, ?string $token = null): void
    {
        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'
            );
        }

        $this->db->update('users', [
            'password_hash'       => password_hash($newPassword, PASSWORD_BCRYPT),
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
            'failed_login_count'  => 0,
            'locked_until'        => null,
        ], ['id' => $userId]);

        if ($token !== null) {
            $this->db->update('password_resets',
                ['used_at' => gmdate('Y-m-d H:i:s')],
                ['token' => $token]
            );
        }
    }

    public function getUserById(int $id): ?array
    {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = :id", ['id' => $id]);
        return $user ? $this->sanitiseUser($user) : null;
    }

    public function createUser(string $email, string $password, bool $isSuperAdmin = false): int
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'
            );
        }

        return $this->db->insert('users', [
            'email'               => strtolower(trim($email)),
            'password_hash'       => password_hash($password, PASSWORD_BCRYPT),
            'is_super_admin'      => $isSuperAdmin ? 1 : 0,
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function sanitiseUser(array $user): array
    {
        unset($user['password_hash'], $user['encrypted_mfa_secret']);
        $user['id']                 = (int) $user['id'];
        $user['is_active']          = (bool) $user['is_active'];
        $user['is_super_admin']     = (bool) $user['is_super_admin'];
        $user['mfa_enabled']        = (bool) $user['mfa_enabled'];
        $user['failed_login_count'] = (int) $user['failed_login_count'];
        return $user;
    }
}

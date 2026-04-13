<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Database;
use App\Core\Encryption;
use App\Core\Logger;

/**
 * Authentication service.
 *
 * Handles user authentication, account locking, password resets,
 * and MFA (TOTP) setup and verification. Passwords are hashed with
 * bcrypt; MFA secrets are encrypted at rest.
 */
class AuthService
{
    private Database $db;
    private ?Encryption $encryption;

    /** Maximum failed login attempts before locking */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** Lock duration in minutes */
    private const LOCK_DURATION_MINUTES = 15;

    /** Password reset token expiry in hours */
    private const RESET_TOKEN_EXPIRY_HOURS = 1;

    /** Minimum password length (NIST guidance) */
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(Database $db, ?Encryption $encryption = null)
    {
        $this->db = $db;
        $this->encryption = $encryption;
    }

    /**
     * Authenticate a user by email and password.
     *
     * @param string $email User's email address
     * @param string $password Plain-text password
     * @return array|null User data array on success, null on failure
     */
    public function authenticate(string $email, string $password): ?array
    {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = :email",
            ['email' => strtolower(trim($email))]
        );

        if ($user === null) {
            return null;
        }

        // Check if account is locked
        if ($this->isLocked($user)) {
            return null;
        }

        // Check if account is active
        if (!$user['is_active']) {
            return null;
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailedLogin($user['id']);
            return null;
        }

        // Reset failed attempts on successful login
        $this->resetFailedLogins($user['id']);

        // Update last login timestamp
        $this->db->update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], ['id' => $user['id']]);

        // Return safe user data (no password hash or MFA secret)
        return $this->sanitiseUser($user);
    }

    /**
     * Check if a user account is currently locked.
     *
     * @param array $user User record from database
     * @return bool True if locked
     */
    public function isLocked(array $user): bool
    {
        if ($user['locked_until'] === null) {
            return false;
        }

        $lockedUntil = new \DateTimeImmutable($user['locked_until']);
        if ($lockedUntil > new \DateTimeImmutable()) {
            return true;
        }

        // Lock has expired — reset
        $this->db->update('users', [
            'failed_login_count' => 0,
            'locked_until' => null,
        ], ['id' => $user['id']]);

        return false;
    }

    /**
     * Record a failed login attempt. Locks the account after MAX_FAILED_ATTEMPTS.
     *
     * @param int $userId The user ID
     */
    public function recordFailedLogin(int $userId): void
    {
        $user = $this->db->fetchOne("SELECT failed_login_count FROM users WHERE id = :id", ['id' => $userId]);
        if ($user === null) {
            return;
        }

        $newCount = (int) $user['failed_login_count'] + 1;
        $data = ['failed_login_count' => $newCount];

        if ($newCount >= self::MAX_FAILED_ATTEMPTS) {
            $lockUntil = (new \DateTimeImmutable())
                ->modify('+' . self::LOCK_DURATION_MINUTES . ' minutes')
                ->format('Y-m-d H:i:s');
            $data['locked_until'] = $lockUntil;

            Logger::warning('Account locked after failed login attempts', [
                'user_id' => $userId,
                'attempts' => $newCount,
            ]);
        }

        $this->db->update('users', $data, ['id' => $userId]);
    }

    /**
     * Reset the failed login counter for a user.
     */
    public function resetFailedLogins(int $userId): void
    {
        $this->db->update('users', [
            'failed_login_count' => 0,
            'locked_until' => null,
        ], ['id' => $userId]);
    }

    /**
     * Create a password reset token.
     *
     * @param string $email User email
     * @return string|null The reset token, or null if user not found
     */
    public function createPasswordResetToken(string $email): ?string
    {
        $user = $this->db->fetchOne(
            "SELECT id FROM users WHERE email = :email AND is_active = 1",
            ['email' => strtolower(trim($email))]
        );

        if ($user === null) {
            return null;
        }

        // Invalidate any existing tokens for this user
        $this->db->update('password_resets', [
            'used_at' => gmdate('Y-m-d H:i:s'),
        ], ['user_id' => $user['id']]);

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . self::RESET_TOKEN_EXPIRY_HOURS . ' hours')
            ->format('Y-m-d H:i:s');

        $this->db->insert('password_resets', [
            'user_id' => $user['id'],
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Validate a password reset token.
     *
     * @param string $token The reset token
     * @return array|null The user data if token is valid, null otherwise
     */
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
            'email' => $reset['email'],
        ];
    }

    /**
     * Update a user's password and mark the reset token as used.
     *
     * @param int $userId User ID
     * @param string $newPassword The new plain-text password
     * @param string|null $token The reset token to mark as used
     * @throws \InvalidArgumentException if password too short
     */
    public function updatePassword(int $userId, string $newPassword, ?string $token = null): void
    {
        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'
            );
        }

        $this->db->update('users', [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
            'failed_login_count' => 0,
            'locked_until' => null,
        ], ['id' => $userId]);

        if ($token !== null) {
            $this->db->update('password_resets', [
                'used_at' => gmdate('Y-m-d H:i:s'),
            ], ['token' => $token]);
        }
    }

    /**
     * Set up MFA for a user — generates a secret and returns it
     * along with QR code data for authenticator apps.
     *
     * @param int $userId User ID
     * @return array{secret: string, qr_url: string}
     */
    public function setupMfa(int $userId): array
    {
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $secret = $google2fa->generateSecretKey();

        // Encrypt the secret before storing
        $encryptedSecret = $this->encryption
            ? $this->encryption->encrypt($secret)
            : $secret;

        $this->db->update('users', [
            'mfa_secret' => $encryptedSecret,
        ], ['id' => $userId]);

        $user = $this->db->fetchOne("SELECT email FROM users WHERE id = :id", ['id' => $userId]);

        $qrUrl = $google2fa->getQRCodeUrl(
            'ScoutKeeper',
            $user['email'] ?? 'user',
            $secret
        );

        return [
            'secret' => $secret,
            'qr_url' => $qrUrl,
        ];
    }

    /**
     * Verify a TOTP MFA code.
     *
     * @param int $userId User ID
     * @param string $code The 6-digit TOTP code
     * @return bool True if the code is valid
     */
    public function verifyMfaCode(int $userId, string $code): bool
    {
        $user = $this->db->fetchOne(
            "SELECT mfa_secret FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if ($user === null || $user['mfa_secret'] === null) {
            return false;
        }

        $secret = $this->encryption
            ? $this->encryption->decrypt($user['mfa_secret'])
            : $user['mfa_secret'];

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        return $google2fa->verifyKey($secret, $code);
    }

    /**
     * Enable MFA for a user (after they've verified it works).
     */
    public function enableMfa(int $userId): void
    {
        $this->db->update('users', ['mfa_enabled' => 1], ['id' => $userId]);
    }

    /**
     * Disable MFA for a user.
     */
    public function disableMfa(int $userId): void
    {
        $this->db->update('users', [
            'mfa_enabled' => 0,
            'mfa_secret' => null,
        ], ['id' => $userId]);
    }

    /**
     * Get a user by ID (safe data only).
     */
    public function getUserById(int $id): ?array
    {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = :id", ['id' => $id]);
        return $user ? $this->sanitiseUser($user) : null;
    }

    /**
     * Get a user by email.
     */
    public function getUserByEmail(string $email): ?array
    {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = :email",
            ['email' => strtolower(trim($email))]
        );
        return $user ? $this->sanitiseUser($user) : null;
    }

    /**
     * Create a new user account.
     *
     * @param string $email Email address
     * @param string $password Plain-text password
     * @param bool $isSuperAdmin Whether this is a super admin account
     * @return int The new user ID
     */
    public function createUser(string $email, string $password, bool $isSuperAdmin = false): int
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'
            );
        }

        return $this->db->insert('users', [
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'is_super_admin' => $isSuperAdmin ? 1 : 0,
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Strip sensitive fields from a user record.
     */
    private function sanitiseUser(array $user): array
    {
        unset($user['password_hash'], $user['mfa_secret']);
        $user['id'] = (int) $user['id'];
        $user['is_active'] = (bool) $user['is_active'];
        $user['is_super_admin'] = (bool) $user['is_super_admin'];
        $user['mfa_enabled'] = (bool) $user['mfa_enabled'];
        $user['failed_login_count'] = (int) $user['failed_login_count'];
        return $user;
    }
}

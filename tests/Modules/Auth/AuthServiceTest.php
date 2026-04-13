<?php

declare(strict_types=1);

namespace Tests\Modules\Auth;

use App\Core\Database;
use App\Modules\Auth\Services\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * AuthService tests — require a running MySQL test database.
 *
 * Tests cover authentication, account locking, password reset tokens,
 * password updates, and user creation. MFA tests are skipped unless
 * the pragmarx/google2fa package is available.
 */
class AuthServiceTest extends TestCase
{
    private ?Database $db = null;
    private ?AuthService $service = null;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Drop tables in dependency order (other tests may leave FK references)
        $this->db->query("DROP TABLE IF EXISTS `member_attachments`");
        $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignment_scopes`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignments`");
        $this->db->query("DROP TABLE IF EXISTS `roles`");
        $this->db->query("DROP TABLE IF EXISTS `user_sessions`");
        $this->db->query("DROP TABLE IF EXISTS `password_resets`");
        $this->db->query("DROP TABLE IF EXISTS `users`");

        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `mfa_secret` TEXT NULL,
                `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `last_login_at` DATETIME NULL,
                `password_changed_at` DATETIME NULL,
                `failed_login_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `locked_until` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `password_resets` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->service = new AuthService($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query("DROP TABLE IF EXISTS `password_resets`");
            $this->db->query("DROP TABLE IF EXISTS `users`");
        }
    }

    // ──── User creation ────

    public function testCreateUserReturnsId(): void
    {
        $id = $this->service->createUser('admin@example.com', 'securepassword123');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateUserStoresLowercaseEmail(): void
    {
        $id = $this->service->createUser('Admin@Example.COM', 'securepassword123');
        $user = $this->db->fetchOne("SELECT email FROM users WHERE id = :id", ['id' => $id]);
        $this->assertSame('admin@example.com', $user['email']);
    }

    public function testCreateUserHashesPassword(): void
    {
        $id = $this->service->createUser('user@example.com', 'securepassword123');
        $user = $this->db->fetchOne("SELECT password_hash FROM users WHERE id = :id", ['id' => $id]);
        $this->assertTrue(password_verify('securepassword123', $user['password_hash']));
        $this->assertNotSame('securepassword123', $user['password_hash']);
    }

    public function testCreateUserRejectsTooShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->createUser('user@example.com', 'short');
    }

    public function testCreateSuperAdmin(): void
    {
        $id = $this->service->createUser('admin@example.com', 'securepassword123', true);
        $user = $this->db->fetchOne("SELECT is_super_admin FROM users WHERE id = :id", ['id' => $id]);
        $this->assertSame(1, (int) $user['is_super_admin']);
    }

    // ──── Authentication ────

    public function testAuthenticateWithCorrectCredentials(): void
    {
        $this->service->createUser('login@example.com', 'correcthorse123');
        $user = $this->service->authenticate('login@example.com', 'correcthorse123');

        $this->assertNotNull($user);
        $this->assertSame('login@example.com', $user['email']);
        $this->assertArrayNotHasKey('password_hash', $user);
        $this->assertArrayNotHasKey('mfa_secret', $user);
    }

    public function testAuthenticateWithWrongPassword(): void
    {
        $this->service->createUser('login@example.com', 'correcthorse123');
        $user = $this->service->authenticate('login@example.com', 'wrongpassword1');

        $this->assertNull($user);
    }

    public function testAuthenticateWithNonexistentEmail(): void
    {
        $user = $this->service->authenticate('nobody@example.com', 'whatever123');
        $this->assertNull($user);
    }

    public function testAuthenticateCaseInsensitiveEmail(): void
    {
        $this->service->createUser('Login@Example.COM', 'correcthorse123');
        $user = $this->service->authenticate('login@example.com', 'correcthorse123');
        $this->assertNotNull($user);
    }

    public function testAuthenticateUpdatesLastLogin(): void
    {
        $id = $this->service->createUser('login@example.com', 'correcthorse123');
        $this->service->authenticate('login@example.com', 'correcthorse123');

        $user = $this->db->fetchOne("SELECT last_login_at FROM users WHERE id = :id", ['id' => $id]);
        $this->assertNotNull($user['last_login_at']);
    }

    public function testAuthenticateInactiveAccountFails(): void
    {
        $id = $this->service->createUser('inactive@example.com', 'correcthorse123');
        $this->db->update('users', ['is_active' => 0], ['id' => $id]);

        $user = $this->service->authenticate('inactive@example.com', 'correcthorse123');
        $this->assertNull($user);
    }

    // ──── Account locking ────

    public function testFailedLoginsIncrementCounter(): void
    {
        $id = $this->service->createUser('lock@example.com', 'correcthorse123');

        $this->service->authenticate('lock@example.com', 'wrongpassword1');
        $user = $this->db->fetchOne("SELECT failed_login_count FROM users WHERE id = :id", ['id' => $id]);
        $this->assertSame(1, (int) $user['failed_login_count']);

        $this->service->authenticate('lock@example.com', 'wrongpassword1');
        $user = $this->db->fetchOne("SELECT failed_login_count FROM users WHERE id = :id", ['id' => $id]);
        $this->assertSame(2, (int) $user['failed_login_count']);
    }

    public function testAccountLocksAfterMaxFailedAttempts(): void
    {
        $id = $this->service->createUser('lock@example.com', 'correcthorse123');

        for ($i = 0; $i < 5; $i++) {
            $this->service->authenticate('lock@example.com', 'wrongpassword1');
        }

        $user = $this->db->fetchOne("SELECT locked_until FROM users WHERE id = :id", ['id' => $id]);
        $this->assertNotNull($user['locked_until']);
    }

    public function testLockedAccountCannotLogin(): void
    {
        $id = $this->service->createUser('lock@example.com', 'correcthorse123');

        for ($i = 0; $i < 5; $i++) {
            $this->service->authenticate('lock@example.com', 'wrongpassword1');
        }

        // Even with correct password, locked account returns null
        $user = $this->service->authenticate('lock@example.com', 'correcthorse123');
        $this->assertNull($user);
    }

    public function testSuccessfulLoginResetsFailedCount(): void
    {
        $id = $this->service->createUser('reset@example.com', 'correcthorse123');

        // Fail a couple of times
        $this->service->authenticate('reset@example.com', 'wrongpassword1');
        $this->service->authenticate('reset@example.com', 'wrongpassword1');

        // Succeed
        $this->service->authenticate('reset@example.com', 'correcthorse123');

        $user = $this->db->fetchOne("SELECT failed_login_count FROM users WHERE id = :id", ['id' => $id]);
        $this->assertSame(0, (int) $user['failed_login_count']);
    }

    // ──── Password reset tokens ────

    public function testCreateResetTokenReturnsToken(): void
    {
        $this->service->createUser('reset@example.com', 'correcthorse123');
        $token = $this->service->createPasswordResetToken('reset@example.com');

        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testCreateResetTokenForNonexistentEmailReturnsNull(): void
    {
        $token = $this->service->createPasswordResetToken('nobody@example.com');
        $this->assertNull($token);
    }

    public function testCreateResetTokenInvalidatesPrevious(): void
    {
        $this->service->createUser('reset@example.com', 'correcthorse123');

        $token1 = $this->service->createPasswordResetToken('reset@example.com');
        $token2 = $this->service->createPasswordResetToken('reset@example.com');

        // First token should be invalidated
        $this->assertNull($this->service->validateResetToken($token1));
        // Second token should be valid
        $this->assertNotNull($this->service->validateResetToken($token2));
    }

    public function testValidateResetTokenReturnsUserData(): void
    {
        $this->service->createUser('reset@example.com', 'correcthorse123');
        $token = $this->service->createPasswordResetToken('reset@example.com');

        $data = $this->service->validateResetToken($token);
        $this->assertNotNull($data);
        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertSame('reset@example.com', $data['email']);
    }

    public function testValidateResetTokenRejectsInvalidToken(): void
    {
        $data = $this->service->validateResetToken('0000000000000000000000000000000000000000000000000000000000000000');
        $this->assertNull($data);
    }

    // ──── Password update ────

    public function testUpdatePasswordChangesHash(): void
    {
        $id = $this->service->createUser('update@example.com', 'oldpassword123');
        $this->service->updatePassword($id, 'newpassword456');

        // Old password no longer works
        $this->assertNull($this->service->authenticate('update@example.com', 'oldpassword123'));
        // New password works
        $this->assertNotNull($this->service->authenticate('update@example.com', 'newpassword456'));
    }

    public function testUpdatePasswordResetsLockout(): void
    {
        $id = $this->service->createUser('locked@example.com', 'correcthorse123');

        // Lock the account
        for ($i = 0; $i < 5; $i++) {
            $this->service->recordFailedLogin($id);
        }

        // Update password should clear lock
        $this->service->updatePassword($id, 'newpassword456');

        $user = $this->db->fetchOne("SELECT failed_login_count, locked_until FROM users WHERE id = :id", ['id' => $id]);
        $this->assertSame(0, (int) $user['failed_login_count']);
        $this->assertNull($user['locked_until']);
    }

    public function testUpdatePasswordRejectsTooShort(): void
    {
        $id = $this->service->createUser('short@example.com', 'correcthorse123');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updatePassword($id, 'short');
    }

    public function testUpdatePasswordMarksTokenAsUsed(): void
    {
        $this->service->createUser('token@example.com', 'correcthorse123');
        $token = $this->service->createPasswordResetToken('token@example.com');
        $data = $this->service->validateResetToken($token);

        $this->service->updatePassword($data['user_id'], 'newpassword456', $token);

        // Token should no longer be valid
        $this->assertNull($this->service->validateResetToken($token));
    }

    // ──── User retrieval ────

    public function testGetUserById(): void
    {
        $id = $this->service->createUser('get@example.com', 'correcthorse123');
        $user = $this->service->getUserById($id);

        $this->assertNotNull($user);
        $this->assertSame('get@example.com', $user['email']);
        $this->assertArrayNotHasKey('password_hash', $user);
    }

    public function testGetUserByIdNotFound(): void
    {
        $user = $this->service->getUserById(99999);
        $this->assertNull($user);
    }

    public function testGetUserByEmail(): void
    {
        $this->service->createUser('find@example.com', 'correcthorse123');
        $user = $this->service->getUserByEmail('Find@Example.COM');

        $this->assertNotNull($user);
        $this->assertSame('find@example.com', $user['email']);
    }

    public function testGetUserByEmailNotFound(): void
    {
        $user = $this->service->getUserByEmail('nobody@example.com');
        $this->assertNull($user);
    }

    // ──── Sanitisation ────

    public function testSanitisedUserCastsTypes(): void
    {
        $id = $this->service->createUser('types@example.com', 'correcthorse123');
        $user = $this->service->getUserById($id);

        $this->assertIsBool($user['is_active']);
        $this->assertIsBool($user['is_super_admin']);
        $this->assertIsBool($user['mfa_enabled']);
        $this->assertIsInt($user['id']);
        $this->assertIsInt($user['failed_login_count']);
    }
}

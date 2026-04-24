<?php

declare(strict_types=1);

namespace AppCore\Modules\Auth\Services;

use AppCore\Core\Database;
use AppCore\Core\Encryption;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP MFA service. Secrets are encrypted at rest via the Encryption
 * service (AES-256-GCM).
 */
class MfaService
{
    private Database $db;
    private ?Encryption $encryption;
    private string $issuer;

    public function __construct(Database $db, ?Encryption $encryption, string $issuer = 'appCore')
    {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->issuer = $issuer;
    }

    /**
     * Generate a new secret for the user and return QR-code provisioning data.
     *
     * @return array{secret: string, qr_url: string}
     */
    public function setup(int $userId): array
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $stored = $this->encryption ? $this->encryption->encrypt($secret) : $secret;
        $this->db->update('users', ['encrypted_mfa_secret' => $stored], ['id' => $userId]);

        $user = $this->db->fetchOne("SELECT email FROM users WHERE id = :id", ['id' => $userId]);

        $qrUrl = $google2fa->getQRCodeUrl(
            $this->issuer,
            $user['email'] ?? 'user',
            $secret
        );

        return ['secret' => $secret, 'qr_url' => $qrUrl];
    }

    public function verifyCode(int $userId, string $code): bool
    {
        $user = $this->db->fetchOne(
            "SELECT encrypted_mfa_secret FROM users WHERE id = :id",
            ['id' => $userId]
        );

        if ($user === null || $user['encrypted_mfa_secret'] === null) {
            return false;
        }

        $secret = $this->encryption
            ? $this->encryption->decrypt($user['encrypted_mfa_secret'])
            : $user['encrypted_mfa_secret'];

        return (new Google2FA())->verifyKey($secret, $code);
    }

    public function enable(int $userId): void
    {
        $this->db->update('users', ['mfa_enabled' => 1], ['id' => $userId]);
    }

    public function disable(int $userId): void
    {
        $this->db->update('users', [
            'mfa_enabled'          => 0,
            'encrypted_mfa_secret' => null,
        ], ['id' => $userId]);
    }
}

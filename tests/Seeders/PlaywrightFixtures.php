<?php

declare(strict_types=1);

namespace Tests\Seeders;

/**
 * Known-state user credentials and identifiers for Playwright E2E tests.
 *
 * All test users are created by NorthlandSeeder with password 'TestPass123!'.
 */
class PlaywrightFixtures
{
    public const PASSWORD = 'TestPass123!';

    /** Super admin — full access */
    public const ADMIN_EMAIL = 'admin@northland.test';

    /** Group leader — mid-level access, can publish events */
    public const LEADER_EMAIL = 'leader@northland.test';

    /** Basic member — read-only access */
    public const MEMBER_EMAIL = 'member@northland.test';

    /** User who has NOT accepted the latest T&Cs — triggers acceptance flow */
    public const PENDING_TERMS_EMAIL = 'pending@northland.test';

    /** User with MFA enabled — triggers TOTP verification */
    public const MFA_EMAIL = 'mfa@northland.test';

    /**
     * Well-known TOTP secret for the MFA test user (base32 encoded).
     * Use this with an OTP library to generate valid codes in tests.
     */
    public const MFA_SECRET = 'JBSWY3DPEHPK3PXP';

    /**
     * Return all fixture users as an associative array.
     */
    public static function getUsers(): array
    {
        return [
            'admin'         => ['email' => self::ADMIN_EMAIL,         'password' => self::PASSWORD, 'role' => 'Super Admin'],
            'leader'        => ['email' => self::LEADER_EMAIL,        'password' => self::PASSWORD, 'role' => 'Group Leader'],
            'member'        => ['email' => self::MEMBER_EMAIL,        'password' => self::PASSWORD, 'role' => 'Member'],
            'pending_terms' => ['email' => self::PENDING_TERMS_EMAIL, 'password' => self::PASSWORD, 'role' => 'Member (pending T&Cs)'],
            'mfa'           => ['email' => self::MFA_EMAIL,           'password' => self::PASSWORD, 'role' => 'Member (MFA enabled)', 'totp_secret' => self::MFA_SECRET],
        ];
    }
}

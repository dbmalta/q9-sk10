<?php

declare(strict_types=1);

namespace AppCore\Modules\Auth\Services;

/**
 * Thin wrapper that delegates to AuthService's reset-token helpers.
 *
 * Exists so the controller layer can depend on a service whose surface is
 * scoped to password resets (token create, token validate, apply new
 * password), without knowing about all the login/MFA responsibilities of
 * AuthService. Delegation keeps logic in one place.
 */
class PasswordResetService
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
    }

    public function create(string $email): ?string
    {
        return $this->auth->createPasswordResetToken($email);
    }

    /**
     * @return array{user_id: int, email: string}|null
     */
    public function validate(string $token): ?array
    {
        return $this->auth->validateResetToken($token);
    }

    public function apply(int $userId, string $newPassword, string $token): void
    {
        $this->auth->updatePassword($userId, $newPassword, $token);
    }
}

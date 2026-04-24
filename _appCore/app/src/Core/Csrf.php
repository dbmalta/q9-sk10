<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * CSRF token manager.
 *
 * Per-session tokens (64 hex chars from 32 random bytes). Submitted tokens
 * are compared with hash_equals(). Accepts tokens via form input
 * `_csrf_token` or via the `X-CSRF-Token` header.
 */
class Csrf
{
    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    public function getToken(): string
    {
        $token = $this->session->get('_csrf_token');
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf_token', $token);
        }
        return $token;
    }

    public function validateToken(string $submittedToken): bool
    {
        $sessionToken = $this->session->get('_csrf_token');
        if ($sessionToken === null || $submittedToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $submittedToken);
    }

    public function field(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }
}

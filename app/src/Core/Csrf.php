<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF token manager.
 *
 * Generates and validates per-session CSRF tokens.
 * Tokens are sent as hidden form fields and as HTMX request headers.
 */
class Csrf
{
    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Get the current CSRF token, generating one if it doesn't exist.
     */
    public function getToken(): string
    {
        $token = $this->session->get('_csrf_token');
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf_token', $token);
        }
        return $token;
    }

    /**
     * Validate a submitted token against the session token.
     *
     * @param string $submittedToken The token from the request
     * @return bool True if the token is valid
     */
    public function validateToken(string $submittedToken): bool
    {
        $sessionToken = $this->session->get('_csrf_token');
        if ($sessionToken === null || $submittedToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $submittedToken);
    }

    /**
     * Generate the HTML hidden input field for forms.
     */
    public function field(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }
}

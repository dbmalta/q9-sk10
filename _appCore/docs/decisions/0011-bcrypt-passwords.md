# ADR-0011 — Bcrypt for password hashing

**Status:** Accepted

## Context
PHP ships two password-hashing algorithms via the `password_*` API: bcrypt (default) and argon2id (since PHP 7.2, if compiled with the extension). Argon2id is memory-hard and generally considered stronger. Bcrypt is older but universally available, well-understood, and still adequate with appropriate cost.

## Decision
Use `password_hash($plain, PASSWORD_BCRYPT)` for new passwords. Cost defaults to PHP's (currently 12). Do not specify `PASSWORD_ARGON2ID` — the libsodium/argon2 extension is not guaranteed on shared hosting and a hash algorithm mismatch between hosts is a painful migration.

## Consequences
- Works on every shared-hosting PHP install out of the box.
- Hash format (`$2y$12$...`) is portable between hosts.
- Bcrypt's 72-byte plaintext limit is not a practical concern for typical passwords; pre-hash with SHA-256 if a project ever needs longer.
- Future migration to argon2id is straightforward: `password_needs_rehash()` during login flow upgrades hashes opportunistically.

## Alternatives considered
- **Argon2id** — stronger, but environmental availability is the binding constraint.
- **scrypt** — not in PHP core.
- **PBKDF2** — weaker than bcrypt/argon2 for password use; no reason to pick it.
- **Plain SHA-256 with salt** — not a password hash; unacceptable.

# ADR-0009 — AES-256-GCM for at-rest encryption

**Status:** Accepted

## Context
Some fields hold data that must stay confidential even if the database is leaked: medical notes, financial info, MFA secrets, personal identifiers in certain regulatory regimes. Database-level TDE isn't available on shared hosting, so field-level application encryption is the fallback.

## Decision
Use **AES-256-GCM** (authenticated encryption with associated data) via PHP's `openssl_encrypt`/`decrypt`. The `Encryption` class handles:

- Key loaded from `/config/encryption.key` — exactly 32 bytes (256 bits), file perms 0600.
- Random 12-byte IV per encrypt call.
- Output format: base64(IV || tag || ciphertext).
- Decrypt validates the GCM tag — tampered ciphertext is rejected, not silently "decrypted" to garbage.

## Consequences
- Strong confidentiality + integrity. An attacker with DB access but no key file cannot read or forge encrypted columns.
- Single key. No rotation mechanism. Rotating means writing a migration that re-encrypts every row — non-trivial, and not built into core.
- Losing `/config/encryption.key` renders all encrypted data unrecoverable. Operators must back this up separately from the database.
- Encrypted columns are not indexable or searchable. Schemas must plan for this (e.g. store a salted hash alongside if you need equality lookup).
- CPU cost is negligible for the volumes involved in this app class.

## Alternatives considered
- **AES-256-CBC + HMAC** — equivalent security if done right, but easy to get wrong. GCM bundles MAC correctly by construction.
- **libsodium's `crypto_secretbox`** — also fine; we chose OpenSSL because it's built into PHP on every shared host without extension gymnastics.
- **Database-level encryption** — not available on the deployment target.
- **Envelope encryption with KMS** — overkill; not shared-host friendly.

# ADR-0008 — JSON permissions blob on roles

**Status:** Accepted

## Context
A permission system needs to associate roles with the set of keys they grant. Options:

1. Normalised: `role_permissions (role_id, permission_key)` — one row per grant.
2. JSON column: `roles.permissions = {"key1": true, "key2": true}`.

With normalised rows, every permission check or role edit does a multi-row query. With JSON, the whole permission set for a role is one read, one write.

## Decision
Store a role's permissions as a **JSON blob** on the `roles` table:

```sql
CREATE TABLE roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    permissions JSON NOT NULL,          -- {"members.read": true, ...}
    ...
);
```

Effective permissions for a user = union of blobs across their active role assignments.

## Consequences
- Role edit is one UPDATE, not N deletes + N inserts.
- Permission check is already cached in session (`$_SESSION['_permissions']`); the underlying query is simple.
- We lose relational integrity — adding a new permission key doesn't automatically propagate to roles. That's fine: new permissions default to "not granted" and are added to roles deliberately via the admin UI.
- Querying "which roles have permission X" is a JSON scan (slow) but rare — that's an admin-UI listing, not a hot path.
- Permission keys have no dedicated table. They are declared in `module.php` `permissions` arrays, collected by `ModuleRegistry`, and shown in the role editor. This keeps declarations close to the code that enforces them.

## Alternatives considered
- **Normalised `role_permissions` table** — more "proper" but doesn't win on any dimension that matters here.
- **Permissions in code only** (role name → hardcoded grants) — inflexible; admins cannot edit.

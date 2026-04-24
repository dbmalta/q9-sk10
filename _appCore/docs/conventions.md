# appCore — Conventions

Rules for how code is written, organised, and named in an appCore project. These are not suggestions — consistency is what lets the codebase stay readable at scale without tooling.

---

## 1. Coding standard

- **PSR-12.** All PHP follows PSR-12 style.
- **PHP 8.2+ features expected.** Use `readonly` properties, `enum`, constructor promotion, named arguments, `match`, first-class callable syntax. Do not write code that targets earlier PHP.
- **`declare(strict_types=1);`** at the top of every `.php` file in `/app/src/` and `/app/modules/`.
- **Return types and parameter types on every method.** No `mixed` unless genuinely unavoidable.
- **No suppression operator `@`.** Handle errors explicitly. The one current exception is reading `VERSION` in `module.php`; don't spread the pattern.

---

## 2. Directory & file naming

| Thing | Convention | Example |
|---|---|---|
| Core class | PascalCase, one per file | `app/src/Core/Database.php` |
| Module directory | PascalCase | `app/modules/Members/` |
| Controller | `{Entity}Controller` | `Controllers/MemberController.php` |
| Service | `{Entity}Service` | `Services/MemberService.php` |
| Twig template | `snake_case.html.twig` | `templates/member_list.html.twig` |
| Component template | `_` prefix | `_pagination.html.twig` |
| Layout template | in `layouts/` | `layouts/admin.html.twig` |
| Migration | `NNNN_snake_case.sql` | `0007_custom_fields.sql` |
| i18n key | `namespace.sub.key` | `members.list.empty` |
| Permission key | `module.action` | `members.write`, `admin.settings` |
| Route name | `module.action` | `members.index`, `auth.login.process` |
| JavaScript file | `kebab-case.js` | `assets/js/org-tree.js` |

---

## 3. Layered responsibilities

**Controllers:**
- Extract + type-cast request parameters.
- Call `requireAuth()` / `requirePermission()` first thing when needed.
- Validate CSRF on state-changing requests (automatic, but call `validateCsrf()` in the rare non-form case).
- Delegate all domain work to Services.
- Return a `Response` (html / json / redirect / file). Never `echo`.
- **No SQL. No business logic. No direct Encryption calls.** All of that is service-layer work.

**Services:**
- Hold all business logic and all database access.
- Receive dependencies via constructor — typically `Database`, `Session`, `Encryption`, other services.
- Return structured results: `['success' => bool, 'errors' => [...], 'data' => ...]` for validated mutations; plain arrays or objects for reads.
- Call `AuditService::log()` on every mutation.
- Call `Encryption::encrypt/decrypt` at the boundary — encrypted columns never leave the service layer unencrypted.

**Templates:**
- Presentation only.
- No queries (no `{{ db.query(...) }}`).
- Access data via variables passed from the controller.
- Use `has_permission()` for conditional UI; use `t()` for all user-facing strings.

---

## 4. Database access

- **Always prepared statements.** `$db->query('SELECT ... WHERE id = ?', [$id])`. Never string-concatenate user input.
- **No `SELECT *`** in new code — list columns explicitly so schema changes don't silently widen result sets.
- **Transactions** for any multi-table mutation: `$db->beginTransaction(); try {...} catch (...) { $db->rollback(); throw; }`.
- **Migrations are forward-only.** No down migrations. If a migration was wrong, write a new one that fixes it.
- **One concern per migration file.** Splitting risky DDL into separate files limits blast radius of partial failures.

---

## 5. Validation

- Validate in the **service layer**, not the controller.
- Return `['success' => false, 'errors' => [...]]` on failure; the controller flashes the errors and re-renders.
- Validation rules live with the service method they apply to — do not build a global validation framework unless there's a concrete reason.
- Boundary validation only. Internal calls between services trust their inputs.

---

## 6. Responses

- `return $this->render('template.html.twig', $data)` — HTML page.
- `return $this->json(['ok' => true])` — JSON (for HTMX, APIs).
- `return $this->redirect(route('members.index'))` — always via named route, never a raw string.
- `return Response::file($path, $filename)` — downloads.
- Flash messages for cross-request feedback: `$this->flash('success', t('members.created'))`.
- All HTML responses emit `Cache-Control: no-store` by default — prevents stale-form-POST hazards.

---

## 7. CSRF

- All state-changing requests (POST/PUT/DELETE) are validated **automatically** by `Application::run()` before dispatch.
- Forms must include `{{ csrf_field()|raw }}` — emits a hidden input with the token.
- HTMX requests must include the token in headers; use the `hx-headers` attribute or the global HTMX config that reads `meta[name=csrf-token]`.
- JSON APIs intended for non-browser clients should opt out of CSRF by routing under a path excluded in `Application::run()` and using a bearer token or API key instead.

---

## 8. i18n

- Every user-facing string uses a key: `{{ t('members.list.empty') }}` / `$t('auth.login_failed')`.
- Keys live in `/lang/en.json` as nested JSON. The key `members.list.empty` resolves from `{"members": {"list": {"empty": "..."}}}`.
- DB overrides in `language_overrides` take precedence over the JSON base — lets non-devs edit copy via the admin UI.
- Missing keys return the key itself — this is deliberate, so missing translations are visible rather than silent.
- Placeholders: `"Welcome, {name}"` interpolated via `t('welcome', ['name' => $user['name']])`.

---

## 9. Permissions

- Declare every permission a module uses in its `module.php` `permissions` array — even if it will be granted implicitly to admins. The declaration is what makes it selectable in the role editor.
- Check in controllers: `$check = $this->requirePermission('key'); if ($check) return $check;`
- Check in templates: `{% if has_permission('key') %}...{% endif %}`
- **Never** gate purely on user role name (`if ($user['role'] === 'admin')`). Always check a permission key. Role names are display labels.
- **Never** imply permission from hierarchy position. A role assigned at a parent node does not automatically grant rights at a child node unless scope resolution explicitly says so.

---

## 10. Audit logging

- Every create/update/delete on a domain entity calls `AuditService::log($entityType, $entityId, $action, $oldValues, $newValues, $userId)` from the service layer.
- Audit log is append-only. Don't edit or delete rows. Don't redact.
- For encrypted columns, store a **marker** (e.g. `'[encrypted]'`) in the audit payload, not the plaintext — audit log must not widen the exposure of protected fields.

---

## 11. Encryption

- Use `Encryption::encrypt($plaintext)` / `decrypt($ciphertext)` only — never roll your own.
- Encrypted columns are named with an `encrypted_` prefix (e.g. `encrypted_medical`, `encrypted_notes`).
- Encrypt at the service boundary on write; decrypt at the service boundary on read. Controllers and templates never see ciphertext.
- Encryption key is at `/config/encryption.key`, permissions `0600`. Never commit it. Never log it. There is no key rotation mechanism — design your data retention with that in mind.

---

## 12. Session

- Default backing store: files in `/var/sessions/`.
- Flash messages: `$session->flash('success', 'Saved.')` — consumed by the **next** request's template render.
- Permission cache in session must be invalidated when a user's role assignments change (the Permissions module does this on save).
- Session timeout enforced by `Session::start()`; configurable via `security.session_timeout`.

---

## 13. Templates

- One template per view. Don't try to parameterise a single template to serve multiple pages.
- Partial templates (for HTMX fragments) are named with an underscore prefix like components: `_member_row.html.twig`.
- Block structure: `{% block content %}` for main body, `{% block scripts %}` for page-specific JS, `{% block styles %}` for page-specific CSS.
- Avoid deeply nested Twig logic. If a template has more than one level of conditional/loop nesting, the logic probably belongs in the controller or a Twig helper.

---

## 14. HTMX

- Controllers detect HTMX via `$request->isHtmx()` and **may** render a fragment template instead of a full page.
- `hx-target` and `hx-swap` on the trigger; controllers do not need to care — they just render.
- Confirm destructive actions with the shared `_confirm_modal` component.
- Include the CSRF token in HTMX requests via `meta[name=csrf-token]` + global HTMX config.

---

## 15. Alpine.js

- Use for small, local interactive state: dropdowns, tabs, toggles, drag-and-drop.
- **Do not** build SPA-style state machines in Alpine. If a component is growing past ~30 lines of `x-data`, the logic probably belongs server-side with an HTMX partial.

---

## 16. Routing

- Register in `module.php` inside the `routes` callable — never via a global config or side-effect.
- Route names mirror permission keys where sensible (`members.index`, `members.store`).
- URL patterns: lowercase, hyphenated, plural for collections (`/members`), `/{id:\d+}` for numeric IDs.
- Admin routes under `/admin/...`; member-facing under `/` or `/my/...`.
- Generate URLs via `route('name', ['id' => 5])` — never hard-code paths in templates or controllers.

---

## 17. Error handling

- Services catch expected failures (validation, not-found, conflict) and return structured errors.
- Services let unexpected failures propagate as exceptions — `ErrorHandler` catches, logs, and renders the 500 page.
- Controllers do not try to catch domain exceptions; they let them bubble.
- Never show a raw exception message to the user in production. `config.app.debug = true` turns on developer-facing detail.

---

## 18. Logging

- Use `Logger::error/warning/info/debug` — not `error_log()`.
- `info` and `debug` are no-ops unless `config.app.debug = true`.
- Include structured context: `Logger::error('Send failed', ['user_id' => $id, 'reason' => $e->getMessage()])`.
- Do not log request bodies blindly — they may contain credentials or PII. Log the shape (field names, sizes) if useful, not the content.

---

## 19. Testing

- **PHPUnit** for unit and service-layer tests. One test class per production class.
- Tests that need a database hit the `{project}_test` database. Bootstrap skips if unavailable (`markTestSkipped`).
- **Playwright** for end-to-end flows — login, one mutation per critical module.
- Don't mock the database in service tests. Integration > isolation for this layer.
- Seed the test DB with `php tests/seed.php` (scaffolded per project).

---

## 20. Git

- Conventional commit messages: `feat:`, `fix:`, `refactor:`, `chore:`, `docs:`, `test:`.
- One concern per commit. Reviewers should be able to understand each commit without reading the next.
- Do not commit `/config/config.php`, `/config/encryption.key`, `/vendor/`, `/var/`, `/data/`.
- Feature branches off `main`; PR; squash or rebase merge.

---

## 21. Dependencies

- **Add with intent.** Every Composer dependency is a supply-chain surface. Prefer standard library and hand-rolled code over a library that saves 30 lines.
- Frontend vendor libraries live in `/assets/vendor/` as raw files — no npm, no build. If a library cannot be dropped in raw, think twice before adding it.
- Lock file (`composer.lock`) is committed. Updates are deliberate PRs, not routine.

---

## 22. What to write down, what to leave out

- **Comments**: Default to none. Good names and small functions make comments redundant. Write a comment only when the **why** is non-obvious — a hidden constraint, a workaround for a specific bug, a subtle invariant.
- **Docstrings**: Only where the parameter or return shape is non-obvious from types. PHPDoc `@param` / `@return` are redundant when types are declared.
- **README**: One per module, only if the module has non-obvious setup or a public API other modules call. Most modules don't need one.

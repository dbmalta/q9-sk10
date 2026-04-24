# appCore — Playbook

> The practical guide to starting and building a project on appCore. Follow these steps in order when spinning up a new project.

---

## 1. Start a new project from the scaffold

```bash
# 1. Clone or copy the _appCore directory
cp -r _appCore ~/projects/my-new-project
cd ~/projects/my-new-project
rm -rf .git
git init

# 2. Find-and-replace the placeholder tokens
#    {{PROJECT_NAME}}  → "Acme Membership Portal"
#    {{PROJECT_SLUG}}  → "acme-portal"
#    {{VENDOR}}        → "Acme"
#    {{VENDOR_SLUG}}   → "acme"
#
#    Check these files:
#      CLAUDE.md
#      config/config.example.php
#      composer.json (optional — "name" and "description")
#      README.md (if you add one)
#
#    On Linux/macOS:
grep -rl '{{PROJECT_NAME}}' . | xargs sed -i '' 's/{{PROJECT_NAME}}/Acme Portal/g'
# ... repeat for each token

# 3. Install Composer dependencies
composer install

# 4. Run the setup wizard
#    (OR manually copy config/config.example.php to config/config.php and run php tools/migrate.php)
php -S localhost:8080
# Browse to http://localhost:8080 — wizard launches.

# 5. First commit
git add .
git commit -m "chore: initialise {{PROJECT_NAME}} from appCore"
```

After the wizard completes, you have a running admin UI at `/login` with the super-admin account you created.

---

## 2. Add a new module

Say you're adding a "Members" feature.

```bash
mkdir -p app/modules/Members/{Controllers,Services,templates}
```

### 2.1 Write `app/modules/Members/module.php`

```php
<?php

declare(strict_types=1);

use AppCore\Modules\Members\Controllers\MemberController;

return [
    'id'      => 'members',
    'name'    => 'Members',
    'version' => '1.0.0',
    'system'  => false,

    'nav' => [
        [
            'label'         => 'nav.members',
            'icon'          => 'bi-people',
            'route'         => '/admin/members',
            'group'         => 'admin',
            'order'         => 20,
            'requires_auth' => true,
            'modes'         => ['admin'],
        ],
    ],

    'routes' => function (\AppCore\Core\Router $router): void {
        $router->get('/admin/members',             [MemberController::class, 'index'],  'members.index');
        $router->get('/admin/members/new',         [MemberController::class, 'create'], 'members.create');
        $router->post('/admin/members',            [MemberController::class, 'store'],  'members.store');
        $router->get('/admin/members/{id:\d+}',    [MemberController::class, 'show'],   'members.show');
        $router->post('/admin/members/{id:\d+}',   [MemberController::class, 'update'], 'members.update');
        $router->post('/admin/members/{id:\d+}/delete', [MemberController::class, 'destroy'], 'members.destroy');
    },

    'permissions' => [
        'members.read'  => 'View members',
        'members.write' => 'Create, edit, and delete members',
    ],
];
```

### 2.2 Write the service

`app/modules/Members/Services/MemberService.php`:

```php
<?php

declare(strict_types=1);

namespace AppCore\Modules\Members\Services;

use AppCore\Core\Database;
use AppCore\Modules\Admin\Services\AuditService;

final class MemberService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, email, created_at FROM members ORDER BY name LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function getById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM members WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, errors?: array<string, string>, id?: int}
     */
    public function create(array $data, int $userId): array
    {
        $errors = $this->validate($data);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $id = $this->db->insert('members', [
            'name'       => $data['name'],
            'email'      => $data['email'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log('member', $id, 'create', null, $data, $userId);

        return ['success' => true, 'id' => $id];
    }

    /** @return array<string, string> */
    private function validate(array $data): array
    {
        $errors = [];
        if (empty($data['name']))  { $errors['name']  = 'Name is required.'; }
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required.';
        }
        return $errors;
    }
}
```

### 2.3 Write the controller

`app/modules/Members/Controllers/MemberController.php`:

```php
<?php

declare(strict_types=1);

namespace AppCore\Modules\Members\Controllers;

use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Members\Services\MemberService;

final class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        if ($check = $this->requirePermission('members.read')) { return $check; }

        $service = new MemberService($this->app->getDatabase(), $this->audit());
        $members = $service->list();

        return $this->render('@members/index.html.twig', ['members' => $members]);
    }

    public function store(Request $request): Response
    {
        if ($check = $this->requirePermission('members.write')) { return $check; }

        $service = new MemberService($this->app->getDatabase(), $this->audit());
        $result  = $service->create($request->getAllParams(), $this->currentUserId());

        if (!$result['success']) {
            $this->flash('error', $this->t('members.create_failed'));
            return $this->render('@members/create.html.twig', ['errors' => $result['errors']]);
        }

        $this->flash('success', $this->t('members.created'));
        return $this->redirect($this->route('members.show', ['id' => $result['id']]));
    }
}
```

### 2.4 Write a migration

`app/migrations/0011_members.sql`:

```sql
CREATE TABLE members (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    name       VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_members_email (email),
    INDEX idx_members_name  (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Run it:
```bash
php tools/migrate.php
```

### 2.5 Write the templates

`app/modules/Members/templates/index.html.twig`:

```twig
{% extends '@templates/layouts/admin.html.twig' %}

{% block title %}{{ t('members.list.title') }}{% endblock %}

{% block content %}
    <h1>{{ t('members.list.title') }}</h1>

    {% if has_permission('members.write') %}
        <a href="{{ route('members.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> {{ t('members.new') }}
        </a>
    {% endif %}

    {% if members is empty %}
        {% include '@templates/components/_empty_state.html.twig' with {
            message: t('members.list.empty')
        } %}
    {% else %}
        <table class="table">
            <thead><tr><th>{{ t('common.name') }}</th><th>{{ t('common.email') }}</th></tr></thead>
            <tbody>
                {% for member in members %}
                    <tr>
                        <td><a href="{{ route('members.show', {id: member.id}) }}">{{ member.name }}</a></td>
                        <td>{{ member.email }}</td>
                    </tr>
                {% endfor %}
            </tbody>
        </table>
    {% endif %}
{% endblock %}
```

### 2.6 Add language keys

Edit `/lang/en.json`:

```json
{
  "nav":     { "members": "Members" },
  "members": {
    "list":       { "title": "Members", "empty": "No members yet." },
    "new":        "New member",
    "created":    "Member created.",
    "create_failed": "Could not create member. Please fix the errors and try again."
  }
}
```

### 2.7 Grant the permission

Log in as super-admin → Permissions → edit a role → tick `members.read` / `members.write` → save.

Done. Your module is live.

---

## 3. Add a permission to an existing module

1. Add the key to the module's `module.php` `permissions` array.
2. Reference it in controllers via `$this->requirePermission('key')` or templates via `{% if has_permission('key') %}`.
3. Assign it to roles via the Permissions admin UI.

No migration needed — permissions are declared in `module.php`, not stored in a permissions table. Roles store a JSON blob of granted keys.

---

## 4. Add a new migration

1. Pick the next sequential number (e.g. `0011` if `0010` is the last).
2. Create `app/migrations/0011_short_description.sql`.
3. Write one schema concern per file. Prefer `CREATE TABLE IF NOT EXISTS` where appropriate; splitting risky multi-statement changes into separate files reduces blast radius on partial failures.
4. Run `php tools/migrate.php` (or let it run on next request if auto-migration is enabled).
5. Commit the migration file.

**Never edit an already-applied migration.** Write a new one that corrects the prior state.

---

## 5. Add a cron job

1. Write a handler class in your module implementing `AppCore\Core\CronHandlerInterface`:
   ```php
   final class NightlyCleanupJob implements CronHandlerInterface
   {
       public function __construct(private readonly Database $db) {}
       public function handle(): void { /* ... */ }
       public function schedule(): string { return '@daily'; } // cron-ish string
   }
   ```
2. Register it in your module's `module.php`:
   ```php
   'cron' => [
       \AppCore\Modules\Members\Jobs\NightlyCleanupJob::class,
   ],
   ```
3. Ensure `/cron/run.php` is called by the system cron (every minute is typical):
   ```
   * * * * * curl -sS "https://example.com/cron/run.php?secret=YOUR_CRON_SECRET" >/dev/null
   ```

---

## 6. Add a new language

1. Via the Admin UI: Languages → Add language → upload `/lang/fr.json` (or edit strings in-UI).
2. Or manually: drop `lang/fr.json` and add a row to `languages`.
3. Users pick their language in the account page; default is set via Admin → Languages.

String overrides (admin-edited) live in `language_overrides` and layer over the JSON base.

---

## 7. Deploy

`{{PROJECT_NAME}}` is designed for traditional hosting:

1. Run `composer install --no-dev --optimize-autoloader` locally.
2. Zip the project (exclude `/var/`, `/data/`, `/config/config.php`, `/config/encryption.key`, `/tests/`, `.git/`).
3. Upload to server (FTP / SFTP / rsync).
4. Create `/config/` and copy `config.example.php` → `config.php`, fill in values. (Or upload no config and let the wizard write it.)
5. Ensure `/var/`, `/data/`, `/config/` are writable by the web server user.
6. Point Apache/Nginx at `/index.php` as the front controller. On Apache, `.htaccess` handles it.
7. Set up cron: `* * * * * curl -sS https://your-domain/cron/run.php?secret=...`.
8. Back up `/config/encryption.key` separately — losing it means losing access to all encrypted columns.

---

## 8. Debugging

| Symptom | Where to look |
|---|---|
| 500 error | `/var/logs/errors.json` |
| Slow page | `/var/logs/requests.json` (includes N+1 detection) |
| Slow query | `/var/logs/slow-queries.json` |
| Failed email | `/var/logs/smtp.json` |
| Cron didn't run | `/var/logs/cron.json` |
| Update failed | `/var/logs/updates.json` |
| User changes | `audit_log` table (viewable via Admin → Audit) |
| Session/login issues | Check `/var/sessions/` writable; check `security.session_timeout` |
| Twig template cached | Delete `/var/cache/twig/` — or set `app.debug = true` for auto-reload |

Set `config.app.debug = true` in `config/config.php` to see full stack traces and disable Twig cache.

---

## 9. What to do when...

| Scenario | Do this |
|---|---|
| Need to add a tree/hierarchy | Read `docs/patterns/hierarchy-closure-table.md` |
| Need file uploads | Read `docs/patterns/attachments.md` |
| Need a per-entity activity feed | Read `docs/patterns/timeline.md` |
| Need flexible/custom fields on an entity | Read `docs/patterns/custom-fields.md` |
| Need to send email | Read `docs/patterns/email-queue.md` |
| Need events/calendar | Read `docs/patterns/calendar-ical.md` |
| Need CSV import | Read `docs/patterns/bulk-import.md` |
| Need reports | Read `docs/patterns/reports.md` |
| About to deviate from an ADR | Write a new ADR first. Don't litigate in code review. |

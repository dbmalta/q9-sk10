<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * Self-contained setup wizard for ScoutKeeper.
 *
 * Handles the multi-step first-run installation: prerequisite checks,
 * database credentials, install-type selection (blank/clean/demo),
 * organisation creation, admin account, SMTP, encryption key, and
 * config file writing.
 *
 * This class is intentionally standalone -- it does NOT depend on the
 * Application singleton, Twig, or the module registry because those are
 * not yet available during first-run setup.
 */
class SetupWizard
{
    private string $rootPath;
    private const TOTAL_STEPS = 8;

    /** Minimum PHP version required. */
    private const MIN_PHP_VERSION = '8.2.0';

    /** PHP extensions that must be loaded. */
    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'json',
        'mbstring',
        'openssl',
        'fileinfo',
        'zip',
    ];

    /** Directories that must be writable (relative to ROOT_PATH). */
    private const WRITABLE_DIRS = [
        'config',
        'data',
        'data/uploads',
        'data/logs',
        'data/backups',
        'var',
        'var/cache',
        'var/logs',
        'var/sessions',
        'var/updates',
    ];

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    // ── Public API ───────────────────────────────────────────────────

    public function isSetupNeeded(): bool
    {
        return !file_exists($this->rootPath . '/config/config.php');
    }

    public function getCurrentStep(): int
    {
        $step = (int) ($_SESSION['setup_step'] ?? 1);
        return max(1, min($step, self::TOTAL_STEPS));
    }

    /**
     * Resolve the step the user should actually see for a requested step.
     *
     * If an install type that skips certain steps has been chosen, this
     * advances past the skipped step. Currently: demo installs skip
     * the organisation step (step 4) because the demo seeder creates
     * its own organisation tree.
     */
    public function resolveVisibleStep(int $requested): int
    {
        $installType = $_SESSION['setup_data']['install_type'] ?? '';
        if ($requested === 4 && $installType === 'demo') {
            return 5;
        }
        return $requested;
    }

    /**
     * @return array{success: bool, errors: string[], next_step: int}
     */
    public function processStep(int $step, array $data): array
    {
        return match ($step) {
            1 => $this->checkPrerequisites(),
            2 => $this->setupDatabase($data),
            3 => $this->processInstallType($data),
            4 => $this->setupOrganisation($data),
            5 => $this->createAdmin($data),
            6 => $this->setupSmtp($data),
            7 => $this->generateEncryptionKey(),
            8 => $this->finishSetup(),
            default => ['success' => false, 'errors' => ['Invalid step.'], 'next_step' => 1],
        };
    }

    public function renderStep(int $step, array $data = []): string
    {
        $templateDir = __DIR__ . '/templates';
        $templateFile = $templateDir . '/step' . $step . '.php';

        if (!file_exists($templateFile)) {
            return '<h1>Setup error</h1><p>Template not found for step ' . $step . '.</p>';
        }

        $totalSteps = self::TOTAL_STEPS;
        $currentStep = $step;
        $wizard = $this;
        $sessionData = $_SESSION['setup_data'] ?? [];

        extract($data, EXTR_SKIP);

        ob_start();
        require $templateDir . '/layout.php';
        return ob_get_clean();
    }

    /**
     * Run all SQL migration files against the given database connection.
     *
     * @return string[] List of filenames that were applied
     */
    public function runMigrations(\PDO $pdo): array
    {
        $migrationsDir = $this->rootPath . '/app/migrations';
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `_migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `filename` VARCHAR(255) NOT NULL UNIQUE,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $files = glob($migrationsDir . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        $stmt = $pdo->query("SELECT `filename` FROM `_migrations`");
        $applied = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $newlyApplied = [];

        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                continue;
            }

            $pdo->exec($sql);

            $ins = $pdo->prepare("INSERT INTO `_migrations` (`filename`) VALUES (:f)");
            $ins->execute(['f' => $basename]);

            $newlyApplied[] = $basename;
        }

        return $newlyApplied;
    }

    /**
     * Return a snapshot of the configured database: whether we can connect,
     * whether it currently has any tables, and how many.
     *
     * Used by step 3 so the user can see if they are about to install into
     * an already-populated schema.
     *
     * @return array{connected: bool, is_empty: bool, table_count: int, error: ?string}
     */
    public function getDatabaseStatus(): array
    {
        $db = $_SESSION['setup_data']['db'] ?? null;
        if ($db === null) {
            return ['connected' => false, 'is_empty' => true, 'table_count' => 0, 'error' => 'Database not configured.'];
        }

        try {
            $pdo = $this->createPdo($db);
            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $count = count($tables);
            return ['connected' => true, 'is_empty' => $count === 0, 'table_count' => $count, 'error' => null];
        } catch (\Throwable $e) {
            return ['connected' => false, 'is_empty' => true, 'table_count' => 0, 'error' => $e->getMessage()];
        }
    }

    // ── Step Handlers ────────────────────────────────────────────────

    /**
     * Step 1 -- Pre-flight checks.
     */
    private function checkPrerequisites(): array
    {
        $checks = $this->getPrerequisiteChecks();
        $errors = [];

        foreach ($checks as $check) {
            if (!$check['passed']) {
                $errors[] = $check['label'] . ': ' . ($check['detail'] ?? 'Failed');
            }
        }

        $success = empty($errors);

        if ($success) {
            $_SESSION['setup_step'] = 2;
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'next_step' => $success ? 2 : 1,
            'checks' => $checks,
        ];
    }

    /**
     * Step 2 -- Database credentials + connection test only.
     *
     * Migrations and schema operations are deferred to step 3, so that the
     * user first gets to choose whether to wipe the DB or keep it.
     */
    private function setupDatabase(array $data): array
    {
        $errors = [];

        $host = trim($data['db_host'] ?? 'localhost');
        $port = trim($data['db_port'] ?? '3306');
        $name = trim($data['db_name'] ?? '');
        $user = trim($data['db_user'] ?? '');
        $pass = $data['db_password'] ?? '';

        if ($name === '') {
            $errors[] = 'Database name is required.';
        }
        if ($user === '') {
            $errors[] = 'Database user is required.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'next_step' => 2];
        }

        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'errors' => ['Database connection failed: ' . $e->getMessage()],
                'next_step' => 2,
            ];
        }

        $mysqlVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        if (version_compare($mysqlVersion, '8.0.0', '<')) {
            return [
                'success' => false,
                'errors' => ["MySQL 8.0+ required. Found version $mysqlVersion."],
                'next_step' => 2,
            ];
        }

        $_SESSION['setup_data']['db'] = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $pass,
        ];
        // A re-submission of step 2 invalidates any previous install-type
        // choice, since the target DB may have changed.
        unset($_SESSION['setup_data']['install_type'], $_SESSION['setup_data']['migrations_applied']);
        $_SESSION['setup_step'] = 3;

        return ['success' => true, 'errors' => [], 'next_step' => 3];
    }

    /**
     * Step 3 -- Install type: keep / clean / demo.
     *
     * Runs schema wipe (for clean and demo) and migrations. Demo seeding
     * is deferred until step 8 so the admin account created in step 5 can
     * be preserved.
     */
    private function processInstallType(array $data): array
    {
        $installType = $data['install_type'] ?? '';
        if (!in_array($installType, ['keep', 'clean', 'demo'], true)) {
            return ['success' => false, 'errors' => ['Please choose an install type.'], 'next_step' => 3];
        }

        $dbConfig = $_SESSION['setup_data']['db'] ?? null;
        if ($dbConfig === null) {
            return ['success' => false, 'errors' => ['Database not configured. Go back to step 2.'], 'next_step' => 2];
        }

        try {
            $pdo = $this->createPdo($dbConfig);

            if ($installType === 'clean' || $installType === 'demo') {
                $this->wipeSchema($pdo);
            }

            $applied = $this->runMigrations($pdo);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Schema setup failed: ' . $e->getMessage()],
                'next_step' => 3,
            ];
        }

        $_SESSION['setup_data']['install_type'] = $installType;
        $_SESSION['setup_data']['migrations_applied'] = $applied;

        // Demo installs skip the organisation step — the seeder will create
        // the org tree at step 8.
        $next = ($installType === 'demo') ? 5 : 4;
        $_SESSION['setup_step'] = $next;

        return ['success' => true, 'errors' => [], 'next_step' => $next];
    }

    /**
     * Drop every table in the configured database. Temporarily disables
     * foreign-key checks so drop order doesn't matter.
     */
    private function wipeSchema(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($tables as $table) {
                $safe = str_replace('`', '', (string) $table);
                $pdo->exec('DROP TABLE IF EXISTS `' . $safe . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Step 4 -- Organisation setup.
     */
    private function setupOrganisation(array $data): array
    {
        // Demo installs bypass this step entirely — if we somehow land
        // here, just advance.
        if (($_SESSION['setup_data']['install_type'] ?? '') === 'demo') {
            $_SESSION['setup_step'] = 5;
            return ['success' => true, 'errors' => [], 'next_step' => 5];
        }

        $errors = [];
        $orgName = trim($data['org_name'] ?? '');
        $rootNodeName = trim($data['root_node_name'] ?? '');
        $levelTypeName = trim($data['level_type_name'] ?? '');

        if ($orgName === '') {
            $errors[] = 'Organisation name is required.';
        }
        if ($rootNodeName === '') {
            $errors[] = 'Root node name is required.';
        }
        if ($levelTypeName === '') {
            $errors[] = 'First level type name is required.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'next_step' => 4];
        }

        $dbConfig = $_SESSION['setup_data']['db'] ?? null;
        if ($dbConfig === null) {
            return ['success' => false, 'errors' => ['Database not configured. Go back to step 2.'], 'next_step' => 2];
        }

        try {
            $pdo = $this->createPdo($dbConfig);

            $stmt = $pdo->prepare(
                "INSERT INTO `org_level_types` (`name`, `depth`, `is_leaf`, `sort_order`) VALUES (:name, 0, 0, 0)"
            );
            $stmt->execute(['name' => $levelTypeName]);
            $levelTypeId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO `org_nodes` (`parent_id`, `level_type_id`, `name`, `sort_order`, `is_active`) VALUES (NULL, :lt, :name, 0, 1)"
            );
            $stmt->execute(['lt' => $levelTypeId, 'name' => $rootNodeName]);
            $rootNodeId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT INTO `org_closure` (`ancestor_id`, `descendant_id`, `depth`) VALUES (:id, :id2, 0)"
            );
            $stmt->execute(['id' => $rootNodeId, 'id2' => $rootNodeId]);

            $stmt = $pdo->prepare(
                "UPDATE `settings` SET `value` = :val WHERE `key` = 'org_name'"
            );
            $stmt->execute(['val' => $orgName]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Organisation setup failed: ' . $e->getMessage()],
                'next_step' => 4,
            ];
        }

        $_SESSION['setup_data']['org'] = [
            'name' => $orgName,
            'root_node_name' => $rootNodeName,
            'root_node_id' => $rootNodeId,
            'level_type_name' => $levelTypeName,
        ];
        $_SESSION['setup_step'] = 5;

        return ['success' => true, 'errors' => [], 'next_step' => 5];
    }

    /**
     * Step 5 -- Create the super-admin user and linked member record.
     */
    private function createAdmin(array $data): array
    {
        $errors = [];
        $email = strtolower(trim($data['admin_email'] ?? ''));
        $password = $data['admin_password'] ?? '';
        $passwordConfirm = $data['admin_password_confirm'] ?? '';
        $firstName = trim($data['admin_first_name'] ?? '');
        $surname = trim($data['admin_surname'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }
        if ($firstName === '') {
            $errors[] = 'First name is required.';
        }
        if ($surname === '') {
            $errors[] = 'Surname is required.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'next_step' => 5];
        }

        $dbConfig = $_SESSION['setup_data']['db'] ?? null;
        if ($dbConfig === null) {
            return ['success' => false, 'errors' => ['Database not configured.'], 'next_step' => 2];
        }

        try {
            $pdo = $this->createPdo($dbConfig);

            $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

            $stmt = $pdo->prepare(
                "INSERT INTO `users` (`email`, `password_hash`, `is_active`, `is_super_admin`, `password_changed_at`)
                 VALUES (:email, :hash, 1, 1, NOW())"
            );
            $stmt->execute(['email' => $email, 'hash' => $passwordHash]);
            $userId = (int) $pdo->lastInsertId();

            $memberNumber = 'ADM-' . str_pad((string) $userId, 5, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare(
                "INSERT INTO `members` (`user_id`, `membership_number`, `first_name`, `surname`, `email`, `status`, `joined_date`)
                 VALUES (:uid, :num, :fn, :sn, :email, 'active', CURDATE())"
            );
            $stmt->execute([
                'uid' => $userId,
                'num' => $memberNumber,
                'fn' => $firstName,
                'sn' => $surname,
                'email' => $email,
            ]);
            $memberId = (int) $pdo->lastInsertId();

            // Demo installs will have their org tree rebuilt by the seeder at
            // step 8; skip the member_nodes insert in that case (no root node
            // exists yet).
            $rootNodeId = $_SESSION['setup_data']['org']['root_node_id'] ?? null;
            if ($rootNodeId !== null) {
                $stmt = $pdo->prepare(
                    "INSERT INTO `member_nodes` (`member_id`, `node_id`, `is_primary`) VALUES (:mid, :nid, 1)"
                );
                $stmt->execute(['mid' => $memberId, 'nid' => $rootNodeId]);
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Admin creation failed: ' . $e->getMessage()],
                'next_step' => 5,
            ];
        }

        $_SESSION['setup_data']['admin'] = [
            'email' => $email,
            'first_name' => $firstName,
            'surname' => $surname,
            'user_id' => $userId,
        ];
        $_SESSION['setup_step'] = 6;

        return ['success' => true, 'errors' => [], 'next_step' => 6];
    }

    /**
     * Step 6 -- SMTP configuration (optional).
     */
    private function setupSmtp(array $data): array
    {
        $skip = ($data['skip_smtp'] ?? '') === '1';

        if ($skip) {
            $_SESSION['setup_data']['smtp'] = [
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_email' => '',
                'from_name' => '',
            ];
            $_SESSION['setup_step'] = 7;
            return ['success' => true, 'errors' => [], 'next_step' => 7];
        }

        $errors = [];
        $host = trim($data['smtp_host'] ?? '');
        $port = (int) ($data['smtp_port'] ?? 587);
        $username = trim($data['smtp_username'] ?? '');
        $password = $data['smtp_password'] ?? '';
        $encryption = $data['smtp_encryption'] ?? 'tls';
        $fromEmail = trim($data['smtp_from_email'] ?? '');
        $fromName = trim($data['smtp_from_name'] ?? '');

        if ($host === '') {
            $errors[] = 'SMTP host is required (or click Skip).';
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid "From" email address is required.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'next_step' => 6];
        }

        $_SESSION['setup_data']['smtp'] = [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
        $_SESSION['setup_step'] = 7;

        return ['success' => true, 'errors' => [], 'next_step' => 7];
    }

    /**
     * Step 7 -- Generate encryption key.
     */
    private function generateEncryptionKey(): array
    {
        $keyFile = $this->rootPath . '/config/encryption.key';

        if (file_exists($keyFile) && filesize($keyFile) >= 32) {
            $_SESSION['setup_step'] = 8;
            return ['success' => true, 'errors' => [], 'next_step' => 8, 'key_existed' => true];
        }

        try {
            $key = bin2hex(random_bytes(32));
            $written = file_put_contents($keyFile, $key);

            if ($written === false) {
                return [
                    'success' => false,
                    'errors' => ['Failed to write encryption key file. Check that config/ is writable.'],
                    'next_step' => 7,
                ];
            }

            @chmod($keyFile, 0600);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Encryption key generation failed: ' . $e->getMessage()],
                'next_step' => 7,
            ];
        }

        $_SESSION['setup_step'] = 8;

        return ['success' => true, 'errors' => [], 'next_step' => 8, 'key_existed' => false];
    }

    /**
     * Step 8 -- Write config/config.php and optionally seed demo data.
     */
    private function finishSetup(): array
    {
        $sd = $_SESSION['setup_data'] ?? [];
        $db = $sd['db'] ?? null;
        $smtp = $sd['smtp'] ?? [];
        $orgName = $sd['org']['name'] ?? 'ScoutKeeper';
        $seedDemo = ($sd['install_type'] ?? '') === 'demo';

        if ($db === null) {
            return ['success' => false, 'errors' => ['Missing database configuration.'], 'next_step' => 2];
        }

        if ($seedDemo) {
            $seedResult = $this->seedDemoData($db, $sd['admin'] ?? []);
            if (!$seedResult['success']) {
                return $seedResult;
            }
            $orgName = \App\Setup\Seeders\FilflaDemoSeeder::ORG_NAME;
        }

        $cronSecret = bin2hex(random_bytes(16));
        $apiKey = bin2hex(random_bytes(20));

        try {
            $pdo = $this->createPdo($db);
            $stmt = $pdo->prepare(
                "INSERT INTO `settings` (`key`, `value`, `group`)
                 VALUES ('api_key', :val, 'monitoring')
                 ON DUPLICATE KEY UPDATE `value` = :val2"
            );
            $stmt->execute(['val' => $apiKey, 'val2' => $apiKey]);

            $appVersion = trim(@file_get_contents($this->rootPath . '/VERSION') ?: '0.0.0');
            $stmt = $pdo->prepare(
                "INSERT INTO `settings` (`key`, `value`, `group`)
                 VALUES ('app_version', :ver, 'general')
                 ON DUPLICATE KEY UPDATE `value` = :ver2"
            );
            $stmt->execute(['ver' => $appVersion, 'ver2' => $appVersion]);

            $installMode = $seedDemo ? 'demo' : 'production';
            $stmt = $pdo->prepare(
                "INSERT INTO `settings` (`key`, `value`, `group`)
                 VALUES ('install_mode', :mode, 'general')
                 ON DUPLICATE KEY UPDATE `value` = :mode2"
            );
            $stmt->execute(['mode' => $installMode, 'mode2' => $installMode]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Failed to save settings: ' . $e->getMessage()],
                'next_step' => 8,
            ];
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host;

        $configContent = $this->buildConfigFile($db, $smtp, $orgName, $cronSecret, $apiKey, $baseUrl);
        $configPath = $this->rootPath . '/config/config.php';

        $written = file_put_contents($configPath, $configContent);
        if ($written === false) {
            return [
                'success' => false,
                'errors' => ['Failed to write config/config.php. Check permissions.'],
                'next_step' => 8,
            ];
        }

        unset($_SESSION['setup_step'], $_SESSION['setup_data']);

        return ['success' => true, 'errors' => [], 'next_step' => 8];
    }

    /**
     * Run the Filfla demo seeder while preserving the installing admin's login.
     *
     * @return array{success: bool, errors: string[], next_step: int}
     */
    private function seedDemoData(array $dbConfig, array $adminInfo): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $pdo = $this->createPdo($dbConfig);
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE email = :e LIMIT 1');
            $stmt->execute(['e' => $adminInfo['email'] ?? '']);
            $adminPasswordHash = $stmt->fetchColumn();
            if (!$adminPasswordHash) {
                return ['success' => false, 'errors' => ['Could not find the admin account to preserve during demo seeding.'], 'next_step' => 8];
            }

            require_once $this->rootPath . '/app/src/Core/Database.php';
            require_once $this->rootPath . '/app/src/Core/Encryption.php';
            require_once $this->rootPath . '/app/src/Setup/Seeders/FilflaDemoSeeder.php';

            $database = new \App\Core\Database($dbConfig);
            $seeder = new \App\Setup\Seeders\FilflaDemoSeeder($database);
            $seeder->setAdminOverride(
                $adminInfo['email'] ?? 'admin@filfla.test',
                (string)$adminPasswordHash,
                $adminInfo['first_name'] ?? 'Admin',
                $adminInfo['surname'] ?? 'User',
            );
            $seeder->setProgressCallback(static function (string $msg) {
                // no-op
            });
            $seeder->run();

            return ['success' => true, 'errors' => [], 'next_step' => 8];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Demo seeding failed: ' . $e->getMessage()],
                'next_step' => 8,
            ];
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @return array<array{label: string, passed: bool, detail: string}>
     */
    public function getPrerequisiteChecks(): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'PHP version',
            'passed' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
            'detail' => 'Found ' . PHP_VERSION . ' (requires ' . self::MIN_PHP_VERSION . '+)',
        ];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = [
                'label' => "Extension: $ext",
                'passed' => extension_loaded($ext),
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
            ];
        }

        foreach (self::WRITABLE_DIRS as $dir) {
            $fullPath = $this->rootPath . '/' . $dir;
            $isWritable = is_dir($fullPath) && is_writable($fullPath);
            $checks[] = [
                'label' => "Writable: $dir/",
                'passed' => $isWritable,
                'detail' => $isWritable ? 'Writable' : (is_dir($fullPath) ? 'Not writable' : 'Directory missing'),
            ];
        }

        return $checks;
    }

    private function createPdo(array $dbConfig): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'],
            $dbConfig['port'] ?? '3306',
            $dbConfig['name']
        );

        return new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function buildConfigFile(
        array $db,
        array $smtp,
        string $orgName,
        string $cronSecret,
        string $apiKey,
        string $baseUrl,
    ): string {
        $dbHost = addslashes($db['host']);
        $dbPort = addslashes($db['port']);
        $dbName = addslashes($db['name']);
        $dbUser = addslashes($db['user']);
        $dbPass = addslashes($db['password']);

        $smtpHost = addslashes($smtp['host'] ?? '');
        $smtpPort = (int) ($smtp['port'] ?? 587);
        $smtpUser = addslashes($smtp['username'] ?? '');
        $smtpPass = addslashes($smtp['password'] ?? '');
        $smtpEnc = addslashes($smtp['encryption'] ?? 'tls');
        $smtpFrom = addslashes($smtp['from_email'] ?? '');
        $smtpName = addslashes($smtp['from_name'] ?? $orgName);

        $orgNameEsc = addslashes($orgName);
        $cronSecretEsc = addslashes($cronSecret);
        $apiKeyEsc = addslashes($apiKey);
        $baseUrlEsc = addslashes($baseUrl);

        return <<<PHP
<?php

/**
 * ScoutKeeper -- Configuration
 *
 * Generated by the setup wizard on {$this->now()}.
 * This file is never committed to version control or included in updates.
 */

return [
    'db' => [
        'host' => '{$dbHost}',
        'port' => '{$dbPort}',
        'name' => '{$dbName}',
        'user' => '{$dbUser}',
        'password' => '{$dbPass}',
    ],

    'app' => [
        'name' => '{$orgNameEsc}',
        'url' => '{$baseUrlEsc}',
        'timezone' => 'UTC',
        'debug' => false,
        'language' => 'en',
    ],

    'smtp' => [
        'host' => '{$smtpHost}',
        'port' => {$smtpPort},
        'username' => '{$smtpUser}',
        'password' => '{$smtpPass}',
        'encryption' => '{$smtpEnc}',
        'from_email' => '{$smtpFrom}',
        'from_name' => '{$smtpName}',
    ],

    'security' => [
        'encryption_key_file' => __DIR__ . '/encryption.key',
        'session_timeout' => 7200,
    ],

    'monitoring' => [
        'api_key' => '{$apiKeyEsc}',
        'slow_query_threshold_ms' => 1000,
    ],

    'cron' => [
        'secret' => '{$cronSecretEsc}',
        'email_batch_size' => 20,
        'email_interval_seconds' => 60,
    ],
];

PHP;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s') . ' UTC';
    }
}

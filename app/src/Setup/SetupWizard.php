<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * Self-contained setup wizard for ScoutKeeper.
 *
 * Handles the multi-step first-run installation: prerequisite checks,
 * database setup, organisation creation, admin account, SMTP, encryption
 * key generation, and config file writing.
 *
 * This class is intentionally standalone -- it does NOT depend on the
 * Application singleton, Twig, or the module registry because those are
 * not yet available during first-run setup.
 */
class SetupWizard
{
    private string $rootPath;
    private const TOTAL_STEPS = 7;

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

    /**
     * Is setup needed?  True when config/config.php does not exist.
     */
    public function isSetupNeeded(): bool
    {
        return !file_exists($this->rootPath . '/config/config.php');
    }

    /**
     * Read the current step from the session (defaults to 1).
     */
    public function getCurrentStep(): int
    {
        $step = (int) ($_SESSION['setup_step'] ?? 1);
        return max(1, min($step, self::TOTAL_STEPS));
    }

    /**
     * Process a setup step.
     *
     * @return array{success: bool, errors: string[], next_step: int}
     */
    public function processStep(int $step, array $data): array
    {
        return match ($step) {
            1 => $this->checkPrerequisites(),
            2 => $this->setupDatabase($data),
            3 => $this->setupOrganisation($data),
            4 => $this->createAdmin($data),
            5 => $this->setupSmtp($data),
            6 => $this->generateEncryptionKey(),
            7 => $this->finishSetup(),
            default => ['success' => false, 'errors' => ['Invalid step.'], 'next_step' => 1],
        };
    }

    /**
     * Render a setup step as HTML.
     */
    public function renderStep(int $step, array $data = []): string
    {
        $templateDir = __DIR__ . '/templates';
        $templateFile = $templateDir . '/step' . $step . '.php';

        if (!file_exists($templateFile)) {
            return '<h1>Setup error</h1><p>Template not found for step ' . $step . '.</p>';
        }

        // Variables available in every template
        $totalSteps = self::TOTAL_STEPS;
        $currentStep = $step;
        $wizard = $this;
        $sessionData = $_SESSION['setup_data'] ?? [];

        // Merge any passed-in data
        extract($data, EXTR_SKIP);

        ob_start();
        require $templateDir . '/layout.php';
        return ob_get_clean();
    }

    /**
     * Run all SQL migration files against the given database connection.
     *
     * Creates the _migrations tracking table if it does not exist, then
     * applies each migration file that has not been recorded yet.
     *
     * @return string[] List of filenames that were applied
     */
    public function runMigrations(\PDO $pdo): array
    {
        $migrationsDir = $this->rootPath . '/app/migrations';
        if (!is_dir($migrationsDir)) {
            return [];
        }

        // Ensure the migrations tracking table exists (from 0001)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `_migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `filename` VARCHAR(255) NOT NULL UNIQUE,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Find and sort migration files
        $files = glob($migrationsDir . '/*.sql');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        // Which have already been applied?
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

            // Execute the migration (may contain multiple statements)
            $pdo->exec($sql);

            // Record it
            $ins = $pdo->prepare("INSERT INTO `_migrations` (`filename`) VALUES (:f)");
            $ins->execute(['f' => $basename]);

            $newlyApplied[] = $basename;
        }

        return $newlyApplied;
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
     * Step 2 -- Database connection and migrations.
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

        // Try connecting
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

        // Check MySQL version >= 8.0
        $mysqlVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        if (version_compare($mysqlVersion, '8.0.0', '<')) {
            return [
                'success' => false,
                'errors' => ["MySQL 8.0+ required. Found version $mysqlVersion."],
                'next_step' => 2,
            ];
        }

        // Run migrations
        try {
            $applied = $this->runMigrations($pdo);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Migration failed: ' . $e->getMessage()],
                'next_step' => 2,
            ];
        }

        // Store DB config in session for later steps
        $_SESSION['setup_data']['db'] = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'password' => $pass,
        ];
        $_SESSION['setup_data']['migrations_applied'] = $applied;
        $_SESSION['setup_step'] = 3;

        return ['success' => true, 'errors' => [], 'next_step' => 3, 'migrations' => $applied];
    }

    /**
     * Step 3 -- Organisation setup.
     */
    private function setupOrganisation(array $data): array
    {
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
            return ['success' => false, 'errors' => $errors, 'next_step' => 3];
        }

        $dbConfig = $_SESSION['setup_data']['db'] ?? null;
        if ($dbConfig === null) {
            return ['success' => false, 'errors' => ['Database not configured. Go back to step 2.'], 'next_step' => 2];
        }

        try {
            $pdo = $this->createPdo($dbConfig);

            // Create the level type
            $stmt = $pdo->prepare(
                "INSERT INTO `org_level_types` (`name`, `depth`, `is_leaf`, `sort_order`) VALUES (:name, 0, 0, 0)"
            );
            $stmt->execute(['name' => $levelTypeName]);
            $levelTypeId = (int) $pdo->lastInsertId();

            // Create the root org node
            $stmt = $pdo->prepare(
                "INSERT INTO `org_nodes` (`parent_id`, `level_type_id`, `name`, `sort_order`, `is_active`) VALUES (NULL, :lt, :name, 0, 1)"
            );
            $stmt->execute(['lt' => $levelTypeId, 'name' => $rootNodeName]);
            $rootNodeId = (int) $pdo->lastInsertId();

            // Insert closure row for root (self-reference at depth 0)
            $stmt = $pdo->prepare(
                "INSERT INTO `org_closure` (`ancestor_id`, `descendant_id`, `depth`) VALUES (:id, :id2, 0)"
            );
            $stmt->execute(['id' => $rootNodeId, 'id2' => $rootNodeId]);

            // Update org_name in settings table
            $stmt = $pdo->prepare(
                "UPDATE `settings` SET `value` = :val WHERE `key` = 'org_name'"
            );
            $stmt->execute(['val' => $orgName]);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Organisation setup failed: ' . $e->getMessage()],
                'next_step' => 3,
            ];
        }

        $_SESSION['setup_data']['org'] = [
            'name' => $orgName,
            'root_node_name' => $rootNodeName,
            'root_node_id' => $rootNodeId,
            'level_type_name' => $levelTypeName,
        ];
        $_SESSION['setup_step'] = 4;

        return ['success' => true, 'errors' => [], 'next_step' => 4];
    }

    /**
     * Step 4 -- Create the super-admin user and linked member record.
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
            return ['success' => false, 'errors' => $errors, 'next_step' => 4];
        }

        $dbConfig = $_SESSION['setup_data']['db'] ?? null;
        if ($dbConfig === null) {
            return ['success' => false, 'errors' => ['Database not configured.'], 'next_step' => 2];
        }

        try {
            $pdo = $this->createPdo($dbConfig);

            $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

            // Create the user
            $stmt = $pdo->prepare(
                "INSERT INTO `users` (`email`, `password_hash`, `is_active`, `is_super_admin`, `password_changed_at`)
                 VALUES (:email, :hash, 1, 1, NOW())"
            );
            $stmt->execute(['email' => $email, 'hash' => $passwordHash]);
            $userId = (int) $pdo->lastInsertId();

            // Create a linked member record
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

            // Assign member to root node
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
                'next_step' => 4,
            ];
        }

        $_SESSION['setup_data']['admin'] = [
            'email' => $email,
            'first_name' => $firstName,
            'surname' => $surname,
            'user_id' => $userId,
        ];
        $_SESSION['setup_step'] = 5;

        return ['success' => true, 'errors' => [], 'next_step' => 5];
    }

    /**
     * Step 5 -- SMTP configuration (optional).
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
            $_SESSION['setup_step'] = 6;
            return ['success' => true, 'errors' => [], 'next_step' => 6];
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
            return ['success' => false, 'errors' => $errors, 'next_step' => 5];
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
        $_SESSION['setup_step'] = 6;

        return ['success' => true, 'errors' => [], 'next_step' => 6];
    }

    /**
     * Step 6 -- Generate encryption key.
     */
    private function generateEncryptionKey(): array
    {
        $keyFile = $this->rootPath . '/config/encryption.key';

        // Don't overwrite an existing key
        if (file_exists($keyFile) && filesize($keyFile) >= 32) {
            $_SESSION['setup_step'] = 7;
            return ['success' => true, 'errors' => [], 'next_step' => 7, 'key_existed' => true];
        }

        try {
            $key = bin2hex(random_bytes(32)); // 64 hex chars = 256 bits
            $written = file_put_contents($keyFile, $key);

            if ($written === false) {
                return [
                    'success' => false,
                    'errors' => ['Failed to write encryption key file. Check that config/ is writable.'],
                    'next_step' => 6,
                ];
            }

            // Restrict file permissions where possible
            @chmod($keyFile, 0600);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Encryption key generation failed: ' . $e->getMessage()],
                'next_step' => 6,
            ];
        }

        $_SESSION['setup_step'] = 7;

        return ['success' => true, 'errors' => [], 'next_step' => 7, 'key_existed' => false];
    }

    /**
     * Step 7 -- Write config/config.php and complete setup.
     */
    private function finishSetup(): array
    {
        $sd = $_SESSION['setup_data'] ?? [];
        $db = $sd['db'] ?? null;
        $smtp = $sd['smtp'] ?? [];
        $orgName = $sd['org']['name'] ?? 'ScoutKeeper';

        if ($db === null) {
            return ['success' => false, 'errors' => ['Missing database configuration.'], 'next_step' => 2];
        }

        // Generate a random cron secret and API key
        $cronSecret = bin2hex(random_bytes(16));
        $apiKey = bin2hex(random_bytes(20));

        // Store API key in settings table
        try {
            $pdo = $this->createPdo($db);
            $stmt = $pdo->prepare(
                "INSERT INTO `settings` (`key`, `value`, `group`)
                 VALUES ('api_key', :val, 'monitoring')
                 ON DUPLICATE KEY UPDATE `value` = :val2"
            );
            $stmt->execute(['val' => $apiKey, 'val2' => $apiKey]);

            // Store app version
            $stmt = $pdo->prepare(
                "INSERT INTO `settings` (`key`, `value`, `group`)
                 VALUES ('app_version', '1.0.0', 'general')
                 ON DUPLICATE KEY UPDATE `value` = '1.0.0'"
            );
            $stmt->execute();
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors' => ['Failed to save settings: ' . $e->getMessage()],
                'next_step' => 7,
            ];
        }

        // Detect the base URL
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
                'next_step' => 7,
            ];
        }

        // Clean up setup session data
        unset($_SESSION['setup_step'], $_SESSION['setup_data']);

        return ['success' => true, 'errors' => [], 'next_step' => 7];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Return the full prerequisite check results for step 1 / template.
     *
     * @return array<array{label: string, passed: bool, detail: string}>
     */
    public function getPrerequisiteChecks(): array
    {
        $checks = [];

        // PHP version
        $checks[] = [
            'label' => 'PHP version',
            'passed' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
            'detail' => 'Found ' . PHP_VERSION . ' (requires ' . self::MIN_PHP_VERSION . '+)',
        ];

        // Required extensions
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = [
                'label' => "Extension: $ext",
                'passed' => extension_loaded($ext),
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
            ];
        }

        // Writable directories
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

    /**
     * Create a PDO instance from a database config array.
     */
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

    /**
     * Build the config.php file content as a PHP string.
     */
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

    /**
     * Current UTC timestamp string.
     */
    private function now(): string
    {
        return gmdate('Y-m-d H:i:s') . ' UTC';
    }
}

<?php

declare(strict_types=1);

namespace AppCore\Setup;

/**
 * Self-contained setup wizard.
 *
 * Does NOT depend on the Application singleton, Twig, or the module
 * registry — those are unavailable during first-run setup. All templates
 * are plain PHP.
 *
 * Steps: prerequisites → database → install type → project details →
 * admin account → SMTP → encryption key → finish (writes config.php).
 */
class SetupWizard
{
    private string $rootPath;
    private const TOTAL_STEPS = 8;

    private const MIN_PHP_VERSION = '8.2.0';

    private const REQUIRED_EXTENSIONS = [
        'pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'fileinfo', 'zip',
    ];

    private const WRITABLE_DIRS = [
        'config',
        'data',
        'var',
        'var/cache',
        'var/logs',
        'var/sessions',
    ];

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

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
     * @return array{success: bool, errors: string[], next_step: int}
     */
    public function processStep(int $step, array $data): array
    {
        return match ($step) {
            1 => $this->checkPrerequisites(),
            2 => $this->setupDatabase($data),
            3 => $this->processInstallType($data),
            4 => $this->setupProject($data),
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
        require $templateDir . '/_layout.php';
        return (string) ob_get_clean();
    }

    /**
     * Run all SQL migrations against the given connection.
     * @return string[] Filenames newly applied.
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

        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = $pdo->query("SELECT `filename` FROM `_migrations`")->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $newlyApplied = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, $applied, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            if (trim($sql) === '') {
                continue;
            }
            $pdo->exec($sql);

            $ins = $pdo->prepare("INSERT INTO `_migrations` (`filename`) VALUES (:f)");
            $ins->execute(['f' => $basename]);
            $newlyApplied[] = $basename;
        }
        return $newlyApplied;
    }

    // ── Step handlers ────────────────────────────────────────────────

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
            'success'   => $success,
            'errors'    => $errors,
            'next_step' => $success ? 2 : 1,
            'checks'    => $checks,
        ];
    }

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
            $pdo = $this->createPdo([
                'host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $pass,
            ]);
        } catch (\PDOException $e) {
            return [
                'success' => false,
                'errors'  => ['Database connection failed: ' . $e->getMessage()],
                'next_step' => 2,
            ];
        }

        $mysqlVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        if (version_compare((string) $mysqlVersion, '8.0.0', '<')) {
            return [
                'success' => false,
                'errors'  => ["MySQL 8.0+ required. Found version $mysqlVersion."],
                'next_step' => 2,
            ];
        }

        $_SESSION['setup_data']['db'] = [
            'host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'password' => $pass,
        ];
        unset($_SESSION['setup_data']['install_type']);
        $_SESSION['setup_step'] = 3;

        return ['success' => true, 'errors' => [], 'next_step' => 3];
    }

    private function processInstallType(array $data): array
    {
        $installType = $data['install_type'] ?? '';
        if (!in_array($installType, ['keep', 'clean'], true)) {
            return ['success' => false, 'errors' => ['Please choose an install type.'], 'next_step' => 3];
        }

        $dbConfig = $_SESSION['setup_data']['db'] ?? null;
        if ($dbConfig === null) {
            return ['success' => false, 'errors' => ['Database not configured.'], 'next_step' => 2];
        }

        try {
            $pdo = $this->createPdo($dbConfig);
            if ($installType === 'clean') {
                $this->wipeSchema($pdo);
            }
            $this->runMigrations($pdo);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors'  => ['Schema setup failed: ' . $e->getMessage()],
                'next_step' => 3,
            ];
        }

        $_SESSION['setup_data']['install_type'] = $installType;
        $_SESSION['setup_step'] = 4;

        return ['success' => true, 'errors' => [], 'next_step' => 4];
    }

    private function wipeSchema(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($tables as $table) {
                $safe = str_replace('`', '', (string) $table);
                $pdo->exec('DROP TABLE IF EXISTS `' . $safe . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function setupProject(array $data): array
    {
        $errors = [];
        $projectName = trim($data['project_name'] ?? '');
        $language = trim($data['language'] ?? 'en');

        if ($projectName === '') {
            $errors[] = 'Project name is required.';
        }
        if ($language === '') {
            $language = 'en';
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
            $this->upsertSetting($pdo, 'project_name', $projectName, 'general');
            $this->upsertSetting($pdo, 'default_language', $language, 'general');
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors'  => ['Project setup failed: ' . $e->getMessage()],
                'next_step' => 4,
            ];
        }

        $_SESSION['setup_data']['project'] = [
            'name'     => $projectName,
            'language' => $language,
        ];
        $_SESSION['setup_step'] = 5;

        return ['success' => true, 'errors' => [], 'next_step' => 5];
    }

    private function createAdmin(array $data): array
    {
        $errors = [];
        $email = strtolower(trim($data['admin_email'] ?? ''));
        $password = $data['admin_password'] ?? '';
        $passwordConfirm = $data['admin_password_confirm'] ?? '';
        $name = trim($data['admin_name'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        }
        if ($name === '') {
            $errors[] = 'Administrator name is required.';
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
            $stmt = $pdo->prepare(
                "INSERT INTO `users` (`email`, `password_hash`, `is_active`, `is_super_admin`, `password_changed_at`)
                 VALUES (:email, :hash, 1, 1, NOW())"
            );
            $stmt->execute([
                'email' => $email,
                'hash'  => password_hash($password, PASSWORD_BCRYPT),
            ]);
            $userId = (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors'  => ['Admin creation failed: ' . $e->getMessage()],
                'next_step' => 5,
            ];
        }

        $_SESSION['setup_data']['admin'] = [
            'email'   => $email,
            'name'    => $name,
            'user_id' => $userId,
        ];
        $_SESSION['setup_step'] = 6;

        return ['success' => true, 'errors' => [], 'next_step' => 6];
    }

    private function setupSmtp(array $data): array
    {
        $skip = ($data['skip_smtp'] ?? '') === '1';

        if ($skip) {
            $_SESSION['setup_data']['smtp'] = [
                'host' => '', 'port' => 587, 'username' => '', 'password' => '',
                'encryption' => 'tls', 'from_email' => '', 'from_name' => '',
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
            'host' => $host, 'port' => $port, 'username' => $username, 'password' => $password,
            'encryption' => $encryption, 'from_email' => $fromEmail, 'from_name' => $fromName,
        ];
        $_SESSION['setup_step'] = 7;

        return ['success' => true, 'errors' => [], 'next_step' => 7];
    }

    private function generateEncryptionKey(): array
    {
        $keyFile = $this->rootPath . '/config/encryption.key';

        if (file_exists($keyFile) && filesize($keyFile) >= 32) {
            $_SESSION['setup_step'] = 8;
            return ['success' => true, 'errors' => [], 'next_step' => 8];
        }

        try {
            $key = bin2hex(random_bytes(32));
            if (file_put_contents($keyFile, $key) === false) {
                return [
                    'success' => false,
                    'errors'  => ['Failed to write encryption key file. Check config/ is writable.'],
                    'next_step' => 7,
                ];
            }
            @chmod($keyFile, 0600);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors'  => ['Encryption key generation failed: ' . $e->getMessage()],
                'next_step' => 7,
            ];
        }

        $_SESSION['setup_step'] = 8;
        return ['success' => true, 'errors' => [], 'next_step' => 8];
    }

    private function finishSetup(): array
    {
        $sd = $_SESSION['setup_data'] ?? [];
        $db = $sd['db'] ?? null;
        $smtp = $sd['smtp'] ?? [];
        $projectName = $sd['project']['name'] ?? 'appCore Project';
        $language = $sd['project']['language'] ?? 'en';

        if ($db === null) {
            return ['success' => false, 'errors' => ['Missing database configuration.'], 'next_step' => 2];
        }

        $cronSecret = bin2hex(random_bytes(16));
        $apiKey     = bin2hex(random_bytes(20));

        try {
            $pdo = $this->createPdo($db);
            $this->upsertSetting($pdo, 'api_key', $apiKey, 'monitoring');
            $appVersion = trim((string) @file_get_contents($this->rootPath . '/VERSION') ?: '0.0.0');
            $this->upsertSetting($pdo, 'app_version', $appVersion, 'general');
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'errors'  => ['Failed to save settings: ' . $e->getMessage()],
                'next_step' => 8,
            ];
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host;

        $configContent = $this->buildConfigFile($db, $smtp, $projectName, $language, $cronSecret, $apiKey, $baseUrl);
        $configPath = $this->rootPath . '/config/config.php';

        if (file_put_contents($configPath, $configContent) === false) {
            return [
                'success' => false,
                'errors'  => ['Failed to write config/config.php. Check permissions.'],
                'next_step' => 8,
            ];
        }

        unset($_SESSION['setup_step'], $_SESSION['setup_data']);
        return ['success' => true, 'errors' => [], 'next_step' => 8];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @return array<array{label: string, passed: bool, detail: string}>
     */
    public function getPrerequisiteChecks(): array
    {
        $checks = [];

        $checks[] = [
            'label'  => 'PHP version',
            'passed' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
            'detail' => 'Found ' . PHP_VERSION . ' (requires ' . self::MIN_PHP_VERSION . '+)',
        ];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = [
                'label'  => "Extension: $ext",
                'passed' => extension_loaded($ext),
                'detail' => extension_loaded($ext) ? 'Loaded' : 'Not loaded',
            ];
        }

        foreach (self::WRITABLE_DIRS as $dir) {
            $fullPath = $this->rootPath . '/' . $dir;
            if (!is_dir($fullPath)) {
                @mkdir($fullPath, 0755, true);
            }
            $isWritable = is_dir($fullPath) && is_writable($fullPath);
            $checks[] = [
                'label'  => "Writable: $dir/",
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
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    private function upsertSetting(\PDO $pdo, string $key, string $value, string $group): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO `settings` (`key`, `value`, `group`)
             VALUES (:k, :v, :g)
             ON DUPLICATE KEY UPDATE `value` = :v2"
        );
        $stmt->execute(['k' => $key, 'v' => $value, 'g' => $group, 'v2' => $value]);
    }

    private function buildConfigFile(
        array $db,
        array $smtp,
        string $projectName,
        string $language,
        string $cronSecret,
        string $apiKey,
        string $baseUrl,
    ): string {
        $esc = static fn(string $s): string => addslashes($s);

        $dbHost = $esc((string) $db['host']);
        $dbPort = $esc((string) ($db['port'] ?? '3306'));
        $dbName = $esc((string) $db['name']);
        $dbUser = $esc((string) $db['user']);
        $dbPass = $esc((string) ($db['password'] ?? ''));

        $smtpHost = $esc((string) ($smtp['host'] ?? ''));
        $smtpPort = (int) ($smtp['port'] ?? 587);
        $smtpUser = $esc((string) ($smtp['username'] ?? ''));
        $smtpPass = $esc((string) ($smtp['password'] ?? ''));
        $smtpEnc  = $esc((string) ($smtp['encryption'] ?? 'tls'));
        $smtpFrom = $esc((string) ($smtp['from_email'] ?? ''));
        $smtpName = $esc((string) ($smtp['from_name'] ?? $projectName));

        $nameEsc = $esc($projectName);
        $langEsc = $esc($language);
        $cron    = $esc($cronSecret);
        $api     = $esc($apiKey);
        $url     = $esc($baseUrl);
        $now     = gmdate('Y-m-d H:i:s') . ' UTC';

        return <<<PHP
<?php

/**
 * appCore — Configuration
 *
 * Generated by the setup wizard on {$now}.
 * This file is not committed to version control and is not replaced by updates.
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
        'name' => '{$nameEsc}',
        'url' => '{$url}',
        'timezone' => 'UTC',
        'debug' => false,
        'language' => '{$langEsc}',
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
        'api_key' => '{$api}',
        'slow_query_threshold_ms' => 1000,
        'slow_request_threshold_ms' => 500,
        'slow_request_query_count' => 20,
    ],

    'cron' => [
        'secret' => '{$cron}',
        'email_interval_seconds' => 60,
    ],
];

PHP;
    }
}

<?php

declare(strict_types=1);

namespace Tests\SetupWizard;

use App\Setup\SetupWizard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Setup Wizard (Phase 6.1).
 */
class WizardTest extends TestCase
{
    private string $tempDir;
    private SetupWizard $wizard;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sk10_setup_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/config', 0755, true);
        mkdir($this->tempDir . '/data', 0755, true);
        mkdir($this->tempDir . '/data/uploads', 0755, true);
        mkdir($this->tempDir . '/data/logs', 0755, true);
        mkdir($this->tempDir . '/data/backups', 0755, true);
        mkdir($this->tempDir . '/var', 0755, true);
        mkdir($this->tempDir . '/var/cache', 0755, true);
        mkdir($this->tempDir . '/var/logs', 0755, true);
        mkdir($this->tempDir . '/var/sessions', 0755, true);
        mkdir($this->tempDir . '/var/updates', 0755, true);

        $srcTemplates = ROOT_PATH . '/app/src/Setup/templates';
        $dstTemplates = $this->tempDir . '/app/src/Setup/templates';
        if (is_dir($srcTemplates)) {
            mkdir($dstTemplates, 0755, true);
            foreach (glob($srcTemplates . '/*.php') as $file) {
                copy($file, $dstTemplates . '/' . basename($file));
            }
        }

        $this->wizard = new SetupWizard($this->tempDir);

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        unset($_SESSION['setup_step'], $_SESSION['setup_data']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['setup_step'], $_SESSION['setup_data']);
        $this->removeDir($this->tempDir);
    }

    public function testIsSetupNeededWhenConfigMissing(): void
    {
        $this->assertTrue($this->wizard->isSetupNeeded());
    }

    public function testIsSetupNeededReturnsFalseWhenConfigExists(): void
    {
        file_put_contents($this->tempDir . '/config/config.php', '<?php return [];');
        $this->assertFalse($this->wizard->isSetupNeeded());
    }

    public function testGetCurrentStepDefaultsToOne(): void
    {
        $this->assertSame(1, $this->wizard->getCurrentStep());
    }

    public function testGetCurrentStepReadsFromSession(): void
    {
        $_SESSION['setup_step'] = 3;
        $this->assertSame(3, $this->wizard->getCurrentStep());
    }

    public function testGetCurrentStepClampsToBounds(): void
    {
        $_SESSION['setup_step'] = 0;
        $this->assertSame(1, $this->wizard->getCurrentStep());

        $_SESSION['setup_step'] = 99;
        $this->assertSame(7, $this->wizard->getCurrentStep());
    }

    public function testCheckPrerequisitesReturnsChecksArray(): void
    {
        $checks = $this->wizard->getPrerequisiteChecks();
        $this->assertIsArray($checks);
        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('passed', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertIsBool($check['passed']);
        }
    }

    public function testCheckPrerequisitesVerifiesPhpVersion(): void
    {
        $checks = $this->wizard->getPrerequisiteChecks();
        $phpCheck = null;
        foreach ($checks as $check) {
            if ($check['label'] === 'PHP version') {
                $phpCheck = $check;
                break;
            }
        }
        $this->assertNotNull($phpCheck);
        $this->assertTrue($phpCheck['passed']);
        $this->assertStringContainsString(PHP_VERSION, $phpCheck['detail']);
    }

    public function testCheckPrerequisitesVerifiesExtensions(): void
    {
        $checks = $this->wizard->getPrerequisiteChecks();
        $pdoCheck = null;
        foreach ($checks as $check) {
            if ($check['label'] === 'Extension: pdo') {
                $pdoCheck = $check;
                break;
            }
        }
        $this->assertNotNull($pdoCheck);
        $this->assertTrue($pdoCheck['passed']);
    }

    public function testCheckPrerequisitesVerifiesWritableDirs(): void
    {
        $checks = $this->wizard->getPrerequisiteChecks();
        $dirChecks = array_filter($checks, fn($c) => str_starts_with($c['label'], 'Writable:'));
        $this->assertNotEmpty($dirChecks);
        foreach ($dirChecks as $check) {
            $this->assertTrue($check['passed'], "Directory should be writable: {$check['label']}");
        }
    }

    public function testProcessStep1AdvancesToStep2OnSuccess(): void
    {
        // Check if all prerequisites pass (zip extension may not be loaded in dev)
        $checks = $this->wizard->getPrerequisiteChecks();
        $allPass = true;
        foreach ($checks as $check) {
            if (!$check['passed']) {
                $allPass = false;
                break;
            }
        }

        $result = $this->wizard->processStep(1, []);

        if ($allPass) {
            $this->assertTrue($result['success']);
            $this->assertSame(2, $result['next_step']);
            $this->assertEmpty($result['errors']);
            $this->assertSame(2, $_SESSION['setup_step']);
        } else {
            // Some prerequisites fail (e.g. zip extension not loaded) — step stays at 1
            $this->assertFalse($result['success']);
            $this->assertSame(1, $result['next_step']);
            $this->assertNotEmpty($result['errors']);
        }
    }

    public function testProcessStep2FailsWithMissingFields(): void
    {
        $result = $this->wizard->processStep(2, []);
        $this->assertFalse($result['success']);
        $this->assertSame(2, $result['next_step']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testProcessStep2FailsWithBadCredentials(): void
    {
        $result = $this->wizard->processStep(2, [
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => 'nonexistent_db_' . uniqid(),
            'db_user' => 'nonexistent_user_' . uniqid(),
            'db_password' => 'bad_password',
        ]);
        $this->assertFalse($result['success']);
        $this->assertSame(2, $result['next_step']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testProcessStep3FailsWithMissingFields(): void
    {
        $result = $this->wizard->processStep(3, []);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testProcessStep3FailsWithoutDbConfig(): void
    {
        $result = $this->wizard->processStep(3, [
            'org_name' => 'Test Scouts',
            'root_node_name' => 'National',
            'level_type_name' => 'Country',
        ]);
        $this->assertFalse($result['success']);
        $this->assertSame(2, $result['next_step']);
    }

    public function testProcessStep4FailsWithMissingFields(): void
    {
        $result = $this->wizard->processStep(4, []);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testProcessStep4FailsWithShortPassword(): void
    {
        $result = $this->wizard->processStep(4, [
            'admin_email' => 'admin@test.com',
            'admin_password' => 'short',
            'admin_password_confirm' => 'short',
            'admin_first_name' => 'Test',
            'admin_surname' => 'Admin',
        ]);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => str_contains($e, 'at least 10')));
    }

    public function testProcessStep4FailsWithPasswordMismatch(): void
    {
        $result = $this->wizard->processStep(4, [
            'admin_email' => 'admin@test.com',
            'admin_password' => 'LongEnoughPassword1',
            'admin_password_confirm' => 'DifferentPassword1',
            'admin_first_name' => 'Test',
            'admin_surname' => 'Admin',
        ]);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => str_contains($e, 'do not match')));
    }

    public function testProcessStep4FailsWithInvalidEmail(): void
    {
        $result = $this->wizard->processStep(4, [
            'admin_email' => 'not-an-email',
            'admin_password' => 'LongEnoughPassword1',
            'admin_password_confirm' => 'LongEnoughPassword1',
            'admin_first_name' => 'Test',
            'admin_surname' => 'Admin',
        ]);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty(array_filter($result['errors'], fn($e) => str_contains($e, 'email')));
    }

    public function testProcessStep5CanBeSkipped(): void
    {
        $result = $this->wizard->processStep(5, ['skip_smtp' => '1']);
        $this->assertTrue($result['success']);
        $this->assertSame(6, $result['next_step']);
        $this->assertArrayHasKey('smtp', $_SESSION['setup_data']);
    }

    public function testProcessStep5FailsWithMissingHost(): void
    {
        $result = $this->wizard->processStep(5, [
            'smtp_host' => '',
            'smtp_from_email' => 'noreply@test.com',
        ]);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testProcessStep5SucceedsWithValidData(): void
    {
        $result = $this->wizard->processStep(5, [
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_username' => 'user',
            'smtp_password' => 'pass',
            'smtp_encryption' => 'tls',
            'smtp_from_email' => 'noreply@example.com',
            'smtp_from_name' => 'ScoutKeeper',
        ]);
        $this->assertTrue($result['success']);
        $this->assertSame(6, $result['next_step']);
        $this->assertSame('smtp.example.com', $_SESSION['setup_data']['smtp']['host']);
    }

    public function testProcessStep6GeneratesEncryptionKey(): void
    {
        $result = $this->wizard->processStep(6, []);
        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['next_step']);
        $this->assertFalse($result['key_existed']);

        $keyFile = $this->tempDir . '/config/encryption.key';
        $this->assertFileExists($keyFile);
        $this->assertSame(64, strlen(file_get_contents($keyFile)));
    }

    public function testProcessStep6PreservesExistingKey(): void
    {
        $keyFile = $this->tempDir . '/config/encryption.key';
        $existingKey = str_repeat('ab', 32);
        file_put_contents($keyFile, $existingKey);

        $result = $this->wizard->processStep(6, []);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['key_existed']);
        $this->assertSame($existingKey, file_get_contents($keyFile));
    }

    public function testProcessStepInvalidStepReturnsFalse(): void
    {
        $result = $this->wizard->processStep(99, []);
        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['next_step']);
    }

    public function testRenderStepReturnsHtml(): void
    {
        $html = $this->wizard->renderStep(1);
        $this->assertIsString($html);
        $this->assertNotEmpty($html);
    }

    public function testRenderStepHandlesMissingTemplateForUnknownStep(): void
    {
        // The template dir is __DIR__/templates (class-relative), so we can't
        // easily make it missing. Instead, test with an out-of-range step number
        // which has no template file.
        $html = $this->wizard->renderStep(99);
        $this->assertStringContainsString('Template not found', $html);
    }

    public function testRunMigrationsAppliesFiles(): void
    {
        $dbConfig = TEST_CONFIG['db'];
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbConfig['host'], $dbConfig['port'], $dbConfig['name']);
            $pdo = new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException) {
            $this->markTestSkipped('Test database not available');
        }

        $pdo->exec("DROP TABLE IF EXISTS `_setup_test_table`");
        $pdo->exec("DROP TABLE IF EXISTS `_migrations`");

        $migrationsDir = $this->tempDir . '/app/migrations';
        mkdir($migrationsDir, 0755, true);
        file_put_contents($migrationsDir . '/0001_test.sql',
            "CREATE TABLE `_setup_test_table` (`id` INT PRIMARY KEY) ENGINE=InnoDB;"
        );

        $wizard = new SetupWizard($this->tempDir);
        $applied = $wizard->runMigrations($pdo);

        $this->assertCount(1, $applied);
        $this->assertSame('0001_test.sql', $applied[0]);

        $applied2 = $wizard->runMigrations($pdo);
        $this->assertEmpty($applied2);

        $pdo->exec("DROP TABLE IF EXISTS `_setup_test_table`");
        $pdo->exec("DROP TABLE IF EXISTS `_migrations`");
    }

    public function testRunMigrationsReturnsEmptyForNoDir(): void
    {
        $emptyDir = sys_get_temp_dir() . '/sk10_nomig_' . uniqid();
        mkdir($emptyDir, 0755, true);
        $wizard = new SetupWizard($emptyDir);

        $dbConfig = TEST_CONFIG['db'];
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbConfig['host'], $dbConfig['port'], $dbConfig['name']);
            $pdo = new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException) {
            $this->markTestSkipped('Test database not available');
        }

        $applied = $wizard->runMigrations($pdo);
        $this->assertEmpty($applied);
        $this->removeDir($emptyDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}

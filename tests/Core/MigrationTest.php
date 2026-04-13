<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use App\Core\Migration;
use PHPUnit\Framework\TestCase;

/**
 * Migration runner tests — require a running MySQL test database.
 */
class MigrationTest extends TestCase
{
    private ?Database $db = null;
    private string $testMigrationsPath;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Create a temporary migrations directory with test migrations
        $this->testMigrationsPath = ROOT_PATH . '/tests/fixtures/test_migrations';
        if (!is_dir($this->testMigrationsPath)) {
            mkdir($this->testMigrationsPath, 0755, true);
        }

        // Clean up
        $this->db->query("DROP TABLE IF EXISTS `_migrations`");
        $this->db->query("DROP TABLE IF EXISTS `_test_migration_table`");

        // Create test migration files
        file_put_contents($this->testMigrationsPath . '/0001_first.sql', "
            CREATE TABLE `_test_migration_table` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        file_put_contents($this->testMigrationsPath . '/0002_second.sql', "
            ALTER TABLE `_test_migration_table` ADD COLUMN `description` TEXT;
        ");
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query("DROP TABLE IF EXISTS `_migrations`");
            $this->db->query("DROP TABLE IF EXISTS `_test_migration_table`");
        }

        // Clean up test migrations
        if (is_dir($this->testMigrationsPath)) {
            foreach (glob($this->testMigrationsPath . '/*.sql') as $file) {
                unlink($file);
            }
            rmdir($this->testMigrationsPath);
        }
    }

    public function testGetMigrationFilesReturnsSorted(): void
    {
        $migration = new Migration($this->db, $this->testMigrationsPath);
        $files = $migration->getMigrationFiles();

        $this->assertCount(2, $files);
        $this->assertSame('0001_first.sql', $files[0]);
        $this->assertSame('0002_second.sql', $files[1]);
    }

    public function testMigrateAppliesAllPending(): void
    {
        $migration = new Migration($this->db, $this->testMigrationsPath);
        $applied = $migration->migrate();

        $this->assertCount(2, $applied);
        $this->assertSame('0001_first.sql', $applied[0]);
        $this->assertSame('0002_second.sql', $applied[1]);
    }

    public function testMigrateSkipsAlreadyApplied(): void
    {
        $migration = new Migration($this->db, $this->testMigrationsPath);

        // First run
        $migration->migrate();

        // Second run — nothing new
        $applied = $migration->migrate();
        $this->assertEmpty($applied);
    }

    public function testGetPendingReturnsOnlyUnapplied(): void
    {
        $migration = new Migration($this->db, $this->testMigrationsPath);
        $migration->migrate();

        // Add a third migration
        file_put_contents($this->testMigrationsPath . '/0003_third.sql', "
            ALTER TABLE `_test_migration_table` ADD COLUMN `extra` VARCHAR(50);
        ");

        $pending = $migration->getPending();
        $this->assertCount(1, $pending);
        $this->assertSame('0003_third.sql', $pending[0]);

        // Clean up
        unlink($this->testMigrationsPath . '/0003_third.sql');
    }

    public function testGetStatusShowsBothAppliedAndPending(): void
    {
        $migration = new Migration($this->db, $this->testMigrationsPath);
        $migration->ensureTable();

        // Apply first migration only (no transaction — DDL causes implicit commit in MySQL)
        $this->db->query(file_get_contents($this->testMigrationsPath . '/0001_first.sql'));
        $this->db->insert('_migrations', ['filename' => '0001_first.sql', 'applied_at' => gmdate('Y-m-d H:i:s')]);

        $status = $migration->getStatus();
        $this->assertCount(2, $status);
        $this->assertSame('applied', $status[0]['status']);
        $this->assertSame('pending', $status[1]['status']);
    }
}

<?php

/**
 * CLI runner for the Northland Seeder.
 *
 * Usage:
 *   php tests/seed.php              — seed the test database with standard dataset (~155 members)
 *   php tests/seed.php --large      — seed with large dataset (~5000 members, for performance testing)
 *
 * Requires: config/config.php with valid database credentials,
 *           or falls back to test config in tests/fixtures/bootstrap.php.
 */

declare(strict_types=1);

// Allow running from project root or tests/ directory
$rootPath = dirname(__DIR__);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $rootPath);
}

// Try production config first, fall back to test config
$configFile = $rootPath . '/config/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
} else {
    $bootstrapFile = $rootPath . '/tests/fixtures/bootstrap.php';
    if (!file_exists($bootstrapFile)) {
        $bootstrapFile = $rootPath . '/tests/fixtures/bootstrap2.php';
    }
    require_once $bootstrapFile;
    $config = TEST_CONFIG;
}

require_once $rootPath . '/app/src/Core/Database.php';
require_once $rootPath . '/tests/Seeders/NorthlandSeeder.php';
require_once $rootPath . '/tests/Seeders/PlaywrightFixtures.php';

use App\Core\Database;
use Tests\Seeders\NorthlandSeeder;

$isLarge = in_array('--large', $argv ?? [], true);

echo "ScoutKeeper — Northland Seeder\n";
echo "==============================\n\n";

try {
    $db = new Database($config['db']);

    // Run migrations first if tables don't exist
    $tableCheck = $db->fetchOne("SHOW TABLES LIKE 'org_nodes'");
    if (!$tableCheck) {
        echo "Running migrations...\n";
        $migrationDir = $rootPath . '/app/migrations';
        $files = glob($migrationDir . '/*.sql');
        sort($files);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            // Split on semicolons but not inside strings
            $statements = array_filter(
                array_map('trim', preg_split('/;\s*$/m', $sql)),
                fn($s) => $s !== ''
            );
            foreach ($statements as $stmt) {
                $db->query($stmt);
            }
            echo "  Applied: " . basename($file) . "\n";
        }
        echo "\n";
    }

    $seeder = new NorthlandSeeder($db);

    echo "Seeding standard dataset...\n";
    $start = microtime(true);
    $seeder->run();
    $elapsed = round(microtime(true) - $start, 2);
    echo "  Done in {$elapsed}s\n\n";

    if ($isLarge) {
        echo "Seeding large dataset (5000+ members)...\n";
        $start = microtime(true);
        seedLargeDataset($db);
        $elapsed = round(microtime(true) - $start, 2);
        echo "  Done in {$elapsed}s\n\n";
    }

    // Summary
    $counts = [
        'members'      => $db->fetchColumn('SELECT COUNT(*) FROM members'),
        'users'        => $db->fetchColumn('SELECT COUNT(*) FROM users'),
        'org_nodes'    => $db->fetchColumn('SELECT COUNT(*) FROM org_nodes'),
        'teams'        => $db->fetchColumn('SELECT COUNT(*) FROM org_teams'),
        'roles'        => $db->fetchColumn('SELECT COUNT(*) FROM roles'),
        'events'       => $db->fetchColumn('SELECT COUNT(*) FROM events'),
        'articles'     => $db->fetchColumn('SELECT COUNT(*) FROM articles'),
        'achievements' => $db->fetchColumn('SELECT COUNT(*) FROM achievement_definitions'),
        'audit_log'    => $db->fetchColumn('SELECT COUNT(*) FROM audit_log'),
    ];

    echo "Summary:\n";
    foreach ($counts as $table => $count) {
        printf("  %-20s %s\n", $table, $count);
    }
    echo "\nPlaywright test users:\n";
    foreach (\Tests\Seeders\PlaywrightFixtures::getUsers() as $key => $user) {
        printf("  %-16s %s\n", $key, $user['email']);
    }
    echo "  Password: " . \Tests\Seeders\PlaywrightFixtures::PASSWORD . "\n";

    echo "\nSeed complete.\n";
} catch (\Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

/**
 * Extend the standard seed with thousands of additional members and events
 * for performance testing.
 */
function seedLargeDataset(Database $db): void
{
    $passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
    $now = gmdate('Y-m-d H:i:s');

    // Get existing section IDs for distributing members
    $sections = $db->fetchAll(
        'SELECT n.id FROM org_nodes n
         JOIN org_level_types lt ON n.level_type_id = lt.id
         WHERE lt.is_leaf = 1'
    );
    $sectionIds = array_column($sections, 'id');

    // Get max membership number
    $maxNum = (int)$db->fetchColumn(
        "SELECT MAX(CAST(SUBSTRING(membership_number, 4) AS UNSIGNED)) FROM members WHERE membership_number LIKE 'SK-%'"
    );

    $firstNames = ['Alex', 'Sam', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Quinn', 'Avery', 'Rowan', 'Blake', 'Drew', 'Sage', 'Sky', 'River', 'Ash', 'Bay', 'Kai', 'Reed', 'Wren'];
    $lastNames  = ['Smith', 'Jones', 'Brown', 'Taylor', 'Wilson', 'Davis', 'Clark', 'Hall', 'Allen', 'Young', 'King', 'Wright', 'Hill', 'Scott', 'Green', 'Adams', 'Baker', 'Nelson', 'Carter', 'Mitchell'];

    echo "  Adding ~4850 members...\n";
    $db->beginTransaction();
    try {
        for ($i = 1; $i <= 4850; $i++) {
            $num = $maxNum + $i;
            $fn = $firstNames[$i % count($firstNames)];
            $ln = $lastNames[$i % count($lastNames)];
            $memberId = $db->insert('members', [
                'membership_number' => sprintf('SK-%06d', $num),
                'first_name'        => $fn,
                'surname'           => $ln . $i,
                'dob'               => sprintf('%04d-%02d-%02d', 2000 + ($i % 20), ($i % 12) + 1, ($i % 28) + 1),
                'gender'            => $i % 2 === 0 ? 'male' : 'female',
                'email'             => strtolower("$fn.$ln$i@bulk.test"),
                'status'            => 'active',
                'joined_date'       => sprintf('%04d-%02d-01', 2018 + ($i % 7), ($i % 12) + 1),
                'gdpr_consent'      => 1,
                'country'           => 'Northland',
                'created_at'        => $now,
            ]);
            $db->query(
                'INSERT INTO member_nodes (member_id, node_id, is_primary) VALUES (?, ?, 1)',
                [$memberId, $sectionIds[$i % count($sectionIds)]]
            );
        }

        // Add 200 extra events
        echo "  Adding 200 events...\n";
        $adminId = $db->fetchColumn("SELECT id FROM users WHERE email = 'admin@northland.test'");
        for ($i = 1; $i <= 200; $i++) {
            $month = ($i % 12) + 1;
            $day = ($i % 28) + 1;
            $year = 2025 + ($i % 3);
            $db->insert('events', [
                'title'        => "Bulk Event #$i",
                'description'  => "Performance testing event $i",
                'location'     => 'Test Location',
                'start_date'   => sprintf('%04d-%02d-%02d 10:00:00', $year, $month, $day),
                'end_date'     => sprintf('%04d-%02d-%02d 16:00:00', $year, $month, $day),
                'all_day'      => 0,
                'created_by'   => $adminId,
                'is_published' => 1,
            ]);
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

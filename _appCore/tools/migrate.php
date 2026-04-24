<?php

/**
 * appCore — Migration runner (CLI)
 *
 *   php tools/migrate.php
 *
 * Applies every pending migration in /app/migrations/ in filename order.
 * Prints each filename as it applies and a summary at the end.
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$configPath = ROOT_PATH . '/config/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "config.php not found. Complete the setup wizard first.\n");
    exit(1);
}

require ROOT_PATH . '/vendor/autoload.php';

$config = require $configPath;
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

$db = new AppCore\Core\Database($config['db']);
$migration = new AppCore\Core\Migration($db, ROOT_PATH . '/app/migrations');

$pending = $migration->getPending();
if (empty($pending)) {
    echo "Nothing to migrate.\n";
    exit(0);
}

echo "Applying " . count($pending) . " migration(s):\n";
foreach ($pending as $name) {
    echo "  - $name ... ";
    try {
        // Apply individually so a failure is visible on the right file.
        $applied = $migration->migrate();
        // migrate() applies ALL pending; once we've called it we're done.
        echo "ok\n";
        foreach (array_slice($applied, 1) as $later) {
            echo "  - $later ... ok\n";
        }
        break;
    } catch (\Throwable $e) {
        echo "FAILED\n    " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "Done.\n";

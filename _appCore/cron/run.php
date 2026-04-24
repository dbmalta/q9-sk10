<?php

/**
 * appCore — Cron Entry Point
 *
 * Dispatches every module's registered cron handlers.
 *
 * CLI usage (recommended for hosted cron):
 *   /usr/bin/php /path/to/appcore/cron/run.php
 *
 * HTTP usage (for shared hosts without CLI cron):
 *   GET /cron/run.php?secret=YOUR_CRON_SECRET
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

$isCli = php_sapi_name() === 'cli';

$configPath = ROOT_PATH . '/config/config.php';
if (!file_exists($configPath)) {
    if ($isCli) {
        fwrite(STDERR, "config.php not found.\n");
    }
    exit(1);
}

$config = require $configPath;

if (!$isCli) {
    $secret = $config['cron']['secret'] ?? '';
    $provided = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, (string) $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit(1);
    }
}

require ROOT_PATH . '/vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

AppCore\Core\Application::init($config);
$app = AppCore\Core\Application::getInstance();

// The Application orchestrator normally sets up these services during run().
// For cron we need a subset: DB + module registry.
$db = new AppCore\Core\Database($config['db']);
$modules = new AppCore\Core\ModuleRegistry();
$modules->loadModules(ROOT_PATH . '/app/modules');

$handlers = $modules->getCronHandlers();
$results = [];

foreach ($handlers as $handler) {
    $start = microtime(true);
    try {
        $handler->execute($app);
        $results[] = [
            'handler' => get_class($handler),
            'status'  => 'ok',
            'ms'      => round((microtime(true) - $start) * 1000, 2),
        ];
    } catch (\Throwable $e) {
        $results[] = [
            'handler' => get_class($handler),
            'status'  => 'error',
            'error'   => $e->getMessage(),
            'ms'      => round((microtime(true) - $start) * 1000, 2),
        ];
        AppCore\Core\Logger::error('Cron handler failed', [
            'handler' => get_class($handler),
            'error'   => $e->getMessage(),
        ]);
    }
}

@file_put_contents(ROOT_PATH . '/var/cache/cron_last_run.txt', (string) time());

$entry = [
    'timestamp' => gmdate('c'),
    'mode'      => $isCli ? 'cli' : 'http',
    'handlers'  => $results,
];

$logFile = ROOT_PATH . '/var/logs/cron.json';
$existing = [];
if (file_exists($logFile)) {
    $existing = json_decode((string) file_get_contents($logFile), true) ?? [];
}
$existing[] = $entry;
if (count($existing) > 100) {
    $existing = array_slice($existing, -100);
}
@file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));

if ($isCli) {
    echo "Cron run completed (" . count($results) . " handlers).\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($entry);
}

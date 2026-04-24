<?php

/**
 * appCore — Bootstrap
 *
 * Loads Composer autoloader, the config file, and initialises the
 * Application singleton. Called by /index.php on every request.
 */

declare(strict_types=1);

$autoloader = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    http_response_code(500);
    echo 'Vendor dependencies not installed. Run composer install.';
    exit(1);
}
require $autoloader;

$configPath = ROOT_PATH . '/config/config.php';
if (!file_exists($configPath)) {
    header('Location: /setup');
    exit;
}

$config = require $configPath;

if ($config['app']['debug'] ?? false) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

AppCore\Core\Application::init($config);

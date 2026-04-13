<?php

/**
 * ScoutKeeper — Application Bootstrap
 *
 * Initialises the autoloader, loads configuration, and prepares
 * the Application singleton. Called by index.php on every request.
 */

declare(strict_types=1);

// Composer autoloader
$autoloader = ROOT_PATH . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    http_response_code(500);
    echo 'Vendor dependencies not installed. Run composer install.';
    exit(1);
}
require $autoloader;

// Load configuration
$configPath = ROOT_PATH . '/config/config.php';
if (!file_exists($configPath)) {
    // No config — redirect to setup wizard
    header('Location: /setup');
    exit;
}

$config = require $configPath;

// Error reporting based on config
if ($config['app']['debug'] ?? false) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

// Set timezone
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// Initialise the Application singleton
App\Core\Application::init($config);

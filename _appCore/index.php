<?php

/**
 * appCore — Front Controller
 *
 * Single entry point for all web requests. Part of the bootstrap tier
 * (Tier 0) and is never replaced by the auto-updater.
 */

declare(strict_types=1);

define('ROOT_PATH', __DIR__);

// PHP built-in dev server: serve static files directly
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = ROOT_PATH . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

// Maintenance mode (but allow /setup and /updater/*)
if (file_exists(ROOT_PATH . '/var/maintenance.flag')) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($uri !== '/setup' && !str_starts_with((string) $uri, '/updater/')) {
        http_response_code(503);
        $maintFile = ROOT_PATH . '/app/templates/errors/maintenance.html';
        if (file_exists($maintFile)) {
            include $maintFile;
        } else {
            echo '<!DOCTYPE html><html><head><title>Maintenance</title></head><body>'
               . '<h1>System Maintenance</h1>'
               . '<p>We are performing scheduled maintenance. Please try again shortly.</p>'
               . '</body></html>';
        }
        exit;
    }
}

// Setup wizard gate: runs when config is missing, or when /setup is explicitly visited.
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$configExists = file_exists(ROOT_PATH . '/config/config.php');

if (!$configExists || $requestUri === '/setup') {
    if (!file_exists(ROOT_PATH . '/vendor/autoload.php')) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><title>Dependencies Missing</title>'
           . '<style>body{font-family:system-ui,sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#333}'
           . 'h1{color:#dc3545}code{background:#f5f5f5;padding:2px 6px;border-radius:3px}</style></head>'
           . '<body><h1>Dependencies Not Installed</h1>'
           . '<p>appCore requires Composer dependencies to run.</p>'
           . '<p>Run <code>composer install --no-dev</code> in the project root.</p>'
           . '</body></html>';
        exit;
    }
    require ROOT_PATH . '/vendor/autoload.php';

    session_start();

    if ($configExists) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Setup Unavailable</title></head><body>'
           . '<h1>Setup Already Complete</h1>'
           . '<p>Configuration already exists. Delete <code>config/config.php</code> to re-run setup.</p>'
           . '<p><a href="/login">Go to login</a></p></body></html>';
        exit;
    }

    if ($requestUri !== '/setup') {
        header('Location: /setup');
        exit;
    }

    $wizard = new \AppCore\Setup\SetupWizard(ROOT_PATH);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $step = (int) ($_POST['step'] ?? $wizard->getCurrentStep());
        $result = $wizard->processStep($step, $_POST);

        if ($result['success']) {
            if ($step === 8) {
                echo $wizard->renderStep(8, ['justFinished' => true]);
                exit;
            }
            header('Location: /setup?step=' . $result['next_step']);
            exit;
        }

        echo $wizard->renderStep($step, ['errors' => $result['errors']]);
        exit;
    }

    $maxReached = (int) ($_SESSION['setup_step'] ?? 1);
    $step = isset($_GET['step']) ? (int) $_GET['step'] : $maxReached;
    $step = max(1, min($step, $maxReached));
    echo $wizard->renderStep($step);
    exit;
}

// Bootstrap and run
require ROOT_PATH . '/app/bootstrap.php';
AppCore\Core\Application::run();

<?php

/**
 * appCore — Updater Entry Point
 *
 * Token-gated. Invoked via GET /updater/run.php?token=...
 *
 * The admin "apply update" action writes a one-time token to
 * /var/update_token.txt and redirects here. The manager consumes the
 * token, toggles maintenance mode, unzips /var/updates/*.zip, swaps /app/,
 * runs migrations, and clears maintenance mode.
 *
 * Operators who want automated fetch from a release server can extend
 * UpdateManager with a downloader; the scaffold assumes the zip is
 * already in /var/updates/ when this entry point runs.
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

$isBrowser = !isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && (empty($_SERVER['HTTP_ACCEPT']) || strpos((string) $_SERVER['HTTP_ACCEPT'], 'text/html') !== false);

if (!$isBrowser) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/UpdateManager.php';

$updater = new \Updater\UpdateManager(ROOT_PATH);

$token = $_GET['token'] ?? '';
if ($token === '' || !$updater->verifyUpdateToken((string) $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired update token.']);
    exit(1);
}

$updater->setMaintenanceMode(true);

// Look for a zip to apply. State file is optional — if absent, take the
// newest .zip in /var/updates/.
$state = $updater->getUpdateState();
$zipPath = $state['zip_path'] ?? null;
if ($zipPath === null || !file_exists($zipPath)) {
    $zips = glob(ROOT_PATH . '/var/updates/*.zip') ?: [];
    if (!empty($zips)) {
        usort($zips, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        $zipPath = $zips[0];
    }
}

if ($zipPath === null || !file_exists($zipPath)) {
    $updater->setMaintenanceMode(false);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No update package found in /var/updates/.']);
    exit(1);
}

$newVersion = $state['new_version'] ?? null;
$result = $updater->applyUpdate($zipPath, $newVersion);

if (file_exists($zipPath)) {
    @unlink($zipPath);
}

$logFile = ROOT_PATH . '/var/logs/updates.json';
$existing = file_exists($logFile)
    ? (json_decode((string) file_get_contents($logFile), true) ?? [])
    : [];

if ($result['success']) {
    $updater->setMaintenanceMode(false);
    $existing[] = [
        'timestamp' => gmdate('c'),
        'status'    => 'success',
        'steps'     => $result['steps_completed'],
    ];
    @file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));

    if ($isBrowser) {
        header('Location: /admin/updates?updated=1');
        exit;
    }
    echo json_encode([
        'success'  => true,
        'message'  => 'Update applied.',
        'steps'    => $result['steps_completed'],
    ]);
    exit;
}

$rolledBack = $updater->rollback();
$updater->setMaintenanceMode(false);

$existing[] = [
    'timestamp'   => gmdate('c'),
    'status'      => 'failed',
    'error'       => $result['error'] ?? 'unknown',
    'steps'       => $result['steps_completed'],
    'rolled_back' => $rolledBack,
];
@file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));

if ($isBrowser) {
    header('Location: /admin/updates?update_failed=1');
    exit;
}
http_response_code(500);
echo json_encode([
    'success'         => false,
    'error'           => $result['error'] ?? 'unknown',
    'steps_completed' => $result['steps_completed'],
    'rolled_back'     => $rolledBack,
]);

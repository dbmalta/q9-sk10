<?php

/**
 * ScoutKeeper -- Update Runner (Phase 2)
 *
 * This is the standalone entry point for applying an update.
 * It is invoked by the admin panel with a single-use token:
 *
 *   GET /updater/run.php?token=XXXXXX
 *
 * Flow:
 *   1. Verify the single-use token
 *   2. Set maintenance mode
 *   3. Apply the update (extract, swap /app/, run migrations)
 *   4. Clear maintenance mode
 *   5. Redirect back to the admin panel
 *
 * This file lives in /updater/ (outside /app/) so it is NOT replaced
 * during the update. It is part of the bootstrap tier (Tier 0).
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

// Detect whether this is a direct browser visit or an API/AJAX call.
$isBrowser = !isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && (empty($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'text/html') !== false);

if (!$isBrowser) {
    header('Content-Type: application/json; charset=UTF-8');
}

require_once __DIR__ . '/UpdateManager.php';

$updater = new \Updater\UpdateManager(ROOT_PATH);

// ── Step 1: Verify token ────────────────────────────────────────────

$token = $_GET['token'] ?? '';

if ($token === '' || !$updater->verifyUpdateToken($token)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or expired update token.',
    ]);
    exit(1);
}

// ── Step 2: Set maintenance mode ────────────────────────────────────

$updater->setMaintenanceMode(true);

// ── Step 3: Find the downloaded zip and apply ───────────────────────

$state = $updater->getUpdateState();
$zipPath = $state['zip_path'] ?? null;

if ($zipPath === null || !file_exists($zipPath)) {
    $updater->setMaintenanceMode(false);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No update package found. Download the release first.',
    ]);
    exit(1);
}

$newVersion = $state['new_version'] ?? null;
$result = $updater->applyUpdate($zipPath, $newVersion);

// ── Step 4: Clean up ────────────────────────────────────────────────

// Remove the zip file after applying
if (file_exists($zipPath)) {
    @unlink($zipPath);
}

// Remove signature file if it exists
$sigPath = $zipPath . '.sig';
if (file_exists($sigPath)) {
    @unlink($sigPath);
}

// ── Step 5: Clear maintenance and respond ───────────────────────────

// ── Log helper ──────────────────────────────────────────────────────

$logFile = ROOT_PATH . '/var/logs/updates.json';

function appendUpdateLog(string $logFile, array $entry): void
{
    $existing = [];
    if (file_exists($logFile)) {
        $existing = json_decode(file_get_contents($logFile), true) ?? [];
    }
    $existing[] = $entry;
    file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
}

// ── Respond helper ───────────────────────────────────────────────────

function respond(bool $isBrowser, bool $success, array $data): void
{
    if ($isBrowser) {
        $location = $success
            ? ($data['redirect'] ?? '/admin/settings')
            : ($data['redirect'] ?? '/admin/updates');
        // Append a flash-style query param so the admin page can show a message
        $sep = strpos($location, '?') !== false ? '&' : '?';
        $location .= $sep . ($success ? 'updated=1' : 'update_failed=1');
        header('Location: ' . $location, true, 302);
        exit(0);
    }

    if (!$success) {
        http_response_code(500);
    }
    echo json_encode($data);
    exit(0);
}

if ($result['success']) {
    $updater->setMaintenanceMode(false);

    appendUpdateLog($logFile, [
        'timestamp' => gmdate('c'),
        'status' => 'success',
        'steps' => $result['steps_completed'],
    ]);

    respond($isBrowser, true, [
        'success' => true,
        'message' => 'Update applied successfully.',
        'steps' => $result['steps_completed'],
        'redirect' => '/admin/settings',
    ]);
} else {
    // Attempt rollback
    $rolledBack = $updater->rollback();
    $updater->setMaintenanceMode(false);

    appendUpdateLog($logFile, [
        'timestamp' => gmdate('c'),
        'status' => 'failed',
        'error' => $result['error'],
        'steps' => $result['steps_completed'],
        'rolled_back' => $rolledBack,
    ]);

    respond($isBrowser, false, [
        'success' => false,
        'error' => $result['error'],
        'steps_completed' => $result['steps_completed'],
        'rolled_back' => $rolledBack,
        'redirect' => '/admin/updates',
    ]);
}

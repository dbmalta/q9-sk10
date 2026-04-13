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

header('Content-Type: application/json; charset=UTF-8');

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

$result = $updater->applyUpdate($zipPath);

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

if ($result['success']) {
    $updater->setMaintenanceMode(false);

    // Log the successful update
    $logFile = ROOT_PATH . '/var/logs/updates.json';
    $logEntry = [
        'timestamp' => gmdate('c'),
        'status' => 'success',
        'steps' => $result['steps_completed'],
    ];
    $existing = [];
    if (file_exists($logFile)) {
        $existing = json_decode(file_get_contents($logFile), true) ?? [];
    }
    $existing[] = $logEntry;
    file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => true,
        'message' => 'Update applied successfully.',
        'steps' => $result['steps_completed'],
        'redirect' => '/admin/settings',
    ]);
} else {
    // Attempt rollback
    $rolledBack = $updater->rollback();
    $updater->setMaintenanceMode(false);

    // Log the failed update
    $logFile = ROOT_PATH . '/var/logs/updates.json';
    $logEntry = [
        'timestamp' => gmdate('c'),
        'status' => 'failed',
        'error' => $result['error'],
        'steps' => $result['steps_completed'],
        'rolled_back' => $rolledBack,
    ];
    $existing = [];
    if (file_exists($logFile)) {
        $existing = json_decode(file_get_contents($logFile), true) ?? [];
    }
    $existing[] = $logEntry;
    file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $result['error'],
        'steps_completed' => $result['steps_completed'],
        'rolled_back' => $rolledBack,
    ]);
}

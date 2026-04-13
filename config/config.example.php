<?php

/**
 * ScoutKeeper — Configuration File
 *
 * Copy this file to config.php and fill in your settings.
 * This file is never committed to version control or included in updates.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'scoutkeeper',
        'user' => 'sk_user',
        'password' => '',
    ],

    'app' => [
        'name' => 'ScoutKeeper',
        'url' => 'https://your-domain.com',
        'timezone' => 'UTC',
        'debug' => false,
        'language' => 'en',
    ],

    'smtp' => [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls', // tls or ssl
        'from_email' => 'noreply@your-domain.com',
        'from_name' => 'ScoutKeeper',
    ],

    'security' => [
        'encryption_key_file' => __DIR__ . '/encryption.key',
        'session_timeout' => 7200, // seconds
    ],

    'monitoring' => [
        'api_key' => '', // leave empty to disable /api/logs endpoint
        'slow_query_threshold_ms' => 1000,
    ],

    'cron' => [
        'secret' => '', // shared secret for cron/run.php web access
        'email_batch_size' => 20,
        'email_interval_seconds' => 60,
    ],
];

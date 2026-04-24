<?php

/**
 * appCore — Configuration template.
 *
 * The setup wizard writes a populated config.php when the application is
 * first installed. This file exists as a reference for operators who want
 * to hand-edit or regenerate their configuration afterwards.
 *
 * Placeholders:
 *   {{PROJECT_NAME}}   Human-readable project name, e.g. "My Project"
 *   {{PROJECT_SLUG}}   Machine-friendly slug, e.g. "my_project"
 *   {{VENDOR}}         Organisation / company name
 *   {{VENDOR_SLUG}}    Slug form of the vendor
 */

declare(strict_types=1);

return [
    'app' => [
        'name'     => '{{PROJECT_NAME}}',
        'url'      => 'https://example.com',
        'timezone' => 'UTC',
        'language' => 'en',
        'debug'    => false,
    ],

    'db' => [
        'host'     => 'localhost',
        'port'     => '3306',
        'name'     => '{{PROJECT_SLUG}}',
        'user'     => 'app',
        'password' => '',
    ],

    'security' => [
        'session_timeout'     => 3600,
        'encryption_key_file' => __DIR__ . '/encryption.key',
    ],

    'smtp' => [
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls',
        'from_email' => '',
        'from_name'  => '{{PROJECT_NAME}}',
    ],

    'cron' => [
        'secret'                 => '',
        'email_interval_seconds' => 60,
    ],

    'monitoring' => [
        'api_key'                   => '',
        'slow_request_threshold_ms' => 500,
        'slow_request_query_count'  => 20,
        'slow_query_threshold_ms'   => 100,
    ],
];

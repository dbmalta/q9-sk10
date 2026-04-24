<?php

/**
 * PHPUnit bootstrap.
 *
 * Reads DB credentials from environment variables so tests can run against
 * a dedicated test database without touching the production config.
 *
 * Required env vars (optional — tests that need the DB will markTestSkipped
 * if they are missing):
 *
 *   APPCORE_TEST_DB_HOST
 *   APPCORE_TEST_DB_PORT
 *   APPCORE_TEST_DB_NAME
 *   APPCORE_TEST_DB_USER
 *   APPCORE_TEST_DB_PASSWORD
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));

require ROOT_PATH . '/vendor/autoload.php';

/**
 * Helper available in tests. Returns null when no test-DB env vars are set,
 * in which case tests should markTestSkipped.
 */
function appcore_test_db_config(): ?array
{
    $host = getenv('APPCORE_TEST_DB_HOST');
    $name = getenv('APPCORE_TEST_DB_NAME');
    $user = getenv('APPCORE_TEST_DB_USER');
    if ($host === false || $name === false || $user === false) {
        return null;
    }
    return [
        'host'     => $host,
        'port'     => getenv('APPCORE_TEST_DB_PORT') ?: '3306',
        'name'     => $name,
        'user'     => $user,
        'password' => getenv('APPCORE_TEST_DB_PASSWORD') ?: '',
    ];
}

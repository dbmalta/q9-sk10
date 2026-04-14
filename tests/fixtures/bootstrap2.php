<?php
declare(strict_types=1);
if (!defined("ROOT_PATH")) {
    define("ROOT_PATH", dirname(__DIR__, 2));
}
require ROOT_PATH . "/vendor/autoload.php";
define("TEST_CONFIG", [
    "db" => [
        "host" => getenv("DB_HOST") ?: "localhost",
        "port" => getenv("DB_PORT") ?: "3306",
        "name" => getenv("DB_DATABASE") ?: "scoutkeeper_test",
        "user" => getenv("DB_USERNAME") ?: "sk_test",
        "password" => getenv("DB_PASSWORD") ?: "sk_test_pass",
    ],
    "app" => ["name" => "ScoutKeeper Test", "url" => "http://localhost:8080", "timezone" => "UTC", "debug" => true, "language" => "en"],
    "security" => ["encryption_key_file" => ""],
    "smtp" => ["host" => "", "port" => 587, "user" => "", "password" => "", "from_email" => "test@scoutkeeper.local", "from_name" => "ScoutKeeper Test"],
]);

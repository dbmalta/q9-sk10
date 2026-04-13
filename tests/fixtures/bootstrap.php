<?php
declare(strict_types=1);
define("ROOT_PATH", dirname(__DIR__, 2));
require ROOT_PATH . "/vendor/autoload.php";
define("TEST_CONFIG", [
    "db" => ["host" => "localhost", "port" => "3306", "name" => "scoutkeeper_test", "user" => "sk_test", "password" => "sk_test_pass"],
    "app" => ["name" => "ScoutKeeper Test", "url" => "http://localhost:8080", "timezone" => "UTC", "debug" => true, "language" => "en"],
    "security" => ["encryption_key_file" => ""],
    "smtp" => ["host" => "", "port" => 587, "user" => "", "password" => "", "from_email" => "test@scoutkeeper.local", "from_name" => "ScoutKeeper Test"],
]);

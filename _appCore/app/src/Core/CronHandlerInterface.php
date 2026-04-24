<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * A module registers cron tasks in its module.php under the `cron` key
 * by listing handler class names. The dispatcher instantiates each and
 * calls execute().
 */
interface CronHandlerInterface
{
    public function execute(Application $app): void;
}

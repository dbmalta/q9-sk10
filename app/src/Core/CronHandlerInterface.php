<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Interface for cron task handlers.
 *
 * Each module can register one or more cron handlers in its module.php.
 * The cron dispatcher calls execute() on each handler in sequence.
 */
interface CronHandlerInterface
{
    /**
     * Execute the cron task.
     *
     * @param Application $app The application instance
     */
    public function execute(Application $app): void;
}

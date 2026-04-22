<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Simple structured logger.
 *
 * Writes log entries as JSON to /var/logs/. Errors and warnings go to
 * errors.json; info and debug to app.json (debug mode only).
 * Log files rotate automatically when they exceed 5MB.
 */
class Logger
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    private const MAX_ROTATIONS = 5;

    /**
     * Log an error.
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * Log a warning.
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /**
     * Log an informational message (debug mode only).
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * Log a debug message (debug mode only).
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * Write a log entry.
     *
     * @param string $level error, warning, info, or debug
     * @param string $message The log message
     * @param array $context Additional context data
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        // Info and debug only logged in debug mode
        if (in_array($level, ['info', 'debug'], true)) {
            if (!defined('ROOT_PATH')) {
                return;
            }
            // Check if we can determine debug mode
            try {
                $app = Application::getInstance();
                if (!$app->getConfigValue('app.debug', false)) {
                    return;
                }
            } catch (\RuntimeException) {
                return;
            }
        }

        $logDir = (defined('ROOT_PATH') ? ROOT_PATH : __DIR__ . '/../../..') . '/var/logs';
        if (!is_dir($logDir)) {
            return;
        }

        $file = in_array($level, ['error', 'warning', 'fatal'], true)
            ? $logDir . '/errors.json'
            : $logDir . '/app.json';

        $entry = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'user_id' => $_SESSION['user']['id'] ?? null,
        ];

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        // Read existing entries
        $entries = [];
        if (file_exists($file)) {
            $entries = json_decode(file_get_contents($file), true) ?? [];
        }

        $entries[] = $entry;

        // Rotate if file is too large
        $content = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($content === false) {
            // Fallback: encode just the new entry
            $content = json_encode([$entry], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
        }
        if (strlen($content) > self::MAX_FILE_SIZE) {
            self::rotate($file);
            $entries = [$entry];
            $content = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        file_put_contents($file, $content, LOCK_EX);
    }

    /**
     * Append a structured SMTP event to var/logs/smtp.json.
     *
     * Writes regardless of debug mode so operators can troubleshoot
     * delivery issues. Rotation uses the same size threshold as log().
     *
     * @param string $direction One of: connect, send, recv, info, error
     * @param string $message   Short human-readable summary
     * @param array  $context   Additional structured data (host, port, response, etc.)
     */
    public static function smtp(string $direction, string $message, array $context = []): void
    {
        $logDir = (defined('ROOT_PATH') ? ROOT_PATH : __DIR__ . '/../../..') . '/var/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
            if (!is_dir($logDir)) {
                return;
            }
        }

        $file = $logDir . '/smtp.json';

        $entry = [
            'timestamp' => gmdate('c'),
            'direction' => $direction,
            'message'   => $message,
            'user_id'   => $_SESSION['user']['id'] ?? null,
        ];
        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $entries = [];
        if (file_exists($file)) {
            $entries = json_decode(file_get_contents($file), true) ?? [];
        }
        $entries[] = $entry;

        $content = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($content === false) {
            $content = json_encode([$entry], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
        }
        if (strlen($content) > self::MAX_FILE_SIZE) {
            self::rotate($file);
            $content = json_encode([$entry], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        file_put_contents($file, $content, LOCK_EX);
    }

    /**
     * Rotate a log file: current → .1.json, .1 → .2, etc.
     */
    private static function rotate(string $file): void
    {
        $base = substr($file, 0, -5); // strip .json

        // Shift existing rotations
        for ($i = self::MAX_ROTATIONS; $i >= 1; $i--) {
            $old = $base . '.' . $i . '.json';
            $new = $base . '.' . ($i + 1) . '.json';
            if (file_exists($old)) {
                if ($i === self::MAX_ROTATIONS) {
                    unlink($old);
                } else {
                    rename($old, $new);
                }
            }
        }

        // Move current to .1
        if (file_exists($file)) {
            rename($file, $base . '.1.json');
        }
    }
}

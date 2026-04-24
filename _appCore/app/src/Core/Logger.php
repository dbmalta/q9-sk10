<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Simple structured logger.
 *
 * Writes JSON entries to /var/logs/. Errors and warnings go to
 * errors.json, info and debug to app.json (debug mode only). SMTP events
 * go to smtp.json. Files rotate at 5 MB, keeping up to 5 backups.
 */
class Logger
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private const MAX_ROTATIONS = 5;

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (in_array($level, ['info', 'debug'], true)) {
            if (!defined('ROOT_PATH')) {
                return;
            }
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
            'timestamp'   => gmdate('c'),
            'level'       => $level,
            'message'     => $message,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'user_id'     => $_SESSION['user']['id'] ?? null,
        ];

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $entries = [];
        if (file_exists($file)) {
            $entries = json_decode((string) file_get_contents($file), true) ?? [];
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
            $entries = json_decode((string) file_get_contents($file), true) ?? [];
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

    private static function rotate(string $file): void
    {
        $base = substr($file, 0, -5);

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

        if (file_exists($file)) {
            rename($file, $base . '.1.json');
        }
    }
}

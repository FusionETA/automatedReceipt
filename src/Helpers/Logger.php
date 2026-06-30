<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Storage\Database;
use App\Config\Bootstrap;

/**
 * Simple logger — writes to file AND the activity_log DB table.
 */
class Logger
{
    private static string $logFile = '';

    private static function logFile(): string
    {
        if (self::$logFile === '') {
            $dir = Bootstrap::env('LOG_PATH', './storage/logs');
            $dir = dirname(__DIR__, 2) . '/' . ltrim($dir, './');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$logFile = $dir . '/app-' . date('Y-m-d') . '.log';
        }
        return self::$logFile;
    }

    public static function info(string $event, string $message, array $context = []): void
    {
        self::write('info', $event, $message, $context);
    }

    public static function warning(string $event, string $message, array $context = []): void
    {
        self::write('warning', $event, $message, $context);
    }

    public static function error(string $event, string $message, array $context = []): void
    {
        self::write('error', $event, $message, $context);
    }

    private static function write(string $level, string $event, string $message, array $context): void
    {
        $timestamp   = date('Y-m-d H:i:s');
        $contextJson = empty($context) ? '' : ' | ' . json_encode($context);
        $line        = "[{$timestamp}] [{$level}] [{$event}] {$message}{$contextJson}\n";

        // Write to daily log file
        file_put_contents(self::logFile(), $line, FILE_APPEND | LOCK_EX);

        // Write to DB (best-effort — don't crash if DB is unavailable)
        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                'INSERT INTO activity_log (level, event, message, context) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$level, $event, $message, empty($context) ? null : json_encode($context)]);
        } catch (\Throwable $e) {
            // Silently ignore DB write failure so logging never crashes the app
        }
    }
}
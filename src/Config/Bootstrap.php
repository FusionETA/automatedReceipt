<?php

declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;

/**
 * App bootstrap: loads .env, sets up autoloading, initialises DB.
 */
class Bootstrap
{
    private static bool $initialised = false;

    public static function init(): void
    {
        if (self::$initialised) {
            return;
        }

        // Start session before any output so the cookie can be set
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Load .env from project root (one level up from /public)
        $root = dirname(__DIR__, 2);
        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();

        // Set timezone — all date() calls will use local time
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

        // Required env vars — blow up early if missing
        $dotenv->required([
            'XERO_CLIENT_ID',
            'XERO_CLIENT_SECRET',
            'XERO_REDIRECT_URI',
            'XERO_WEBHOOK_KEY',
            'MAIL_HOST',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'MAIL_FROM_ADDRESS',
        ]);

        self::$initialised = true;
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $default;
    }

    /**
     * Build a URL relative to the app's base path.
     * Set APP_BASE_PATH=/app/xero-receipt-app/public in .env
     *
     * url('/login.php') → /app/xero-receipt-app/public/login.php
     */
    public static function url(string $path = '/'): string
    {
        $base = rtrim($_ENV['APP_BASE_PATH'] ?? '', '/');
        return $base . '/' . ltrim($path, '/');
    }
}
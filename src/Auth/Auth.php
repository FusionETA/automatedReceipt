<?php

declare(strict_types=1);

namespace App\Auth;

use App\Xero\UserTokenStorage;
use App\Config\Bootstrap;
/**
 * Auth — session management for Xero-based identity.
 *
 * Identity comes entirely from Xero. No passwords stored locally.
 * A user is "logged in" when we have their Xero user ID in session.
 *
 * Session keys:
 *   xero_user_id        — Xero's stable user identifier
 *   xero_user_email     — user's email from Xero identity
 *   xero_user_name      — display name from Xero
 *   active_tenant_id    — currently selected org
 */
class Auth
{
    // ── Login / Logout ────────────────────────────────────────────────

    /**
     * Store Xero identity in session after successful OAuth.
     */
    public static function login(
        string $xeroUserId,
        string $email,
        string $name,
        string $activeTenantId = ''
    ): void {
        self::ensureSession();
        session_regenerate_id(true); // prevent session fixation

        $_SESSION['xero_user_id']     = $xeroUserId;
        $_SESSION['xero_user_email']  = $email;
        $_SESSION['xero_user_name']   = $name;
        $_SESSION['active_tenant_id'] = $activeTenantId;
    }

    /**
     * Clear session and log out.
     */
    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        session_destroy();
    }

    // ── Identity ──────────────────────────────────────────────────────

    public static function check(): bool
    {
        self::ensureSession();
        return !empty($_SESSION['xero_user_id']);
    }

    public static function userId(): string
    {
        return $_SESSION['xero_user_id'] ?? '';
    }

    public static function userEmail(): string
    {
        return $_SESSION['xero_user_email'] ?? '';
    }

    public static function userName(): string
    {
        return $_SESSION['xero_user_name'] ?? '';
    }

    // ── Active tenant ─────────────────────────────────────────────────

    public static function activeTenantId(): string
    {
        return $_SESSION['active_tenant_id'] ?? '';
    }

    public static function setActiveTenant(string $tenantId): void
    {
        self::ensureSession();
        $_SESSION['active_tenant_id'] = $tenantId;
    }

    // ── Org access check ──────────────────────────────────────────────

    /**
     * Check whether the logged-in user has a token for a given tenant.
     * This is the core access control gate — Xero is the source of truth.
     */
    public static function canAccessOrg(string $tenantId): bool
    {
        if (!self::check()) return false;

        $tokens = UserTokenStorage::getAll(self::userId());
        return isset($tokens[$tenantId]);
    }

    /**
     * Require the user to be logged in.
     * Redirects to /login.php if not authenticated.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . Bootstrap::url('/login.php'));
            exit;
        }
    }

    /**
     * Require the user to be logged in AND have access to the active org.
     * Redirects appropriately if either check fails.
     */
    public static function requireOrgAccess(string $tenantId = ''): void
    {
        self::requireLogin();

        $tenantId = $tenantId ?: self::activeTenantId();

        if (!$tenantId || !self::canAccessOrg($tenantId)) {
            // They're logged in but don't have this org — send to dashboard
            header('Location: ' . Bootstrap::url('/index.php'));
            exit;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Xero;

/**
 * UserTokenStorage — per-user OAuth token persistence.
 *
 * Each Xero user gets their own token file:
 *   storage/users/{xero_user_id}/tokens.json
 *
 * The file is a map of tenantId → token data, so one user
 * can have tokens for multiple orgs without conflict.
 *
 * Two users in the same org each have their own token file —
 * they refresh independently and never collide.
 */
class UserTokenStorage
{
    // ── Path helpers ──────────────────────────────────────────────────

    private static function userDir(string $userId): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/users/' . $userId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function tokenPath(string $userId): string
    {
        return self::userDir($userId) . '/tokens.json';
    }

    // ── Read ──────────────────────────────────────────────────────────

    /**
     * Get all tokens for a user (keyed by tenantId).
     * Returns empty array if no tokens stored yet.
     */
    public static function getAll(string $userId): array
    {
        $path = self::tokenPath($userId);
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?: [];
    }

    /**
     * Get token for a specific tenant, or null if not found.
     */
    public static function getForTenant(string $userId, string $tenantId): ?array
    {
        $all = self::getAll($userId);
        return $all[$tenantId] ?? null;
    }

    /**
     * Get the first available token for a user (used for active tenant fallback).
     */
    public static function getFirst(string $userId): ?array
    {
        $all = self::getAll($userId);
        return empty($all) ? null : reset($all);
    }

    /**
     * Check if a user has any tokens stored.
     */
    public static function hasAny(string $userId): bool
    {
        return !empty(self::getAll($userId));
    }

    /**
     * Find any valid (non-expired) token for a given org across ALL users.
     * Used by webhook.php which has no user session.
     *
     * Scans storage/users/ looking for a token for $tenantId.
     */
    public static function findAnyValidTokenForOrg(string $tenantId): ?array
    {
        $usersDir = dirname(__DIR__, 2) . '/storage/users';
        if (!is_dir($usersDir)) return null;

        foreach (scandir($usersDir) as $userId) {
            if ($userId === '.' || $userId === '..') continue;

            $token = self::getForTenant($userId, $tenantId);
            if (!$token) continue;

            // Prefer non-expired tokens, but return expired ones as last resort
            // (OAuthClient will refresh them automatically)
            return $token;
        }

        return null;
    }

    /**
     * Find the userId who owns a valid token for a given org.
     * Used by webhook.php to know which user to refresh tokens as.
     */
    public static function findUserIdForOrg(string $tenantId): ?string
    {
        $usersDir = dirname(__DIR__, 2) . '/storage/users';
        if (!is_dir($usersDir)) return null;

        foreach (scandir($usersDir) as $userId) {
            if ($userId === '.' || $userId === '..') continue;

            $token = self::getForTenant($userId, $tenantId);
            if ($token) return $userId;
        }

        return null;
    }

    // ── Write ─────────────────────────────────────────────────────────

    /**
     * Save or update a token for a specific tenant.
     * Other tenants' tokens are preserved.
     */
    public static function save(
        string $userId,
        string $tenantId,
        string $tenantName,
        string $accessToken,
        string $refreshToken,
        int    $expiresAt
    ): void {
        $all = self::getAll($userId);

        $all[$tenantId] = [
            'tenant_id'     => $tenantId,
            'tenant_name'   => $tenantName,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at'    => $expiresAt,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        file_put_contents(
            self::tokenPath($userId),
            json_encode($all, JSON_PRETTY_PRINT)
        );

        // Ensure org shared folder exists
        OrgStorage::scaffold($tenantId);
    }

    /**
     * Remove a specific tenant's token for a user.
     */
    public static function remove(string $userId, string $tenantId): void
    {
        $all = self::getAll($userId);
        unset($all[$tenantId]);
        file_put_contents(
            self::tokenPath($userId),
            json_encode($all, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Remove ALL tokens for a user (full logout / account delete).
     */
    public static function clearAll(string $userId): void
    {
        $path = self::tokenPath($userId);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // ── Expiry ────────────────────────────────────────────────────────

    public static function isExpired(array $token): bool
    {
        // Treat as expired 60 seconds early to avoid edge cases
        return time() >= ($token['expires_at'] - 60);
    }
}
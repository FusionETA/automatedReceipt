<?php

declare(strict_types=1);

/**
 * cron/refresh_tokens.php
 *
 * Proactively refreshes Xero access tokens for ALL users.
 * Scans storage/users/{xero_user_id}/tokens.json for each user.
 * Run every 20 minutes — tokens expire every 30 minutes.
 *
 * ── Setup ─────────────────────────────────────────────────────────────
 * Cron: every 20 minutes
 * Command:
 * php /var/www/html/cron/refresh_tokens.php >> /var/www/html/storage/logs/cron.log 2>&1
 *
 * ── Test manually ─────────────────────────────────────────────────────
 * php cron/refresh_tokens.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Bootstrap;
use App\Xero\UserTokenStorage;
use App\Xero\OAuthClient;
use App\Helpers\Logger;

Bootstrap::init();

$startedAt = date('Y-m-d H:i:s');
$usersDir  = __DIR__ . '/../storage/users';

// ── Scan all user folders ─────────────────────────────────────────────
if (!is_dir($usersDir)) {
    echo "[{$startedAt}] No users directory found — nothing to refresh.\n";
    Logger::info('cron', 'No users directory found — nothing to refresh.');
    exit(0);
}

$userIds = array_filter(
    scandir($usersDir),
    fn($f) => $f !== '.' && $f !== '..' && is_dir($usersDir . '/' . $f)
);

if (empty($userIds)) {
    echo "[{$startedAt}] No users found — nothing to refresh.\n";
    Logger::info('cron', 'No users found — nothing to refresh.');
    exit(0);
}

// ── Count total orgs across all users ────────────────────────────────
$totalOrgs = 0;
foreach ($userIds as $userId) {
    $totalOrgs += count(UserTokenStorage::getAll($userId));
}

echo "[{$startedAt}] Checking tokens for {$totalOrgs} org(s) across " . count($userIds) . " user(s)...\n";
Logger::info('cron', "Token refresh started — {$totalOrgs} org(s) across " . count($userIds) . " user(s)");

$oauth     = new OAuthClient();
$refreshed = 0;
$skipped   = 0;
$failed    = 0;

// ── Loop each user → each org ─────────────────────────────────────────
foreach ($userIds as $userId) {
    $allTokens = UserTokenStorage::getAll($userId);

    if (empty($allTokens)) continue;

    foreach ($allTokens as $tenantId => $token) {
        $name      = $token['tenant_name'] ?? $tenantId;
        $expiresAt = $token['expires_at']  ?? 0;
        $expiresIn = $expiresAt - time();

        // Skip if valid for more than 10 minutes
        if ($expiresIn > 600) {
            $skipped++;
            echo "  ⏭  {$name} (user: ...{$userId}) — valid for " . gmdate('i\m s\s', $expiresIn) . ", skipping.\n";
            continue;
        }

        echo "  🔄  {$name} (user: ...{$userId}) — expires in {$expiresIn}s, refreshing...\n";

        try {
            $refreshedTokens = $oauth->refreshToken($token['refresh_token']);

            UserTokenStorage::save(
                $userId,
                $tenantId,
                $token['tenant_name'] ?? '',
                $refreshedTokens['access_token'],
                $refreshedTokens['refresh_token'],
                time() + (int) $refreshedTokens['expires_in']
            );

            $refreshed++;
            $newExpiry = date('H:i:s', time() + (int) $refreshedTokens['expires_in']);
            echo "  ✅  {$name} — refreshed. New expiry: {$newExpiry}\n";
            Logger::info('cron', "Token refreshed for {$name} / user {$userId}");

        } catch (\Throwable $e) {
            $failed++;
            echo "  ❌  {$name} — FAILED: " . $e->getMessage() . "\n";
            Logger::error('cron', "Token refresh failed for {$name} / user {$userId}: " . $e->getMessage());
        }
    }
}

$summary = "Done — {$refreshed} refreshed, {$skipped} skipped, {$failed} failed.";
echo "\n[" . date('Y-m-d H:i:s') . "] {$summary}\n";
Logger::info('cron', $summary);

exit($failed > 0 ? 1 : 0);
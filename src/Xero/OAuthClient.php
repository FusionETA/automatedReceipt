<?php

declare(strict_types=1);

namespace App\Xero;

use GuzzleHttp\Client;
use App\Auth\Auth;
use App\Helpers\Logger;
use App\Config\Bootstrap;

/**
 * OAuthClient — Xero OAuth2 + identity.
 *
 * Key changes from original:
 * - Requests openid + profile + email scopes (Xero identity)
 * - getValidAccessToken() is now per-user, not global
 * - decodeIdentity() extracts Xero user info from the id_token JWT
 */
class OAuthClient
{
    private const AUTH_URL    = 'https://login.xero.com/identity/connect/authorize';
    private const TOKEN_URL   = 'https://identity.xero.com/connect/token';
    private const CONNECTIONS = 'https://api.xero.com/connections';

    private Client $http;

    public function __construct()
    {
        $this->http = new Client(['timeout' => 30]);
    }

    // ── Step 1 — Redirect user to Xero login ─────────────────────────

    public function getAuthorizationUrl(): string
    {
        // Include openid + profile + email to get Xero user identity
        $baseScopes     = Bootstrap::env('XERO_SCOPES', 'accounting.transactions accounting.contacts');
        $identityScopes = 'openid profile email';
        $allScopes      = $identityScopes . ' ' . $baseScopes;

        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => Bootstrap::env('XERO_CLIENT_ID'),
            'redirect_uri'  => Bootstrap::env('XERO_REDIRECT_URI'),
            'scope'         => $allScopes,
            'state'         => $this->generateState(),
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    // ── Step 2 — Exchange code for tokens ────────────────────────────

    public function exchangeCode(string $code): array
    {
        $response = $this->http->post(self::TOKEN_URL, [
            'auth' => [
                Bootstrap::env('XERO_CLIENT_ID'),
                Bootstrap::env('XERO_CLIENT_SECRET'),
            ],
            'form_params' => [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => Bootstrap::env('XERO_REDIRECT_URI'),
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    // ── Step 3 — Get connected orgs ──────────────────────────────────

    public function getTenants(string $accessToken): array
    {
        $response = $this->http->get(self::CONNECTIONS, [
            'headers' => ['Authorization' => "Bearer {$accessToken}"],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    // ── Identity — decode Xero user info from id_token ───────────────

    /**
     * Extract user identity from the id_token JWT returned by Xero.
     * We only need the payload (middle part) — no signature verification
     * needed here because the token came directly from Xero over TLS.
     *
     * Returns array with: sub (xero user id), email, name
     */
    public function decodeIdentity(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return ['sub' => '', 'email' => '', 'name' => ''];
        }

        // Base64url decode the payload
        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        $claims  = json_decode($payload, true) ?: [];

        return [
            'sub'   => $claims['sub']              ?? '',   // stable Xero user ID
            'email' => $claims['email']             ?? '',
            'name'  => $claims['preferred_username'] ?? $claims['email'] ?? '',
        ];
    }

    // ── Token refresh — per user ──────────────────────────────────────

    public function refreshToken(string $refreshToken): array
    {
        $response = $this->http->post(self::TOKEN_URL, [
            'auth' => [
                Bootstrap::env('XERO_CLIENT_ID'),
                Bootstrap::env('XERO_CLIENT_SECRET'),
            ],
            'form_params' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Get a valid access token for a specific user + tenant.
     * Refreshes automatically if expired.
     *
     * $userId   — Xero user ID (from session or explicit)
     * $tenantId — which org to get the token for
     */
    public function getValidAccessToken(string $userId = '', string $tenantId = ''): ?string
    {
        // Fall back to session values if not provided
        $userId   = $userId   ?: Auth::userId();
        $tenantId = $tenantId ?: Auth::activeTenantId();

        if (!$userId || !$tenantId) {
            Logger::warning('oauth', 'getValidAccessToken called without userId or tenantId');
            return null;
        }

        $token = UserTokenStorage::getForTenant($userId, $tenantId);

        if (!$token) {
            Logger::warning('oauth', "No token found for user {$userId} / tenant {$tenantId}");
            return null;
        }

        if (UserTokenStorage::isExpired($token)) {
            Logger::info('oauth', "Token expired for user {$userId} / tenant {$tenantId} — refreshing");
            try {
                $refreshed = $this->refreshToken($token['refresh_token']);

                UserTokenStorage::save(
                    $userId,
                    $tenantId,
                    $token['tenant_name'],
                    $refreshed['access_token'],
                    $refreshed['refresh_token'],
                    time() + (int) $refreshed['expires_in']
                );

                Logger::info('oauth', "Token refreshed for user {$userId} / tenant {$tenantId}");
                return $refreshed['access_token'];

            } catch (\Throwable $e) {
                Logger::error('oauth', "Token refresh failed for user {$userId}: " . $e->getMessage());
                return null;
            }
        }

        return $token['access_token'];
    }

    // ── CSRF state ────────────────────────────────────────────────────

    private function generateState(): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (!empty($_SESSION['xero_oauth_state'])) {
            return $_SESSION['xero_oauth_state'];
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['xero_oauth_state'] = $state;
        return $state;
    }

    public function verifyState(string $returnedState): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $stored = $_SESSION['xero_oauth_state'] ?? '';
        unset($_SESSION['xero_oauth_state']);
        return hash_equals($stored, $returnedState);
    }
}
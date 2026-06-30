<?php

declare(strict_types=1);

namespace App\Xero;

use App\Config\Bootstrap;
use App\Helpers\Logger;

/**
 * Verifies that incoming webhook requests genuinely come from Xero.
 *
 * Xero signs each webhook with HMAC-SHA256 using your webhook key.
 * The signature is in the X-Xero-Signature header.
 *
 * Docs: https://developer.xero.com/documentation/guides/webhooks/overview/
 */
class WebhookVerifier
{
    /**
     * Verify the webhook signature.
     *
     * @param string $rawBody   The raw POST body (do NOT json_decode first)
     * @param string $signature The value of X-Xero-Signature header
     */
    public static function verify(string $rawBody, string $signature): bool
    {
        $webhookKey = Bootstrap::env('XERO_WEBHOOK_KEY', '');

        if (empty($webhookKey)) {
            Logger::error('webhook', 'XERO_WEBHOOK_KEY is not set in .env');
            return false;
        }

        // HMAC-SHA256 of the raw body using the webhook signing key
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $webhookKey, true));

        // Constant-time comparison to prevent timing attacks
        $valid = hash_equals($expected, $signature);

        if (!$valid) {
            Logger::warning('webhook', 'Signature mismatch — possible spoofed request.', [
                'expected' => substr($expected, 0, 10) . '...',
                'received' => substr($signature, 0, 10) . '...',
            ]);
        }

        return $valid;
    }
}
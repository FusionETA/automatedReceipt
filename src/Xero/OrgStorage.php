<?php

declare(strict_types=1);

namespace App\Xero;

/**
 * OrgStorage — shared per-org data (receipts, webhook events).
 *
 * Org data is NOT user-specific. All users in the same Xero org
 * see the same receipts and events.
 *
 * Structure:
 *   storage/orgs/{tenant_id}/
 *     receipts/                  ← PDF files
 *     webhook_events.json        ← event log
     *     org_profile.json           ← cached Xero org profile
     *     bank_accounts.json         ← cached list of BANK accounts from Xero
     *     chart_accounts.json        ← cached list of chart accounts from Xero
     *     selected_accounts.json     ← account IDs the user has opted-in for auto-receipt
     *     receipt_settings.json      ← selected trigger method for auto-receipt
 */
class OrgStorage
{
    private static function baseDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/orgs';
    }

    public static function orgDir(string $tenantId): string
    {
        return self::baseDir() . '/' . $tenantId;
    }

    public static function receiptsDir(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/receipts';
    }

    private static function eventsPath(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/webhook_events.json';
    }

    private static function bankAccountsPath(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/bank_accounts.json';
    }

    private static function chartAccountsPath(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/chart_accounts.json';
    }

    private static function selectedAccountsPath(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/selected_accounts.json';
    }

    private static function receiptSettingsPath(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/receipt_settings.json';
    }

    private static function loadReceiptSettings(string $tenantId): array
    {
        $path = self::receiptSettingsPath($tenantId);
        if (!file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    private static function saveReceiptSettings(string $tenantId, array $settings): void
    {
        self::scaffold($tenantId);
        $settings['_updated_at'] = date('Y-m-d H:i:s');

        file_put_contents(
            self::receiptSettingsPath($tenantId),
            json_encode($settings, JSON_PRETTY_PRINT)
        );
    }

    private static function receiptLogPath(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/receipt_log.json';
    }

    // ── Setup ─────────────────────────────────────────────────────────

    /**
     * Create folder structure for an org if it doesn't exist.
     * Called automatically when a token is saved.
     */
    public static function scaffold(string $tenantId): void
    {
        $dirs = [
            self::orgDir($tenantId),
            self::receiptsDir($tenantId),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    // ── Org profile cache ─────────────────────────────────────────────

    private static function orgProfilePath(string $tenantId): string
    {
        return self::orgDir($tenantId) . '/org_profile.json';
    }

    /**
     * Save fetched Xero org profile to disk.
     * Includes a timestamp so callers can decide to re-fetch after N hours.
     */
    public static function saveOrgProfile(string $tenantId, array $profile): void
    {
        self::scaffold($tenantId);
        $profile['_cached_at'] = time();
        file_put_contents(
            self::orgProfilePath($tenantId),
            json_encode($profile, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Load cached org profile. Returns null if not cached or older than $maxAgeSeconds.
     * Default TTL: 6 hours — org details rarely change.
     */
    public static function getOrgProfile(string $tenantId, int $maxAgeSeconds = 21600): ?array
    {
        $path = self::orgProfilePath($tenantId);
        if (!file_exists($path)) return null;

        $data = json_decode(file_get_contents($path), true);
        if (!$data) return null;

        $cachedAt = $data['_cached_at'] ?? 0;
        if ((time() - $cachedAt) > $maxAgeSeconds) return null; // stale

        return $data;
    }

    // ── Bank accounts cache ───────────────────────────────────────────

    /**
     * Save the full list of BANK-type accounts fetched from Xero.
     * Each account: ['account_id', 'name', 'code', 'type', 'currency_code']
     *
     * TTL: 24 hours — bank accounts change very infrequently.
     */
    public static function saveBankAccounts(string $tenantId, array $accounts): void
    {
        self::scaffold($tenantId);
        $data = [
            '_cached_at' => time(),
            'accounts'   => $accounts,
        ];
        file_put_contents(
            self::bankAccountsPath($tenantId),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Load cached bank accounts list. Returns null if not cached or stale.
     * Default TTL: 24 hours.
     */
    public static function getBankAccounts(string $tenantId, int $maxAgeSeconds = 86400): ?array
    {
        $path = self::bankAccountsPath($tenantId);
        if (!file_exists($path)) return null;

        $data = json_decode(file_get_contents($path), true);
        if (!$data) return null;

        $cachedAt = $data['_cached_at'] ?? 0;
        if ((time() - $cachedAt) > $maxAgeSeconds) return null; // stale

        return $data['accounts'] ?? [];
    }

    /**
     * Force-invalidate the bank accounts cache so it is re-fetched next time.
     */
    public static function clearBankAccountsCache(string $tenantId): void
    {
        $path = self::bankAccountsPath($tenantId);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // ── Chart accounts cache ──────────────────────────────────────────

    public static function saveChartAccounts(string $tenantId, array $accounts): void
    {
        self::scaffold($tenantId);
        $data = [
            '_cached_at' => time(),
            'accounts'   => $accounts,
        ];

        file_put_contents(
            self::chartAccountsPath($tenantId),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    public static function getChartAccounts(string $tenantId, int $maxAgeSeconds = 86400): ?array
    {
        $path = self::chartAccountsPath($tenantId);
        if (!file_exists($path)) return null;

        $data = json_decode(file_get_contents($path), true);
        if (!$data) return null;

        $cachedAt = $data['_cached_at'] ?? 0;
        if ((time() - $cachedAt) > $maxAgeSeconds) return null;

        return $data['accounts'] ?? [];
    }

    public static function clearChartAccountsCache(string $tenantId): void
    {
        $path = self::chartAccountsPath($tenantId);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // ── Selected accounts (opt-in for auto-receipt) ───────────────────

    /**
     * Persist the set of account IDs the user has chosen to trigger auto-receipts.
     *
     * @param string[] $accountIds
     */
    public static function saveSelectedAccounts(string $tenantId, array $accountIds): void
    {
        self::scaffold($tenantId);
        $data = [
            '_updated_at' => date('Y-m-d H:i:s'),
            'account_ids' => array_values(array_unique($accountIds)),
        ];
        file_put_contents(
            self::selectedAccountsPath($tenantId),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Load the opted-in account IDs. Returns empty array if none saved yet.
     *
     * @return string[]
     */
    public static function getSelectedAccounts(string $tenantId): array
    {
        $path = self::selectedAccountsPath($tenantId);
        if (!file_exists($path)) return [];

        $data = json_decode(file_get_contents($path), true);
        return $data['account_ids'] ?? [];
    }

    /**
     * Whether the user has made a selection (even an empty one counts as "decided").
     * Distinguishes "never configured" from "deliberately deselected everything".
     */
    public static function hasConfiguredAccounts(string $tenantId): bool
    {
        return file_exists(self::selectedAccountsPath($tenantId));
    }

    /**
     * Check whether a specific account ID is in the opted-in list.
     */
    public static function isAccountSelected(string $tenantId, string $accountId): bool
    {
        return in_array($accountId, self::getSelectedAccounts($tenantId), true);
    }

    // ── Receipt trigger settings ─────────────────────────────────────

    public static function saveReceiptTriggerMethod(string $tenantId, string $method): void
    {
        $allowed = ['bank_account', 'account_code'];
        $method = in_array($method, $allowed, true) ? $method : '';
        $settings = self::loadReceiptSettings($tenantId);
        $settings['trigger_method'] = $method;
        self::saveReceiptSettings($tenantId, $settings);
    }

    public static function getReceiptTriggerMethod(string $tenantId): string
    {
        $data = self::loadReceiptSettings($tenantId);
        $method = $data['trigger_method'] ?? '';

        return in_array($method, ['bank_account', 'account_code'], true) ? $method : '';
    }

    public static function saveSelectedChartAccountCodes(string $tenantId, array $codes): void
    {
        $settings = self::loadReceiptSettings($tenantId);
        $settings['selected_chart_account_codes'] = array_values(array_unique(array_filter(array_map('trim', $codes))));
        self::saveReceiptSettings($tenantId, $settings);
    }

    public static function getSelectedChartAccountCodes(string $tenantId): array
    {
        $settings = self::loadReceiptSettings($tenantId);
        return $settings['selected_chart_account_codes'] ?? [];
    }

    public static function saveChartAccountTemplates(string $tenantId, array $templates): void
    {
        $normalized = [];

        foreach ($templates as $code => $template) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }

            $normalized[$code] = [
                'name' => trim((string) ($template['name'] ?? '')),
                'sender_name' => trim((string) ($template['sender_name'] ?? '')),
                'subject' => trim((string) ($template['subject'] ?? '')),
                'body' => trim((string) ($template['body'] ?? '')),
            ];
        }

        $settings = self::loadReceiptSettings($tenantId);
        $settings['chart_account_templates'] = $normalized;
        self::saveReceiptSettings($tenantId, $settings);
    }

    public static function getChartAccountTemplates(string $tenantId): array
    {
        $settings = self::loadReceiptSettings($tenantId);
        return $settings['chart_account_templates'] ?? [];
    }

    public static function saveTemplateLibrary(string $tenantId, array $templates): void
    {
        $normalized = [];

        foreach ($templates as $templateId => $template) {
            $templateId = trim((string) $templateId);
            if ($templateId === '') {
                continue;
            }

            $normalized[$templateId] = [
                'id' => $templateId,
                'name' => trim((string) ($template['name'] ?? '')),
                'sender_name' => trim((string) ($template['sender_name'] ?? '')),
                'subject' => trim((string) ($template['subject'] ?? '')),
                'body' => trim((string) ($template['body'] ?? '')),
            ];
        }

        $settings = self::loadReceiptSettings($tenantId);
        $settings['template_library'] = $normalized;
        unset($settings['chart_account_templates']);
        self::saveReceiptSettings($tenantId, $settings);
    }

    public static function getTemplateLibrary(string $tenantId): array
    {
        $settings = self::loadReceiptSettings($tenantId);

        if (array_key_exists('template_library', $settings) && is_array($settings['template_library'])) {
            return $settings['template_library'];
        }

        $legacyTemplates = $settings['chart_account_templates'] ?? [];
        if (!is_array($legacyTemplates) || $legacyTemplates === []) {
            return [];
        }

        $library = [];
        $assignments = [];

        foreach ($legacyTemplates as $code => $template) {
            $templateId = 'legacy-' . trim((string) $code);
            $library[$templateId] = [
                'id' => $templateId,
                'name' => trim((string) ($template['name'] ?? '')),
                'sender_name' => trim((string) ($template['sender_name'] ?? '')),
                'subject' => trim((string) ($template['subject'] ?? '')),
                'body' => trim((string) ($template['body'] ?? '')),
            ];
            $assignments[(string) $code] = $templateId;
        }

        $settings['template_library'] = $library;
        $settings['chart_account_template_assignments'] = $assignments;
        self::saveReceiptSettings($tenantId, $settings);

        return $library;
    }

    public static function saveChartAccountTemplateAssignments(string $tenantId, array $assignments): void
    {
        $normalized = [];

        foreach ($assignments as $code => $templateId) {
            $code = trim((string) $code);
            $templateId = trim((string) $templateId);
            if ($code === '') {
                continue;
            }

            $normalized[$code] = $templateId;
        }

        $settings = self::loadReceiptSettings($tenantId);
        $settings['chart_account_template_assignments'] = $normalized;
        self::saveReceiptSettings($tenantId, $settings);
    }

    public static function getChartAccountTemplateAssignments(string $tenantId): array
    {
        $settings = self::loadReceiptSettings($tenantId);

        if (array_key_exists('chart_account_template_assignments', $settings) && is_array($settings['chart_account_template_assignments'])) {
            return $settings['chart_account_template_assignments'];
        }

        $legacyTemplates = $settings['chart_account_templates'] ?? [];
        if (!is_array($legacyTemplates) || $legacyTemplates === []) {
            return [];
        }

        $assignments = [];
        foreach ($legacyTemplates as $code => $_template) {
            $assignments[(string) $code] = 'legacy-' . trim((string) $code);
        }

        $settings['chart_account_template_assignments'] = $assignments;
        self::saveReceiptSettings($tenantId, $settings);

        return $assignments;
    }

    public static function getChartAccountTemplate(string $tenantId, string $code): ?array
    {
        $assignments = self::getChartAccountTemplateAssignments($tenantId);
        $templateId = trim((string) ($assignments[$code] ?? ''));
        if ($templateId === '') {
            return null;
        }

        $templates = self::getTemplateLibrary($tenantId);
        return $templates[$templateId] ?? null;
    }

    public static function isReceiptTriggerConfigured(string $tenantId): bool
    {
        $method = self::getReceiptTriggerMethod($tenantId);

        if ($method === 'account_code') {
            return count(self::getSelectedChartAccountCodes($tenantId)) > 0;
        }

        if ($method === 'bank_account') {
            return count(self::getSelectedAccounts($tenantId)) > 0;
        }

        return false;
    }

    // ── Receipt send log ──────────────────────────────────────────────

    /**
     * Record the outcome of a receipt send attempt for a given invoice.
     *
     * Status values: 'sent' | 'failed' | 'skipped_no_email' | 'coa_mismatch' | 'coa_no_template'
     *
     * @param string $invoiceNumber   e.g. "INV-0042"
     * @param string $status          'sent' | 'failed' | 'skipped_no_email' | 'coa_mismatch' | 'coa_no_template'
     * @param string $recipientEmail  The address we tried to send to (empty if no email)
     * @param string $errorMessage    Non-empty when the status needs dashboard detail
     */
    public static function saveReceiptLog(
        string $tenantId,
        string $invoiceNumber,
        string $status,
        string $recipientEmail = '',
        string $errorMessage   = ''
    ): void {
        self::scaffold($tenantId);
        $path = self::receiptLogPath($tenantId);
        $log  = [];

        if (file_exists($path)) {
            $log = json_decode(file_get_contents($path), true) ?: [];
        }

        $log[$invoiceNumber] = [
            'status'    => $status,           // sent | failed | skipped_no_email | coa_mismatch | coa_no_template
            'email'     => $recipientEmail,
            'error'     => $errorMessage,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT));
    }

    /**
     * Get the full receipt log for an org (keyed by invoice number).
     *
     * @return array<string, array{status:string, email:string, error:string, timestamp:string}>
     */
    public static function getReceiptLog(string $tenantId): array
    {
        $path = self::receiptLogPath($tenantId);
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?: [];
    }

    /**
     * Get the send status for a single invoice number, or null if unknown.
     *
     * @return array{status:string, email:string, error:string, timestamp:string}|null
     */
    public static function getReceiptStatus(string $tenantId, string $invoiceNumber): ?array
    {
        $log = self::getReceiptLog($tenantId);
        return $log[$invoiceNumber] ?? null;
    }

    // ── Webhook events ────────────────────────────────────────────────

    /**
     * Append a webhook event. Keeps most recent 200.
     */
    public static function appendEvent(string $tenantId, array $event): void
    {
        self::scaffold($tenantId);

        $path   = self::eventsPath($tenantId);
        $events = [];

        if (file_exists($path)) {
            $events = json_decode(file_get_contents($path), true) ?: [];
        }

        array_unshift($events, $event);
        $events = array_slice($events, 0, 200);

        file_put_contents($path, json_encode($events, JSON_PRETTY_PRINT));
    }

    /**
     * Get all webhook events for an org (newest first).
     */
    public static function getEvents(string $tenantId): array
    {
        $path = self::eventsPath($tenantId);
        if (!file_exists($path)) return [];
        return json_decode(file_get_contents($path), true) ?: [];
    }

    // ── Receipts ──────────────────────────────────────────────────────

    /**
     * Find an existing receipt PDF for an invoice.
     * Returns the full path or null if not found.
     */
    public static function findReceipt(string $tenantId, string $invoiceNumber): ?string
    {
        $dir = self::receiptsDir($tenantId);
        if (!is_dir($dir)) return null;
    
        $safe    = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $invoiceNumber);
        $pattern = $dir . '/receipt_' . $safe . '_*.pdf';
        $files   = glob($pattern);
    
        if (empty($files)) {
            return null;
        }
    
        // Keep only real, non-empty files
        $files = array_filter($files, static function ($file) {
            return is_file($file) && filesize($file) > 1000;
        });
    
        if (empty($files)) {
            return null;
        }
    
        // Return newest file
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
    
        return $files[0];
    }

    // ── Cleanup ───────────────────────────────────────────────────────

    /**
     * Delete all org data (use when ALL users disconnect from this org).
     */
    public static function destroy(string $tenantId): void
    {
        $dir = self::orgDir($tenantId);
        if (is_dir($dir)) {
            self::deleteDir($dir);
        }
    }

    private static function deleteDir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? self::deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
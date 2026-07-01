<?php

declare(strict_types=1);

/**
 * cron/process-queue.php
 *
 * Processes queued Xero webhook payloads.
 * Run every minute via cPanel cron:
 *   * * * * * php /home/your-user/xero-receipt-app/cron/process-queue.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Xero\XeroApiClient;
use App\Xero\UserTokenStorage;
use App\Xero\OrgStorage;
use App\Email\EmailSender;
use App\PDF\ReceiptGenerator;
use App\Helpers\Logger;

Bootstrap::init();

$queueDir = dirname(__DIR__) . '/storage/webhook_queue';

if (!is_dir($queueDir)) {
    exit(0); // Nothing to process
}

// ── Lock file: prevent overlapping cron runs ──────────────────────────
$lockFile = $queueDir . '/.lock';
$lock     = fopen($lockFile, 'c');

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    Logger::info('cron', 'Another process is already running — skipping.');
    exit(0);
}

// ── Pick up all queued files ──────────────────────────────────────────
$files = glob($queueDir . '/*.json');

if (empty($files)) {
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

Logger::info('cron', 'Processing ' . count($files) . ' queued webhook file(s).');

foreach ($files as $file) {
    $rawBody = file_get_contents($file);
    $payload = json_decode($rawBody, true);

    if (empty($payload['events'])) {
        unlink($file);
        continue;
    }

    $processed = true;

    foreach ($payload['events'] as $event) {
        $category   = strtoupper($event['eventCategory'] ?? '');
        $eventType  = $event['eventType'] ?? '';
        $resourceId = $event['resourceId'] ?? '';
        $tenantId   = $event['tenantId']   ?? '';

        Logger::info('cron', "Event: {$category}/{$eventType}", [
            'resource_id' => $resourceId,
            'tenant_id'   => $tenantId,
        ]);

        if ($category !== 'INVOICE' || empty($resourceId) || empty($tenantId)) {
            continue;
        }

        // ── Find a user who has a token for this org ──────────────────
        $userId = UserTokenStorage::findUserIdForOrg($tenantId);

        if (!$userId) {
            Logger::warning('cron', "No user found with token for tenant {$tenantId} — skipping.");
            continue;
        }

        // ── Fetch full invoice ────────────────────────────────────────
        $xeroApi = new XeroApiClient($userId, $tenantId);
        $invoice = $xeroApi->getInvoice($resourceId);

        if (!$invoice) {
            Logger::warning('cron', "Could not fetch invoice {$resourceId} — will retry later.");
            $processed = false;
            break;
        }
        // ── Only process fully paid invoices ──────────────────────────
        $fullyPaidRaw = $invoice['FullyPaidOnDate'] ?? null;

        if (!$fullyPaidRaw) {
            Logger::info('cron', "Invoice {$resourceId} has no FullyPaidOnDate — ignoring.");
            continue;
        }

        // Parse paid date in local timezone
        $localTz       = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'UTC');
        $fullyPaidDate = null;

        if (preg_match('/\/Date\((\d+)/', $fullyPaidRaw, $m)) {
            $dt = (new \DateTime('now', new \DateTimeZone('UTC')))
                ->setTimestamp((int)($m[1] / 1000))
                ->setTimezone($localTz);
            $fullyPaidDate = $dt->format('Y-m-d');
        } elseif (strtotime($fullyPaidRaw)) {
            $dt = (new \DateTime($fullyPaidRaw, new \DateTimeZone('UTC')))->setTimezone($localTz);
            $fullyPaidDate = $dt->format('Y-m-d');
        }

        if (!$fullyPaidDate) {
            Logger::warning('cron', "Could not parse FullyPaidOnDate for {$resourceId} — skipping.");
            continue;
        }

        $today = (new \DateTime('now', $localTz))->format('Y-m-d');

        if ($fullyPaidDate !== $today) {
            Logger::info('cron', "Invoice {$resourceId} paid on {$fullyPaidDate}, today is {$today} — skipping.");
            continue;
        }

        $receiptRule = null;
        $triggerMethod = OrgStorage::getReceiptTriggerMethod($tenantId);

        if ($triggerMethod === '') {
            Logger::info('cron', "No receipt trigger method configured for {$tenantId} — skipping.");
            continue;
        }

        if ($triggerMethod === 'bank_account') {
            Logger::info('cron', "✅ Invoice {$resourceId} fully paid today — checking bank account filter.");

            $selectedAccounts = OrgStorage::getSelectedAccounts($tenantId);

            if (empty($selectedAccounts)) {
                Logger::info('cron', "Bank account trigger selected but no bank accounts are ticked for {$tenantId} — skipping.");
                continue;
            }

            $payments  = $invoice['Payments'] ?? [];
            $paymentId = $payments[0]['PaymentID'] ?? null;

            if (!$paymentId) {
                Logger::info('cron', "Invoice {$resourceId} has no PaymentID — skipping (bank account trigger active).");
                continue;
            }

            $payment = $xeroApi->getPayment($paymentId);

            if (!$payment) {
                Logger::warning('cron', "Could not fetch payment {$paymentId} — will retry later.");
                $processed = false;
                break;
            }

            $paidIntoAccountId = $payment['Account']['AccountID'] ?? null;

            if (!$paidIntoAccountId) {
                Logger::warning('cron', "Could not determine account for payment {$paymentId} — will retry later.");
                $processed = false;
                break;
            }

            if (!in_array($paidIntoAccountId, $selectedAccounts, true)) {
                Logger::info('cron', "Invoice {$resourceId} paid into {$paidIntoAccountId} — not in selected list, skipping.");
                continue;
            }

            Logger::info('cron', "✅ Bank account {$paidIntoAccountId} is selected — proceeding.");
        } elseif ($triggerMethod === 'account_code') {
            Logger::info('cron', "✅ Invoice {$resourceId} fully paid today — checking chart account code.");

            $selectedChartCodes = OrgStorage::getSelectedChartAccountCodes($tenantId);

            if (empty($selectedChartCodes)) {
                Logger::info('cron', "Chart account code trigger selected but no chart account codes are configured for {$tenantId} — skipping.");
                continue;
            }

            $normalizeAccountCode = static function (string $code): string {
                return strtoupper(trim($code));
            };

            $selectedChartCodeMap = [];
            foreach ($selectedChartCodes as $selectedCode) {
                $selectedCode = trim((string) $selectedCode);
                if ($selectedCode === '') {
                    continue;
                }

                $normalizedSelectedCode = $normalizeAccountCode($selectedCode);
                if (!isset($selectedChartCodeMap[$normalizedSelectedCode])) {
                    $selectedChartCodeMap[$normalizedSelectedCode] = $selectedCode;
                }
            }

            $lineItemsSnapshot = array_map(static function (array $lineItem): array {
                $rawAccountCode = array_key_exists('AccountCode', $lineItem)
                    ? (string) $lineItem['AccountCode']
                    : null;

                return [
                    'line_item_id' => (string) ($lineItem['LineItemID'] ?? ''),
                    'description' => (string) ($lineItem['Description'] ?? ''),
                    'account_code' => $rawAccountCode,
                    'normalized_account_code' => $rawAccountCode !== null
                        ? strtoupper(trim($rawAccountCode))
                        : null,
                    'item_code' => (string) ($lineItem['ItemCode'] ?? ''),
                    'line_amount' => $lineItem['LineAmount'] ?? null,
                ];
            }, (array) ($invoice['LineItems'] ?? []));

            Logger::info('cron', "Invoice {$resourceId} account-code debug snapshot.", [
                'invoice_number' => $invoice['InvoiceNumber'] ?? $resourceId,
                'selected_codes' => $selectedChartCodes,
                'normalized_selected_codes' => array_keys($selectedChartCodeMap),
                'line_items' => $lineItemsSnapshot,
            ]);

            $lineAccountCodes = [];
            $lineAccountCodeMap = [];
            $matchedChartCodes = [];

            foreach (($invoice['LineItems'] ?? []) as $lineItem) {
                $lineCode = trim((string) ($lineItem['AccountCode'] ?? ''));

                if ($lineCode === '') {
                    continue;
                }

                $normalizedLineCode = $normalizeAccountCode($lineCode);

                if (!isset($lineAccountCodeMap[$normalizedLineCode])) {
                    $lineAccountCodeMap[$normalizedLineCode] = $lineCode;
                    $lineAccountCodes[] = $lineCode;
                }

                if (isset($selectedChartCodeMap[$normalizedLineCode])) {
                    $matchedSelectedCode = $selectedChartCodeMap[$normalizedLineCode];

                    if (!in_array($matchedSelectedCode, $matchedChartCodes, true)) {
                        $matchedChartCodes[] = $matchedSelectedCode;
                    }
                }
            }

            if (!empty($matchedChartCodes)) {
                // Collect the template assigned to each matched COA
                $matchedTemplates = [];
                foreach ($matchedChartCodes as $matchedCode) {
                    $tpl = OrgStorage::getChartAccountTemplate($tenantId, $matchedCode);
                    $tplId = $tpl['id'] ?? null;
                    $matchedTemplates[$matchedCode] = ['template' => $tpl, 'template_id' => $tplId];
                }

                $uniqueTemplateIds = array_unique(array_column(array_values($matchedTemplates), 'template_id'));

                if (count($uniqueTemplateIds) > 1) {
                    // Multiple matched COAs with different templates — ambiguous, skip
                    $invoiceNumber = $invoice['InvoiceNumber'] ?? $resourceId;
                    $errorMessage = 'Multiple selected COAs matched (' . implode(', ', $matchedChartCodes)
                        . ') with different templates assigned. Cannot determine which template to use.';

                    Logger::warning('cron', "Invoice {$resourceId} matched multiple COAs with different templates — skipping receipt.", [
                        'matched_codes' => $matchedChartCodes,
                    ]);

                    OrgStorage::saveReceiptLog(
                        $tenantId,
                        $invoiceNumber,
                        'coa_mismatch',
                        '',
                        $errorMessage
                    );

                    continue;
                }

                // All matched COAs share the same template (or only one matched) — proceed
                $matchedCode = array_key_first($matchedTemplates);
                $receiptRule = [
                    'account_code' => $matchedCode,
                    'template'     => $matchedTemplates[$matchedCode]['template'],
                ];
            }

            if (!$receiptRule) {
                Logger::info('cron', "Invoice {$resourceId} has no selected chart account code — skipping.", [
                    'selected_codes' => $selectedChartCodes,
                ]);
                continue;
            }

            if ($receiptRule['template'] === null) {
                $invoiceNumber = $invoice['InvoiceNumber'] ?? $resourceId;
                $errorMessage = "No template chosen for account code {$receiptRule['account_code']}. Assign a template to this account before receipts can be sent.";

                Logger::warning('cron', "Invoice {$resourceId} matched account code {$receiptRule['account_code']} but no template is assigned — skipping receipt.");

                OrgStorage::saveReceiptLog(
                    $tenantId,
                    $invoiceNumber,
                    'coa_no_template',
                    '',
                    $errorMessage
                );

                continue;
            }

            Logger::info('cron', "✅ Account code {$receiptRule['account_code']} matched — proceeding.", [
                'template_name' => $receiptRule['template']['name'] ?? '',
            ]);
        }

        // ── Deduplicate ───────────────────────────────────────────────
        $invoiceNumber = $invoice['InvoiceNumber'] ?? '';

        if (OrgStorage::findReceipt($tenantId, $invoiceNumber)) {
            Logger::info('cron', "Receipt already exists for {$invoiceNumber} — skipping duplicate.");
            continue;
        }

        Logger::info('cron', "Generating receipt for {$invoiceNumber}.");

        // ── Build receipt data ────────────────────────────────────────
        $receiptData  = $xeroApi->extractReceiptData($invoice);
        if ($receiptRule) {
            $receiptData['receipt_account_code'] = $receiptRule['account_code'];
            $receiptData['receipt_template_name'] = $receiptRule['template']['name'] ?? '';
            $receiptData['receipt_template_sender_name'] = $receiptRule['template']['sender_name'] ?? '';
            $receiptData['receipt_template_subject'] = $receiptRule['template']['subject'] ?? '';
            $receiptData['receipt_template_body'] = $receiptRule['template']['body'] ?? '';
        }
        $contactEmail = $invoice['Contact']['EmailAddress'] ?? '';

        $orgProfile = OrgStorage::getOrgProfile($tenantId);
        if (
            !$orgProfile
            || empty($orgProfile['address'])
            || empty($orgProfile['email'])
            || empty($orgProfile['phone'])
        ) {
            $freshOrgProfile = $xeroApi->getOrganisation();
            if ($freshOrgProfile) {
                $orgProfile = array_merge($orgProfile ?? [], $freshOrgProfile);
                OrgStorage::saveOrgProfile($tenantId, $orgProfile);
            }
        }

        // ── Generate PDF ──────────────────────────────────────────────
        $pdfPath = null;
        try {
            $generator = new ReceiptGenerator($tenantId);
            $pdfPath   = $generator->generate($receiptData);
            if ($pdfPath) {
                Logger::info('pdf', "PDF generated: {$pdfPath}");
            }
        } catch (\Throwable $e) {
            Logger::error('pdf', 'PDF generation failed: ' . $e->getMessage());
            $processed = false; // Keep the queue file so it can be retried
        }

        // ── Build download URL ────────────────────────────────────────
        $appUrl     = Bootstrap::env('APP_URL', '');
        $receiptUrl = $appUrl
            ? rtrim($appUrl, '/') . '/download.php?id=' . urlencode($resourceId)
            : '';

        // ── Record event ──────────────────────────────────────────────
        OrgStorage::appendEvent($tenantId, [
            'time'            => date('Y-m-d H:i:s'),
            'category'        => $category,
            'event_type'      => $eventType,
            'resource_id'     => $resourceId,
            'invoice_number'  => $invoiceNumber,
            'contact'         => $invoice['Contact']['Name'] ?? '',
            'total'           => $invoice['Total'] ?? 0,
            'currency'        => $invoice['CurrencyCode'] ?? '',
            'fully_paid_date' => $fullyPaidDate,
            'tenant_id'       => $tenantId,
            'receipt_url'     => $receiptUrl,
        ]);

        // ── Send email ────────────────────────────────────────────────
        if (empty($contactEmail)) {
            Logger::warning('cron', "Invoice {$resourceId} has no contact email — skipping email.");
        
            OrgStorage::saveReceiptLog(
                $tenantId,
                $invoiceNumber,
                'skipped_no_email',
                '',
                'No email on contact'
            );
        
            continue;
        }

        $sent = (new EmailSender())->send($receiptData, (string)($pdfPath ?? ''), $receiptUrl, $tenantId);

        if ($sent === 'sent') {
            Logger::info('email', "✅ Receipt sent to {$contactEmail} for {$invoiceNumber}");
        
            OrgStorage::saveReceiptLog(
                $tenantId,
                $invoiceNumber,
                'sent',
                $contactEmail,
                ''
            );
        } else {
            Logger::error('email', "❌ Failed to send receipt to {$contactEmail} for {$invoiceNumber}");
        
            OrgStorage::saveReceiptLog(
                $tenantId,
                $invoiceNumber,
                $sent,
                $contactEmail,
                $sent === 'failed' ? 'Email send failed' : ''
            );
        
            $processed = false;
        }
    }

    // ── Remove processed file, or move to failed/ ─────────────────────
    if ($processed) {
        unlink($file);
    } else {
        $failedDir = $queueDir . '/failed';
        if (!is_dir($failedDir)) mkdir($failedDir, 0755, true);
        rename($file, $failedDir . '/' . basename($file));
        Logger::warning('cron', 'Moved to failed/: ' . basename($file));
    }
}

flock($lock, LOCK_UN);
fclose($lock);

Logger::info('cron', 'Queue processing complete.');
exit(0);
<?php

declare(strict_types=1);

namespace App\PDF;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Auth\Auth;
use App\Xero\OrgStorage;
use App\Config\Bootstrap;
use App\Helpers\Logger;

/**
 * ReceiptGenerator — generates PDF receipts.
 *
 * Saves PDFs to storage/orgs/{tenant_id}/receipts/
 * so all users in the same org share the same receipt files.
 */
class ReceiptGenerator
{
    private string $tenantId;

    public function __construct(string $tenantId = '')
    {
        $this->tenantId = $tenantId ?: Auth::activeTenantId();
    }

    public function generate(array $data): ?string
    {
        try {
            $html = $this->renderTemplate($data);
            
            file_put_contents(
                dirname(__DIR__, 2) . '/storage/logs/last-pdf-html.html',
                $html
            );

            $previousReporting = error_reporting();
            $renderNoise = '';

            ob_start();

            try {
                error_reporting($previousReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

                $options = new Options();
                $options->set('isHtml5ParserEnabled', true);
                $options->set('isRemoteEnabled', true);
                $options->set('defaultFont', 'Helvetica');

                $pdf = new Dompdf($options);
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('A4', 'portrait');
                $pdf->render();

                $renderNoise = trim((string) ob_get_clean());
            } catch (\Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                throw $e;
            } finally {
                error_reporting($previousReporting);
            }

            if ($renderNoise !== '') {
                Logger::warning('pdf', 'Suppressed unexpected PDF renderer output', [
                    'invoice' => $data['invoice_number'] ?? '',
                    'output' => substr(strip_tags($renderNoise), 0, 500),
                ]);
            }

            // Save to org's receipts folder
            $dir = OrgStorage::receiptsDir($this->tenantId);
            OrgStorage::scaffold($this->tenantId);

            $invoiceNum = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $data['invoice_number']);
            $filename   = "receipt_{$invoiceNum}_" . date('Ymd') . '.pdf';
            $path       = $dir . '/' . $filename;

            file_put_contents($path, $pdf->output());

            Logger::info('pdf', "Receipt PDF saved: {$filename}", [
                'invoice'   => $data['invoice_number'],
                'tenant_id' => $this->tenantId,
            ]);

            return $path;

        } catch (\Throwable $e) {
            Logger::error('pdf', 'PDF generation failed: ' . $e->getMessage(), [
                'invoice' => $data['invoice_number'] ?? '',
            ]);
            return null;
        }
    }

    private function renderTemplate(array $data): string
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/pdf/receipt.html';

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("PDF template not found at: {$templatePath}");
        }

        $html     = file_get_contents($templatePath);
        $currency = $data['currency_code'] ?? 'USD';

        // ── Org name + address from cache, .env as fallback ───────────
        $orgProfile   = OrgStorage::getOrgProfile($this->tenantId);
        $businessName = $orgProfile['name']    ?? Bootstrap::env('BUSINESS_NAME', 'Your Business');
        $businessAddr = $orgProfile['address'] ?? Bootstrap::env('BUSINESS_ADDRESS', '');
        $businessEmail = $orgProfile['email']  ?? Bootstrap::env('BUSINESS_EMAIL', '');
        $businessPhone = $orgProfile['phone']  ?? Bootstrap::env('BUSINESS_PHONE', '');

        $amountPaid = (float) ($data['amount_paid'] ?? 0);
        $invoiceTotal = (float) ($data['total'] ?? 0);
        $stillOwing = (float) ($data['amount_due'] ?? 0);

        $replacements = [
            '{{BUSINESS_NAME}}'    => htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8'),
            '{{BUSINESS_ADDRESS}}' => htmlspecialchars($businessAddr, ENT_QUOTES, 'UTF-8'),
            '{{BUSINESS_EMAIL}}'   => htmlspecialchars($businessEmail, ENT_QUOTES, 'UTF-8'),
            '{{BUSINESS_PHONE}}'   => htmlspecialchars($businessPhone, ENT_QUOTES, 'UTF-8'),
            '{{BUSINESS_WEBSITE}}' => htmlspecialchars(Bootstrap::env('BUSINESS_WEBSITE', ''), ENT_QUOTES, 'UTF-8'),
            '{{CUSTOMER_NAME}}'    => htmlspecialchars($data['contact_name']   ?? ''),
            '{{CUSTOMER_EMAIL}}'   => htmlspecialchars($data['contact_email']  ?? ''),
            '{{INVOICE_NUMBER}}'   => htmlspecialchars($data['invoice_number'] ?? ''),
            '{{INVOICE_DATE}}'     => $this->formatDisplayDate($data['invoice_date'] ?? null),
            '{{PAYMENT_DATE}}'     => $this->formatDisplayDate($data['payment_date'] ?? $data['invoice_date'] ?? date('Y-m-d')),
            '{{AMOUNT_PAID}}'      => $currency . ' ' . number_format((float) $data['amount_paid'], 2),
            '{{SUB_TOTAL}}'        => $currency . ' ' . number_format((float) $data['sub_total'],   2),
            '{{TOTAL_TAX}}'        => $currency . ' ' . number_format((float) $data['total_tax'],   2),
            '{{TOTAL}}'            => $currency . ' ' . number_format((float) $data['total'],       2),
            '{{CURRENCY}}'         => htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'),
            '{{LINE_ITEMS}}'       => $this->renderLineItems($data['line_items'] ?? [], $currency),
            '{{GENERATED_AT}}'     => date('Y-m-d H:i:s'),
            '{{RECEIPT_TITLE}}'    => 'Payment Receipt',
            '{{BUSINESS_BRAND_HTML}}' => $this->buildBusinessBrandHtml($businessName),
            '{{BUSINESS_ADDRESS_HTML}}' => $this->formatLinesHtml($this->splitAddressLines($businessAddr)),
            '{{BUSINESS_EMAIL_HTML}}' => $this->buildInlineDetailsHtml('Email-', $businessEmail),
            '{{BUSINESS_PHONE_HTML}}' => $this->buildInlineDetailsHtml('Mobile- ', $businessPhone),
            '{{CUSTOMER_DETAILS_HTML}}' => $this->buildCustomerDetailsHtml($data),
            '{{SENT_DATE}}' => $this->formatDisplayDate(date('Y-m-d')),
            '{{AMOUNT_PAID_VALUE}}' => $this->formatMoneyValue($amountPaid),
            '{{INVOICE_TOTAL_VALUE}}' => $this->formatMoneyValue($invoiceTotal),
            '{{STILL_OWING_VALUE}}' => $this->formatMoneyValue($stillOwing),
            '{{REFERENCE}}' => htmlspecialchars($data['invoice_number'] ?? '-', ENT_QUOTES, 'UTF-8'),
            '{{RECEIPT_ROWS}}' => $this->renderReceiptRows($data),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    private function renderLineItems(array $items, string $currency): string
    {
        if (empty($items)) return '<tr><td colspan="3">No line items</td></tr>';

        $rows = '';
        foreach ($items as $item) {
            $desc      = htmlspecialchars($item['Description'] ?? '');
            $qty       = $item['Quantity'] ?? 1;
            $lineTotal = number_format((float) ($item['LineAmount'] ?? 0), 2);
            $rows .= "<tr>
                <td>{$desc}</td>
                <td style='text-align:center'>{$qty}</td>
                <td style='text-align:right'>{$currency} {$lineTotal}</td>
            </tr>";
        }
        return $rows;
    }

    private function formatDisplayDate(?string $date): string
    {
        if (!$date) {
            return '-';
        }

        $ts = strtotime($date);
        if ($ts === false) {
            return htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
        }

        return date('j M Y', $ts);
    }

    private function formatMoneyValue(float $amount): string
    {
        return number_format($amount, 2);
    }

    private function splitAddressLines(string $value): array
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($normalized === '') {
            return [];
        }

        $parts = strpos($normalized, "\n") !== false
            ? preg_split('/\n+/', $normalized)
            : preg_split('/,\s*/', $normalized);

        $lines = [];
        foreach ($parts ?: [] as $part) {
            $line = trim((string) $part);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function formatLinesHtml(array $lines): string
    {
        if (empty($lines)) {
            return '-';
        }

        $escaped = array_map(
            static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'),
            $lines
        );

        return implode('<br>', $escaped);
    }

    private function buildCustomerDetailsHtml(array $data): string
    {
        $lines = $this->splitAddressLines((string) ($data['contact_address'] ?? ''));

        if (empty($lines) && !empty($data['contact_email'])) {
            $lines[] = (string) $data['contact_email'];
        }

        return $this->formatLinesHtml($lines);
    }

    private function buildInlineDetailsHtml(string $label, string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return '<div>' . htmlspecialchars($label . $trimmed, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    private function renderReceiptRows(array $data): string
    {
        $paymentReference = trim((string) ($data['payment_reference'] ?? ''));
        $lineDescriptions = [];

        foreach ($data['line_items'] ?? [] as $item) {
            $description = trim((string) ($item['Description'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($item['ItemCode'] ?? ''));
            }
            if ($description !== '') {
                $lineDescriptions[] = $description;
            }
        }

        if (empty($lineDescriptions)) {
            $fallback = trim((string) ($data['invoice_reference'] ?? ''));
            $lineDescriptions[] = $fallback !== '' ? $fallback : '-';
        }

        $rows = '';
        foreach ($lineDescriptions as $index => $description) {
            $paymentLines = [];
            if ($index === 0 && $paymentReference !== '') {
                $paymentLines[] = 'Payment - ' . $paymentReference;
            }
            $paymentLines[] = $description;

            $rows .= '<tr>';
            $rows .= '<td>' . ($index === 0 ? $this->formatDisplayDate($data['invoice_date'] ?? null) : '&nbsp;') . '</td>';
            $rows .= '<td>' . ($index === 0
                ? htmlspecialchars((string) ($data['invoice_number'] ?? '-'), ENT_QUOTES, 'UTF-8')
                : '&nbsp;') . '</td>';
            $rows .= '<td class="payment-reference">' . $this->formatLinesHtml($paymentLines) . '</td>';
            $rows .= '<td class="num">' . ($index === 0 ? $this->formatMoneyValue((float) ($data['total'] ?? 0)) : '&nbsp;') . '</td>';
            $rows .= '<td class="num">' . ($index === 0 ? $this->formatMoneyValue((float) ($data['amount_paid'] ?? 0)) : '&nbsp;') . '</td>';
            $rows .= '<td class="num">' . ($index === 0 ? $this->formatMoneyValue((float) ($data['amount_due'] ?? 0)) : '&nbsp;') . '</td>';
            $rows .= '</tr>';
        }

        return $rows;
    }

    private function buildBusinessBrandHtml(string $businessName): string
    {
        $logoSrc = $this->resolveBusinessLogoSrc();
        if ($logoSrc !== null) {
            return '<img class="brand-logo" src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '">';
        }

        return '<div class="brand-fallback">' . htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    private function resolveBusinessLogoSrc(): ?string
    {
        $logoPath = trim((string) Bootstrap::env('BUSINESS_LOGO_PATH', ''));
        if ($logoPath === '') {
            $logoPath = 'public/assets/globe-logo-receipt-clean.png';
        }

        if (preg_match('#^https?://#i', $logoPath)) {
            return $logoPath;
        }

        $candidates = [$logoPath];
        $projectRelative = dirname(__DIR__, 2) . '/' . ltrim($logoPath, '/');
        if ($projectRelative !== $logoPath) {
            $candidates[] = $projectRelative;
        }

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            return $this->fileToDataUri($candidate);
        }

        return null;
    }

    private function fileToDataUri(string $path): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? @mime_content_type($path) : false;
        if (!$mime) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
                default => 'application/octet-stream',
            };
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
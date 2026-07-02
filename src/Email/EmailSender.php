<?php

declare(strict_types=1);

namespace App\Email;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;
use App\Config\Bootstrap;
use App\Xero\OrgStorage;
use App\Helpers\Logger;

/**
 * Sends the receipt email with the PDF attached.
 * Wraps PHPMailer — swap in any SMTP provider by editing .env.
 */
class EmailSender
{
    /**
     * Send a receipt email.
     *
     * Returns one of three status strings:
     *   'sent'              — delivered successfully
     *   'failed'            — SMTP / PHPMailer error
     *   'skipped_no_email'  — contact has no email address
     *
     * @param array  $data        Receipt data from XeroApiClient::extractReceiptData
     * @param string $pdfPath     Absolute path to the generated PDF
     * @param string $downloadUrl Public URL to download the PDF (optional)
     * @param string $tenantId    Tenant ID to load the cached org profile
     */
    public function send(array $data, string $pdfPath, string $downloadUrl = '', string $tenantId = ''): string
    {
        $invoiceNumber = $data['invoice_number'] ?? '';
        $contactEmail  = trim($data['contact_email'] ?? '');
        $contactName   = $data['contact_name']  ?? '';

        // ── Guard: blank email ────────────────────────────────────────
        if ($contactEmail === '') {
            $this->emailLog('SKIP', $invoiceNumber, '', '', 'No email address on contact');
            Logger::warning('email', "Skipping receipt — no email for invoice {$invoiceNumber}");
            return 'skipped_no_email';
        }

        // ── Resolve org name from cache, .env as fallback ─────────────
        $orgProfile   = $tenantId ? OrgStorage::getOrgProfile($tenantId) : null;
        $businessName = $orgProfile['name']    ?? Bootstrap::env('BUSINESS_NAME', 'Us');
        $businessAddr = $orgProfile['address'] ?? Bootstrap::env('BUSINESS_ADDRESS', '');
        $businessEmail = $orgProfile['email']  ?? Bootstrap::env('BUSINESS_EMAIL', '');
        $businessPhone = $orgProfile['phone']  ?? Bootstrap::env('BUSINESS_PHONE', '');

        $mail = new PHPMailer(true); // true = throw exceptions

        try {
            // ── SMTP config ──
            $mail->isSMTP();
            $mail->Host        = Bootstrap::env('MAIL_HOST', 'smtp.gmail.com');
            $mail->SMTPAuth    = true;
            $mail->Username    = Bootstrap::env('MAIL_USERNAME');
            $mail->Password    = Bootstrap::env('MAIL_PASSWORD');
            $mail->SMTPSecure  = Bootstrap::env('MAIL_ENCRYPTION', 'tls') === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port        = (int) Bootstrap::env('MAIL_PORT', '587');

            // ── Sender ──
            $senderName = trim((string) ($data['receipt_template_sender_name'] ?? ''));
            if ($senderName === '') {
                $senderName = Bootstrap::env('MAIL_FROM_NAME', $businessName);
            } else {
                $senderName = $this->replacePlaceholders($senderName, $data, $businessName);
            }

            $mail->setFrom(
                Bootstrap::env('MAIL_FROM_ADDRESS'),
                $senderName
            );

            // ── Recipient ──
            $mail->addAddress($contactEmail, $contactName);

            // ── Finance CC ──
            $mail->addCC('finance@globesuccesslearning.com', 'Finance');

            // ── Attach PDF ──
            if ($pdfPath && file_exists($pdfPath)) {
                $filename = "Receipt-{$invoiceNumber}.pdf";
                $mail->addAttachment($pdfPath, $filename);
            }

            // ── Subject ──
            $customSubject = trim((string) ($data['receipt_template_subject'] ?? ''));
            if ($customSubject !== '') {
                $mail->Subject = $this->replacePlaceholders($customSubject, $data, $businessName);
            } else {
                $mail->Subject = "Your receipt from {$businessName} - Invoice {$invoiceNumber}";
            }

            // ── HTML body from template ──
            $mail->isHTML(true);
            $mail->Body    = $this->renderEmailTemplate($data, $downloadUrl, $businessName, $businessAddr, $businessEmail, $businessPhone);
            $mail->AltBody = $this->plainTextFallback($data, $businessName);

            $mail->send();

            $this->emailLog('SENT', $invoiceNumber, $contactEmail, $contactName, '');
            Logger::info('email', "Receipt sent to {$contactEmail}", ['invoice' => $invoiceNumber]);

            return 'sent';

        } catch (MailException $e) {
            $error = $mail->ErrorInfo ?: $e->getMessage();
            $this->emailLog('FAIL', $invoiceNumber, $contactEmail, $contactName, $error);
            Logger::error('email', "Send failed for {$invoiceNumber}: {$error}");
            return 'failed';
        }
    }

    // ── Dedicated email log ───────────────────────────────────────────

    /**
     * Write a line to storage/logs/email-YYYY-MM-DD.log.
     * Format: [2024-03-16 14:22:01] [SENT] INV-0042 → contact@email.com (Contact Name)
     */
    private function emailLog(
        string $outcome,
        string $invoiceNumber,
        string $toEmail,
        string $toName,
        string $detail
    ): void {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file      = $dir . '/email-' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $recipient = $toEmail ? "{$toEmail}" . ($toName ? " ({$toName})" : '') : '—';
        $extra     = $detail ? " | {$detail}" : '';

        $line = "[{$timestamp}] [{$outcome}] {$invoiceNumber} → {$recipient}{$extra}\n";
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    // ------------------------------------------------------------------
    // Render the email HTML template
    // ------------------------------------------------------------------
    private function renderEmailTemplate(array $data, string $downloadUrl, string $businessName, string $businessAddr, string $businessEmail, string $businessPhone): string
    {
        if (!empty($data['receipt_template_body'])) {
            return $this->renderCustomTemplate($data, $businessName);
        }

        $templatePath = dirname(__DIR__, 2) . '/templates/email/receipt.html';

        if (!file_exists($templatePath)) {
            return "<p>Hi {$data['contact_name']},<br>Your payment of {$data['amount_paid']} has been received. Invoice: {$data['invoice_number']}.</p>";
        }

        $html      = file_get_contents($templatePath);
        $currency  = $data['currency_code'] ?? 'USD';
        $lineItems = $this->renderLineItemsEmail($data['line_items'] ?? [], $currency);
        $amountWithoutCurrency = number_format((float) ($data['amount_paid'] ?? 0), 2);
        $contactFirstName = $this->firstName((string) ($data['contact_name'] ?? ''));
        $thankYouParagraph = $this->thankYouParagraph($data);

        $replacements = [
            '{{BUSINESS_NAME}}'    => $businessName,
            '{{TRADING_NAME}}'     => $businessName,
            '{{BUSINESS_ADDRESS}}' => $businessAddr,
            '{{BUSINESS_EMAIL}}'   => $businessEmail,
            '{{BUSINESS_PHONE}}'   => $businessPhone,
            '{{BUSINESS_WEBSITE}}' => Bootstrap::env('BUSINESS_WEBSITE', ''),
            '{{CUSTOMER_NAME}}'    => htmlspecialchars($data['contact_name'] ?? ''),
            '{{CONTACT_FIRST_NAME}}' => htmlspecialchars($contactFirstName),
            '{{CUSTOMER_EMAIL}}'   => htmlspecialchars($data['contact_email'] ?? ''),
            '{{INVOICE_NUMBER}}'   => htmlspecialchars($data['invoice_number'] ?? ''),
            '{{PAYMENT_DATE}}'     => $data['payment_date'] ?? date('Y-m-d'),
            '{{AMOUNT_PAID}}'      => $currency . ' ' . number_format((float) $data['amount_paid'], 2),
            '{{CURRENCY_CODE}}'    => htmlspecialchars($currency),
            '{{CUSTOMER_TOTAL_WITHOUT_CURRENCY}}' => $amountWithoutCurrency,
            '{{THANK_YOU_PARAGRAPH}}' => $thankYouParagraph,
            '{{SUB_TOTAL}}'        => $currency . ' ' . number_format((float) $data['sub_total'], 2),
            '{{TOTAL_TAX}}'        => $currency . ' ' . number_format((float) $data['total_tax'], 2),
            '{{LINE_ITEMS_EMAIL}}' => $lineItems,
            '{{PDF_DOWNLOAD_URL}}' => htmlspecialchars($downloadUrl),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    private function renderLineItemsEmail(array $items, string $currency): string
    {
        if (empty($items)) {
            return '<tr><td colspan="2">No items</td></tr>';
        }
        $rows = '';
        foreach ($items as $item) {
            $desc  = htmlspecialchars($item['Description'] ?? '');
            $total = number_format((float) ($item['LineAmount'] ?? 0), 2);
            $rows .= "<tr><td>{$desc}</td><td style='text-align:right'>{$currency} {$total}</td></tr>";
        }
        return $rows;
    }

    private function plainTextFallback(array $data, string $businessName): string
    {
        if (!empty($data['receipt_template_body'])) {
            return $this->replacePlaceholders((string) $data['receipt_template_body'], $data, $businessName);
        }

        $currency = $data['currency_code'] ?? 'USD';
        $amount   = number_format((float) $data['amount_paid'], 2);
        $firstName = $this->firstName((string) ($data['contact_name'] ?? ''));
        $description = $data['receipt_description'] ?? 'your purchase';

        return "Dear {$firstName},\n\nThank you for your payment of {$currency} {$amount} for {$description} and for placing your trust in us at Globe Success Learning!\n\nPlease find attached your receipt for your records. Kindly send us a quick reply to let us know you've received this email, and that everything is in order. If you need assistance, please reach out to Premmi at +6017 713 7249.\n\nWe look forward to be with you in your journey, continuous growth and limitless possibilities!\n\nHappy to assist,\n\nPremmi@Prem Kaur\nCreative System Designer\n+6017 713 7249\n\nMoney & You Malaysia Team\nhttps://www.globesuccesslearning.com/\n{$businessName}";
    }

    private function firstName(string $contactName): string
    {
        $contactName = trim($contactName);
        if ($contactName === '') {
            return 'there';
        }

        return preg_split('/\s+/', $contactName)[0] ?? $contactName;
    }

    private function thankYouParagraph(array $data): string
    {
        $rule = null;
        if (!empty($data['receipt_description'])) {
            $rule = ['description' => $data['receipt_description']];
        }

        $currency = htmlspecialchars((string) ($data['currency_code'] ?? 'USD'));
        $amount = number_format((float) ($data['amount_paid'] ?? 0), 2);
        $description = htmlspecialchars($rule['description'] ?? 'your purchase');

        return "Thank you for your payment of {$currency} {$amount} for {$description} and for placing your trust in us at Globe Success Learning!";
    }

    private function renderCustomTemplate(array $data, string $businessName): string
    {
        $body = $this->replacePlaceholders((string) $data['receipt_template_body'], $data, $businessName);
        $paragraphs = preg_split("/\R{2,}/", trim($body)) ?: [];

        $html = '';
        foreach ($paragraphs as $paragraph) {
            $escaped = nl2br(htmlspecialchars(trim($paragraph)));
            $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
            $html .= '<p style="margin:0 0 18px;">' . $escaped . '</p>';
        }

        return '<!DOCTYPE html><html lang="en"><body style="margin:0;padding:28px 20px;background:#f4f4f4;color:#222;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;"><div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e7e7e7;border-radius:8px;padding:30px 34px;">' . $html . '</div></body></html>';
    }

    private function replacePlaceholders(string $template, array $data, string $businessName): string
    {
        $replacements = $this->placeholderValues($data, $businessName);

        return preg_replace_callback('/\[([^\]]+)\]/', static function (array $matches) use ($replacements) {
            $raw = trim($matches[1]);
            $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower($raw)) ?? '';
            return $replacements[$normalized] ?? $matches[0];
        }, $template) ?? $template;
    }

    private function placeholderValues(array $data, string $businessName): array
    {
        $currency = (string) ($data['currency_code'] ?? 'USD');
        $amountWithoutCurrency = number_format((float) ($data['amount_paid'] ?? 0), 2);

        return [
            'contact_name' => (string) ($data['contact_name'] ?? ''),
            'contact_first_name' => $this->firstName((string) ($data['contact_name'] ?? '')),
            'contact_email' => (string) ($data['contact_email'] ?? ''),
            'currency_code' => $currency,
            'customer_total_without_currency' => $amountWithoutCurrency,
            'amount_paid' => $currency . ' ' . $amountWithoutCurrency,
            'invoice_number' => (string) ($data['invoice_number'] ?? ''),
            'invoice_date' => (string) ($data['invoice_date'] ?? ''),
            'payment_date' => (string) ($data['payment_date'] ?? ''),
            'trading_name' => $businessName,
            'business_name' => $businessName,
        ];
    }
}
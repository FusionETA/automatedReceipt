<?php
/**
 * templates/receipt/default.php
 *
 * Receipt template — Xero-style official receipt layout for the browser view.
 */

$currency = $data['currency_code'] ?? 'MYR';
$isPreview = ($invoiceId ?? '') === 'PREVIEW';

$fmtMoneyValue = function (mixed $value): string {
    if (is_string($value) && str_starts_with($value, '<<')) {
        return $value;
    }

    return number_format((float) $value, 2);
};

$fmtDate = function (?string $value): string {
    if (!$value) {
        return '—';
    }

    if (is_string($value) && str_starts_with($value, '<<')) {
        return $value;
    }

    $ts = strtotime($value);
    return $ts ? date('j M Y', $ts) : $value;
};

$splitLines = function (?string $value): array {
    $raw = trim((string) $value);
    if ($raw === '') {
        return [];
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
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
};

$linesToHtml = function (array $lines): string {
    if (empty($lines)) {
        return '—';
    }

    return implode('<br>', array_map(
        static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'),
        $lines
    ));
};

$contactLines = $splitLines($data['contact_address'] ?? '');
if (empty($contactLines) && !empty($data['contact_email'])) {
    $contactLines[] = (string) $data['contact_email'];
}

$businessLines = $splitLines($business['address'] ?? '');

$paymentReference = trim((string) ($data['payment_reference'] ?? ''));
$invoiceReference = trim((string) ($data['invoice_reference'] ?? ''));
$lineRows = [];

foreach (($data['line_items'] ?? []) as $index => $item) {
    $description = trim((string) ($item['Description'] ?? ''));
    if ($description === '') {
        $description = trim((string) ($item['ItemCode'] ?? ''));
    }

    if ($description === '') {
        continue;
    }

    $lineRows[] = [
        'show_invoice_meta' => $index === 0,
        'payment_lines' => array_values(array_filter([
            $index === 0 && $paymentReference !== '' ? 'Payment - ' . $paymentReference : '',
            $description,
        ], static fn (string $value): bool => $value !== '')),
    ];
}

if (empty($lineRows)) {
    $fallback = $invoiceReference !== '' ? $invoiceReference : '—';
    $lineRows[] = [
        'show_invoice_meta' => true,
        'payment_lines' => array_values(array_filter([
            $paymentReference !== '' ? 'Payment - ' . $paymentReference : '',
            $fallback,
        ], static fn (string $value): bool => $value !== '')),
    ];
}

$resolveLogoSrc = function (?string $logoPath): ?string {
    $path = trim((string) $logoPath);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $candidates = [$path, dirname(__DIR__, 2) . '/' . ltrim($path, '/')];

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }

        $bytes = @file_get_contents($candidate);
        if ($bytes === false) {
            continue;
        }

        $mime = function_exists('mime_content_type') ? @mime_content_type($candidate) : false;
        if (!$mime) {
            $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
                default => 'application/octet-stream',
            };
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    return null;
};

$logoSrc = $resolveLogoSrc($business['logo'] ?? '');
$downloadHref = $isPreview
    ? '#'
    : \App\Config\Bootstrap::url('/download.php') . '?id=' . urlencode($invoiceId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt – <?= htmlspecialchars($data['invoice_number'] ?? 'Receipt') ?></title>
<style>
  * { box-sizing: border-box; }

  body {
    margin: 0;
    background: #eef2f6;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
    color: #111827;
  }

  .action-bar {
    background: #111827;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }

  .action-bar a {
    color: #cbd5e1;
    text-decoration: none;
    font-size: 13px;
  }

  .action-bar a:hover {
    color: #ffffff;
  }

  .action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
  }

  .btn-print {
    background: #ffffff;
    color: #111827;
  }

  .btn-pdf {
    background: #2563eb;
    color: #ffffff;
  }

  .btn-disabled {
    background: #475569;
    color: #cbd5e1;
    pointer-events: none;
  }

  .page {
    max-width: 1040px;
    margin: 28px auto 56px;
    padding: 0 20px;
  }

  .paper {
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
    padding: 36px 40px 44px;
  }

  .brand-row {
    margin-bottom: 20px;
  }

  .brand-logo {
    display: block;
    max-width: 190px;
    max-height: 78px;
  }

  .brand-fallback {
    font-size: 24px;
    font-weight: 700;
    line-height: 1.1;
    max-width: 280px;
  }

  .title {
    font-size: 44px;
    font-weight: 300;
    letter-spacing: 1px;
    margin-bottom: 18px;
    color: #0f172a;
  }

  .summary-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(180px, 0.55fr) minmax(220px, 0.9fr);
    gap: 28px;
    align-items: start;
  }

  .customer-name {
    font-size: 17px;
    font-weight: 500;
    margin-bottom: 8px;
  }

  .summary-block {
    font-size: 16px;
    line-height: 1.55;
  }

  .meta-label {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 2px;
  }

  .meta-value {
    margin-bottom: 18px;
  }

  .business-name {
    font-size: 17px;
    font-weight: 600;
    margin-bottom: 6px;
  }

  .top-total-wrap {
    display: flex;
    justify-content: flex-end;
    margin: 26px 0 52px;
  }

  .top-total {
    width: min(100%, 360px);
    border-top: 1px solid #4b5563;
    border-bottom: 1px solid #4b5563;
    padding: 8px 0;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 16px;
    align-items: center;
    font-size: 16px;
  }

  .top-total .label {
    text-align: right;
    font-weight: 600;
  }

  .top-total .amount {
    min-width: 88px;
    text-align: right;
  }

  table.receipt-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }

  .receipt-table thead th {
    padding: 0 0 10px;
    border-bottom: 1px solid #9ca3af;
    font-size: 15px;
    font-weight: 600;
    text-align: left;
  }

  .receipt-table thead th.num {
    text-align: right;
  }

  .receipt-table tbody td {
    padding: 10px 0 8px;
    vertical-align: top;
    font-size: 15px;
    line-height: 1.45;
  }

  .receipt-table tbody td.num {
    text-align: right;
    white-space: nowrap;
    padding-left: 14px;
  }

  .receipt-table .payment-reference {
    padding-right: 22px;
    word-break: break-word;
  }

  .receipt-table .totals-row td {
    border-top: 1px solid #d1d5db;
    padding-top: 12px;
    font-weight: 700;
  }

  .receipt-table .totals-row .totals-label {
    text-align: right;
  }

  @media (max-width: 900px) {
    .paper {
      padding: 28px 24px 32px;
      border-radius: 14px;
    }

    .title {
      font-size: 36px;
    }

    .summary-grid {
      grid-template-columns: 1fr;
      gap: 20px;
    }

    .top-total-wrap {
      justify-content: flex-start;
      margin-top: 18px;
    }
  }

  @media (max-width: 720px) {
    .page {
      padding: 0 12px;
    }

    .paper {
      padding: 22px 18px 28px;
    }

    .title {
      font-size: 28px;
    }

    .summary-block,
    .top-total,
    .receipt-table thead th,
    .receipt-table tbody td {
      font-size: 14px;
    }
  }

  @media print {
    body {
      background: #ffffff;
    }

    .action-bar,
    .preview-banner {
      display: none !important;
    }

    .page {
      max-width: none;
      margin: 0;
      padding: 0;
    }

    .paper {
      box-shadow: none;
      border-radius: 0;
      padding: 0;
    }
  }
</style>
</head>
<body>

<div class="action-bar">
  <a href="<?= \App\Config\Bootstrap::url('/index.php') ?>?tab=recent_paid">← Back to Invoices</a>
  <div class="action-buttons">
    <button class="btn btn-print" onclick="window.print()">Print</button>
    <a href="<?= htmlspecialchars($downloadHref) ?>" class="btn btn-pdf<?= $isPreview ? ' btn-disabled' : '' ?>">
      <?= $isPreview ? 'PDF Disabled In Preview' : 'Download PDF' ?>
    </a>
  </div>
</div>

<div class="page">
  <div class="paper">
    <div class="brand-row">
      <?php if ($logoSrc) : ?>
        <img src="<?= htmlspecialchars($logoSrc) ?>" alt="<?= htmlspecialchars($business['name'] ?? '') ?>" class="brand-logo">
      <?php else : ?>
        <div class="brand-fallback"><?= htmlspecialchars($business['name'] ?? '') ?></div>
      <?php endif; ?>
    </div>

    <div class="title">OFFICIAL RECEIPT</div>

    <div class="summary-grid">
      <div class="summary-block">
        <div class="customer-name"><?= htmlspecialchars($data['contact_name'] ?? '—') ?></div>
        <div><?= $linesToHtml($contactLines) ?></div>
      </div>

      <div class="summary-block">
        <div class="meta-label">Payment Date</div>
        <div class="meta-value"><?= htmlspecialchars($fmtDate($data['payment_date'] ?? $data['invoice_date'] ?? null)) ?></div>

        <div class="meta-label">Sent Date</div>
        <div class="meta-value"><?= htmlspecialchars($fmtDate(date('Y-m-d'))) ?></div>
      </div>

      <div class="summary-block">
        <div class="business-name"><?= htmlspecialchars($business['name'] ?? '') ?></div>
        <div><?= $linesToHtml($businessLines) ?></div>
        <?php if (!empty($business['email'])) : ?>
          <div>Email-<?= htmlspecialchars($business['email']) ?></div>
        <?php endif; ?>
        <?php if (!empty($business['phone'])) : ?>
          <div>Mobile- <?= htmlspecialchars($business['phone']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="top-total-wrap">
      <div class="top-total">
        <div class="label">Total <?= htmlspecialchars($currency) ?> paid</div>
        <div class="amount"><?= htmlspecialchars($fmtMoneyValue($data['amount_paid'] ?? 0)) ?></div>
      </div>
    </div>

    <table class="receipt-table">
      <colgroup>
        <col style="width:15%">
        <col style="width:21%">
        <col style="width:28%">
        <col style="width:12%">
        <col style="width:12%">
        <col style="width:12%">
      </colgroup>
      <thead>
        <tr>
          <th>Invoice Date</th>
          <th>Reference</th>
          <th>Payment Reference</th>
          <th class="num">Invoice Total</th>
          <th class="num">Amount Paid</th>
          <th class="num">Still Owing</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lineRows as $row) : ?>
        <tr>
          <td><?= $row['show_invoice_meta'] ? htmlspecialchars($fmtDate($data['invoice_date'] ?? null)) : '&nbsp;' ?></td>
          <td><?= $row['show_invoice_meta'] ? htmlspecialchars($data['invoice_number'] ?? '—') : '&nbsp;' ?></td>
          <td class="payment-reference"><?= $linesToHtml($row['payment_lines']) ?></td>
          <td class="num"><?= $row['show_invoice_meta'] ? htmlspecialchars($fmtMoneyValue($data['total'] ?? 0)) : '&nbsp;' ?></td>
          <td class="num"><?= $row['show_invoice_meta'] ? htmlspecialchars($fmtMoneyValue($data['amount_paid'] ?? 0)) : '&nbsp;' ?></td>
          <td class="num"><?= $row['show_invoice_meta'] ? htmlspecialchars($fmtMoneyValue($data['amount_due'] ?? 0)) : '&nbsp;' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="totals-row">
          <td colspan="3"></td>
          <td class="totals-label">Total <?= htmlspecialchars($currency) ?></td>
          <td class="num"><?= htmlspecialchars($fmtMoneyValue($data['amount_paid'] ?? 0)) ?></td>
          <td class="num"><?= htmlspecialchars($fmtMoneyValue($data['amount_due'] ?? 0)) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

</body>
</html>
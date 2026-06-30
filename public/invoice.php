<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\XeroApiClient;

Bootstrap::init();

Auth::requireLogin();
Auth::requireOrgAccess();

$invoiceId = $_GET['id'] ?? '';
if (!$invoiceId) {
    header('Location: ' . Bootstrap::url('/index.php'));
    exit;
}

$xero    = new XeroApiClient(Auth::userId(), Auth::activeTenantId());
$invoice = $xero->getInvoice($invoiceId);

if (!$invoice) {
    die('<p style="font-family:sans-serif;padding:32px;color:red">Invoice not found or failed to load. <a href="' . Bootstrap::url('/index.php') . '">Back</a></p>');
}

function parseXeroDate(string $raw): string {
    if (empty($raw)) return '—';
    if (preg_match('/\/Date\((\d+)/', $raw, $m)) return date('d M Y', (int)($m[1] / 1000));
    if (strtotime($raw)) return date('d M Y', strtotime($raw));
    return $raw;
}

function statusColor(string $s): string {
    return ['PAID'=>'#22c55e','AUTHORISED'=>'#3b82f6','DRAFT'=>'#f59e0b','VOIDED'=>'#6b7280'][$s] ?? '#6b7280';
}

$contact   = $invoice['Contact'] ?? [];
$lineItems = $invoice['LineItems'] ?? [];
$status    = $invoice['Status'] ?? '';
$currency  = $invoice['CurrencyCode'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($invoice['InvoiceNumber'] ?? '') ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #333; }
  .topbar { background: #1a1a1a; color: #fff; padding: 14px 32px; display: flex; align-items: center; gap: 16px; }
  .topbar a { color: #aaa; text-decoration: none; font-size: 13px; }
  .topbar a:hover { color: #fff; }
  .container { max-width: 860px; margin: 32px auto; padding: 0 24px; }
  .card { background: #fff; border: 1px solid #e5e5e5; border-radius: 10px; padding: 28px 32px; margin-bottom: 20px; }
  .badge { display: inline-block; padding: 4px 12px; border-radius: 14px; font-size: 12px; font-weight: 700; color: #fff; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
  .label { font-size: 11px; text-transform: uppercase; color: #999; margin-bottom: 4px; }
  .value { font-size: 15px; font-weight: 500; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  th { text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; color: #888; border-bottom: 2px solid #f0f0f0; }
  td { padding: 11px 12px; border-bottom: 1px solid #f5f5f5; vertical-align: top; }
  .totals-row { display: flex; justify-content: flex-end; }
  .totals { min-width: 280px; }
  .totals-line { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
  .totals-line.grand { font-size: 16px; font-weight: 700; border-top: 2px solid #e5e5e5; margin-top: 4px; padding-top: 10px; }
  @media print {
    .topbar, .no-print { display: none; }
    body { background: white; }
    .container { margin: 0; padding: 0; max-width: 100%; }
    .card { border: none; box-shadow: none; }
  }
</style>
</head>
<body>

<div class="topbar no-print">
  <a href="<?= Bootstrap::url('/index.php') ?>">← Back to Invoices</a>
  <span style="color:#555">|</span>
  <span style="color:#aaa;font-size:13px">Invoice <?= htmlspecialchars($invoice['InvoiceNumber'] ?? '') ?></span>
  <div style="margin-left:auto">
    <button onclick="window.print()" style="background:#fff;color:#1a1a1a;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500">🖨 Print</button>
  </div>
</div>

<div class="container">

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px">
      <div>
        <h1 style="font-size:22px;margin-bottom:6px">Invoice <?= htmlspecialchars($invoice['InvoiceNumber'] ?? '') ?></h1>
        <span class="badge" style="background:<?= statusColor($status) ?>"><?= htmlspecialchars($status) ?></span>
      </div>
      <div style="text-align:right">
        <div style="font-size:28px;font-weight:700"><?= $currency ?> <?= number_format((float)($invoice['Total'] ?? 0), 2) ?></div>
        <?php if ((float)($invoice['AmountDue'] ?? 0) > 0): ?>
          <div style="font-size:13px;color:#ef4444;margin-top:4px">Due: <?= $currency ?> <?= number_format((float)$invoice['AmountDue'], 2) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid2">
      <div>
        <div class="label">Contact</div>
        <div class="value"><?= htmlspecialchars($contact['Name'] ?? '—') ?></div>
        <?php if (!empty($contact['EmailAddress'])): ?>
          <div style="font-size:13px;color:#666;margin-top:2px"><?= htmlspecialchars($contact['EmailAddress']) ?></div>
        <?php endif; ?>
      </div>
      <div>
        <div class="grid2">
          <div>
            <div class="label">Invoice Date</div>
            <div class="value"><?= parseXeroDate($invoice['Date'] ?? '') ?></div>
          </div>
          <div>
            <div class="label">Due Date</div>
            <div class="value"><?= parseXeroDate($invoice['DueDate'] ?? '') ?></div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($invoice['Payments'])): ?>
    <div style="margin-top:20px;padding-top:20px;border-top:1px solid #f0f0f0">
      <div class="label" style="margin-bottom:8px">Payments</div>
      <?php foreach ($invoice['Payments'] as $pay): ?>
        <div style="font-size:13px;color:#555;margin-bottom:4px">
          <?= parseXeroDate($pay['Date'] ?? '') ?> —
          <strong><?= $currency ?> <?= number_format((float)($pay['Amount'] ?? 0), 2) ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead>
        <tr>
          <th>Item / Description</th>
          <th style="text-align:right">Qty</th>
          <th style="text-align:right">Unit Price</th>
          <th style="text-align:right">Tax</th>
          <th style="text-align:right">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lineItems as $line): ?>
        <tr>
          <td>
            <?php if (!empty($line['ItemCode'])): ?>
              <strong><?= htmlspecialchars($line['ItemCode']) ?></strong><br>
            <?php endif; ?>
            <?= htmlspecialchars($line['Description'] ?? '—') ?>
          </td>
          <td style="text-align:right"><?= htmlspecialchars((string)($line['Quantity'] ?? '')) ?></td>
          <td style="text-align:right"><?= number_format((float)($line['UnitAmount'] ?? 0), 2) ?></td>
          <td style="text-align:right"><?= number_format((float)($line['TaxAmount'] ?? 0), 2) ?></td>
          <td style="text-align:right;font-weight:500"><?= number_format((float)($line['LineAmount'] ?? 0), 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals-row" style="padding:20px 24px">
      <div class="totals">
        <div class="totals-line"><span>Subtotal</span><span><?= $currency ?> <?= number_format((float)($invoice['SubTotal'] ?? 0), 2) ?></span></div>
        <div class="totals-line"><span>Tax</span><span><?= $currency ?> <?= number_format((float)($invoice['TotalTax'] ?? 0), 2) ?></span></div>
        <div class="totals-line grand"><span>Total</span><span><?= $currency ?> <?= number_format((float)($invoice['Total'] ?? 0), 2) ?></span></div>
        <?php if ((float)($invoice['AmountPaid'] ?? 0) > 0): ?>
        <div class="totals-line" style="color:#22c55e"><span>Amount Paid</span><span><?= $currency ?> <?= number_format((float)$invoice['AmountPaid'], 2) ?></span></div>
        <?php endif; ?>
        <?php if ((float)($invoice['AmountDue'] ?? 0) > 0): ?>
        <div class="totals-line" style="color:#ef4444;font-weight:600"><span>Amount Due</span><span><?= $currency ?> <?= number_format((float)$invoice['AmountDue'], 2) ?></span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
</body>
</html>
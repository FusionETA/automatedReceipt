<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\OrgStorage;

Bootstrap::init();

Auth::requireLogin();
Auth::requireOrgAccess();

$tenantId = Auth::activeTenantId();
$events   = OrgStorage::getEvents($tenantId);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="5">
<title>Webhook Events — Live</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f0f0f; color: #e0e0e0; }
  .topbar { background: #1a1a1a; padding: 14px 28px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #2a2a2a; }
  .topbar h1 { font-size: 16px; font-weight: 600; color: #fff; }
  .live-dot { display: inline-block; width: 8px; height: 8px; background: #22c55e; border-radius: 50%; margin-right: 8px; animation: pulse 1.5s infinite; }
  @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.3; } }
  .topbar a { color: #888; text-decoration: none; font-size: 13px; }
  .topbar a:hover { color: #fff; }
  .container { max-width: 900px; margin: 32px auto; padding: 0 24px; }
  .empty { text-align: center; padding: 80px 24px; color: #555; font-size: 15px; }
  .empty .hint { font-size: 13px; color: #444; margin-top: 8px; }
  .event-card { background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 8px; padding: 16px 20px; margin-bottom: 12px; display: flex; align-items: flex-start; gap: 16px; }
  .event-card.new { border-color: #22c55e44; background: #0d2010; }
  .event-icon { font-size: 22px; margin-top: 2px; }
  .event-body { flex: 1; }
  .event-header { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 700; letter-spacing: 0.4px; }
  .badge-invoice { background: #3b82f620; color: #60a5fa; border: 1px solid #3b82f640; }
  .badge-update  { background: #f59e0b20; color: #fbbf24; border: 1px solid #f59e0b40; }
  .badge-create  { background: #22c55e20; color: #4ade80; border: 1px solid #22c55e40; }
  .event-time { font-size: 12px; color: #555; margin-left: auto; white-space: nowrap; }
  .event-detail { font-size: 12px; color: #666; font-family: 'SF Mono', 'Fira Code', monospace; }
  .event-detail span { color: #888; }
  .invoice-link { color: #60a5fa; text-decoration: none; font-size: 12px; }
  .invoice-link:hover { text-decoration: underline; }
  .counter { font-size: 12px; color: #555; margin-bottom: 16px; }
  .refresh-note { font-size: 11px; color: #444; text-align: right; margin-top: 16px; }
</style>
</head>
<body>

<div class="topbar">
  <h1><span class="live-dot"></span> Webhook Events — Live</h1>
  <a href="<?= Bootstrap::url('/index.php') ?>">← Back to Invoices</a>
</div>

<div class="container">

<?php if (empty($events)): ?>
  <div class="empty">
    📡 Listening for invoice events...
    <div class="hint">Make a change to an invoice in Xero and it will appear here automatically.</div>
  </div>
<?php else: ?>
  <p class="counter"><?= count($events) ?> event<?= count($events) !== 1 ? 's' : '' ?> received</p>

  <?php foreach ($events as $i => $ev): ?>
  <div class="event-card <?= $i === 0 ? 'new' : '' ?>">
    <div class="event-icon">💸</div>
    <div class="event-body">
      <div class="event-header">
        <span class="badge badge-invoice"><?= htmlspecialchars($ev['category'] ?? 'INVOICE') ?></span>
        <span class="badge <?= strtolower($ev['event_type'] ?? '') === 'create' ? 'badge-create' : 'badge-update' ?>">
          <?= htmlspecialchars(strtoupper($ev['event_type'] ?? 'UPDATE')) ?>
        </span>
        <?php if (!empty($ev['fully_paid_date'])): ?>
          <span class="badge" style="background:#22c55e20;color:#4ade80;border:1px solid #22c55e40">
            ✅ Paid <?= htmlspecialchars($ev['fully_paid_date']) ?>
          </span>
        <?php endif; ?>
        <span class="event-time"><?= htmlspecialchars($ev['time'] ?? '') ?></span>
      </div>
      <div class="event-detail" style="margin-bottom:4px">
        <?php if (!empty($ev['invoice_number'])): ?>
          <strong style="color:#ddd"><?= htmlspecialchars($ev['invoice_number']) ?></strong> &nbsp;·&nbsp;
        <?php endif; ?>
        <?php if (!empty($ev['contact'])): ?>
          <?= htmlspecialchars($ev['contact']) ?> &nbsp;·&nbsp;
        <?php endif; ?>
        <?php if (!empty($ev['total'])): ?>
          <strong style="color:#4ade80"><?= htmlspecialchars($ev['currency'] ?? '') ?> <?= number_format((float)$ev['total'], 2) ?></strong>
        <?php endif; ?>
      </div>
      <div class="event-detail">
        <span>Invoice ID:</span> <?= htmlspecialchars($ev['resource_id'] ?? '') ?>
        &nbsp;
        <a href="<?= Bootstrap::url('/invoice.php') ?>?id=<?= urlencode($ev['resource_id'] ?? '') ?>" class="invoice-link">View →</a>
        &nbsp;
        <a href="<?= Bootstrap::url('/receipt.php') ?>?id=<?= urlencode($ev['resource_id'] ?? '') ?>" class="invoice-link">🧾 Receipt →</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <p class="refresh-note">Auto-refreshes every 5 seconds</p>
<?php endif; ?>

</div>
</body>
</html>
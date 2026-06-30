<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\OAuthClient;
use App\Xero\UserTokenStorage;
use App\Xero\XeroApiClient;
use App\Xero\OrgStorage;

Bootstrap::init();

// ── Auth guard ────────────────────────────────────────────────────────
Auth::requireLogin();

$userId = Auth::userId();
$oauth  = new OAuthClient();

// ── Handle org switch ─────────────────────────────────────────────────
if (!empty($_GET['switch_tenant'])) {
    $switchTo = $_GET['switch_tenant'];
    if (Auth::canAccessOrg($switchTo)) {
        Auth::setActiveTenant($switchTo);
    }
    header('Location: ' . Bootstrap::url('/index.php'));
    exit;
}

// ── Handle "connect another org" ──────────────────────────────────────
if (!empty($_GET['connect'])) {
    header('Location: ' . $oauth->getAuthorizationUrl());
    exit;
}

// ── Resolve active tenant ─────────────────────────────────────────────
$allTokens = UserTokenStorage::getAll($userId);

if (empty($allTokens)) {
    header('Location: ' . $oauth->getAuthorizationUrl());
    exit;
}

$activeTenantId = Auth::activeTenantId();

if (!isset($allTokens[$activeTenantId])) {
    $activeTenantId = array_key_first($allTokens);
    Auth::setActiveTenant($activeTenantId);
}

$activeToken = $allTokens[$activeTenantId];

// ── Fetch invoices ────────────────────────────────────────────────────
$invoices   = [];
$fetchError = '';
$hasMore    = false;

$page = max(1, (int) ($_GET['page'] ?? 1));

try {
    $xero = new XeroApiClient($userId, $activeTenantId);
    $invoices = $xero->getRecentlyPaid(7, $page);
    $hasMore = count($invoices) === 100;
} catch (\Throwable $e) {
    $fetchError = $e->getMessage();
}

// ── Receipt setup status ──────────────────────────────────────────────
$receiptTriggerMethod = OrgStorage::getReceiptTriggerMethod($activeTenantId);
$receiptConfigured    = OrgStorage::isReceiptTriggerConfigured($activeTenantId);

// ── Count today's events for topbar badge ─────────────────────────────
$todayEventCount = 0;
$eventsPath = dirname(__DIR__) . '/storage/orgs/' . $activeTenantId . '/webhook_events.json';
if (file_exists($eventsPath)) {
    $allEvents = json_decode(file_get_contents($eventsPath), true) ?: [];
    $today = date('Y-m-d');
    foreach ($allEvents as $ev) {
        if (isset($ev['time']) && str_starts_with($ev['time'], $today)) {
            $todayEventCount++;
        }
    }
}

// ── Load receipt send log for this org ───────────────────────────────
$receiptLog = OrgStorage::getReceiptLog($activeTenantId);

$invoices = array_filter($invoices, function ($inv) use ($receiptLog) {
    $invoiceNum = $inv['InvoiceNumber'] ?? '';
    return isset($receiptLog[$invoiceNum]);
});

$availableTemplates = [];
$templateDir = dirname(__DIR__) . '/templates/receipt/';
if (is_dir($templateDir)) {
    foreach (glob($templateDir . '*.php') as $f) {
        $availableTemplates[] = pathinfo($f, PATHINFO_FILENAME);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────
function receiptStatusBadge(?array $entry): string {
    if ($entry === null) {
        return '<span style="color:#bbb;font-size:12px">—</span>';
    }
    switch ($entry['status']) {
        case 'sent':
            $ts = $entry['timestamp'] ? '<br><span style="font-size:10px;color:#86efac">' . htmlspecialchars($entry['timestamp']) . '</span>' : '';
            return '<span style="background:#dcfce7;color:#166534;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap">✅ Sent</span>' . $ts;
        case 'failed':
            $err = $entry['error'] ? '<br><span style="font-size:10px;color:#fca5a5;max-width:160px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . htmlspecialchars($entry['error']) . '">' . htmlspecialchars($entry['error']) . '</span>' : '';
            return '<span style="background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap">❌ Failed</span>' . $err;
        case 'coa_mismatch':
            $err = $entry['error'] ? '<br><span style="font-size:10px;color:#b45309;max-width:220px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . htmlspecialchars($entry['error']) . '">' . htmlspecialchars($entry['error']) . '</span>' : '';
            return '<span style="background:#ffedd5;color:#9a3412;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap">⚠️ COA Error</span>' . $err;
        case 'coa_no_template':
            $err = $entry['error'] ? '<br><span style="font-size:10px;color:#b45309;max-width:220px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . htmlspecialchars($entry['error']) . '">' . htmlspecialchars($entry['error']) . '</span>' : '';
            return '<span style="background:#ffedd5;color:#9a3412;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap">⚠️ No Template</span>' . $err;
        case 'skipped_no_email':
            return '<span style="background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap">⚠️ No Email</span>';
        default:
            return '<span style="color:#bbb;font-size:12px">—</span>';
    }
}

function statusBadge(string $status): string {
    $map = [
        'PAID'       => '#22c55e',
        'AUTHORISED' => '#3b82f6',
        'DRAFT'      => '#f59e0b',
        'VOIDED'     => '#6b7280',
        'DELETED'    => '#ef4444',
    ];
    $color = $map[$status] ?? '#6b7280';
    return "<span style=\"background:{$color};color:#fff;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap\">{$status}</span>";
}

function parseXeroDate(string $raw): string {
    if (empty($raw)) return '—';
    if (preg_match('/\/Date\((\d+)/', $raw, $m)) return date('d M Y', (int)($m[1] / 1000));
    if (strtotime($raw)) return date('d M Y', strtotime($raw));
    return $raw;
}

function pageUrl(int $page): string {
    return '?page=' . $page;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Xero Receipt App</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #333; }

  /* ── Topbar ── */
  .topbar { background: #1a1a1a; color: #fff; padding: 14px 32px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  .topbar h1 { font-size: 18px; font-weight: 600; white-space: nowrap; }
  .topbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .settings-btn {
    display: flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2);
    color: #fff; padding: 7px 14px; border-radius: 7px;
    font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap;
    transition: background .15s;
  }
  .settings-btn:hover { background: rgba(255,255,255,.22); }

  /* ── Org dropdown — same for 1 or many orgs ── */
  .org-switcher { position: relative; }
  .org-btn {
    display: flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15);
    color: #fff; padding: 7px 12px; border-radius: 7px;
    font-size: 13px; font-weight: 500; cursor: pointer;
    font-family: inherit; white-space: nowrap;
  }
  .org-btn:hover { background: rgba(255,255,255,.18); }
  .org-btn .dot { width: 7px; height: 7px; background: #22c55e; border-radius: 50%; flex-shrink: 0; }
  .org-btn .caret { font-size: 10px; opacity: .6; margin-left: 2px; }

  .org-menu {
    display: none; position: absolute; right: 0; top: calc(100% + 6px);
    background: #fff; border: 1px solid #e5e5e5; border-radius: 9px;
    box-shadow: 0 8px 24px rgba(0,0,0,.15); min-width: 230px; z-index: 200; overflow: hidden;
  }
  .org-menu.open { display: block; }
  .org-menu-header { padding: 9px 14px; font-size: 10px; color: #aaa; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #f0f0f0; }

  /* Each connected org row */
  .org-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #f5f5f5; }
  .org-item:hover { background: #f9f9f9; }
  .org-switch-link { flex: 1; color: #222; text-decoration: none; font-size: 13px; font-weight: 500; }
  .org-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .check { color: #22c55e; font-size: 14px; font-weight: 700; line-height: 1; }
  .disc { color: #ccc; font-size: 13px; text-decoration: none; line-height: 1; padding: 2px; }
  .disc:hover { color: #ef4444; }

  /* Footer actions */
  .org-menu-footer { border-top: 1px solid #f0f0f0; }
  .org-menu-action { display: flex; align-items: center; gap: 8px; padding: 10px 14px; font-size: 13px; text-decoration: none; border-bottom: 1px solid #f5f5f5; }
  .org-menu-action:last-child { border-bottom: none; }
  .org-menu-action.add { color: #3b82f6; }
  .org-menu-action.add:hover { background: #f0f7ff; }
  .org-menu-action.danger { color: #ef4444; }
  .org-menu-action.danger:hover { background: #fef2f2; }

  /* ── Live events button with today's badge ── */
  .events-btn {
    position: relative;
    display: flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
    color: #ccc; padding: 7px 12px; border-radius: 7px;
    font-size: 13px; text-decoration: none; white-space: nowrap;
    transition: background .15s, color .15s;
  }
  .events-btn:hover { background: rgba(255,255,255,.15); color: #fff; }
  .events-badge {
    position: absolute; top: -7px; right: -7px;
    background: #22c55e; color: #fff;
    font-size: 10px; font-weight: 700;
    min-width: 18px; height: 18px; border-radius: 9px; padding: 0 4px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #1a1a1a; line-height: 1;
  }

  /* ── User menu ── */
  .user-menu-wrap { position: relative; }
  .user-menu-btn {
    display: flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
    color: #fff; padding: 6px 10px; border-radius: 7px;
    cursor: pointer; font-family: inherit;
  }
  .user-menu-btn:hover { background: rgba(255,255,255,.14); }
  .user-email { font-size: 13px; color: #ddd; white-space: nowrap; }
  .user-avatar { width: 26px; height: 26px; background: #444; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: #fff; flex-shrink: 0; }
  .user-menu {
    display: none; position: absolute; right: 0; top: calc(100% + 6px);
    min-width: 220px; background: #fff; border: 1px solid #e5e5e5;
    border-radius: 9px; box-shadow: 0 8px 24px rgba(0,0,0,.15); overflow: hidden; z-index: 220;
  }
  .user-menu.open { display: block; }
  .user-menu-header { padding: 12px 14px; font-size: 13px; color: #666; border-bottom: 1px solid #f0f0f0; background: #fafafa; word-break: break-all; }
  .user-menu-item { display: block; padding: 11px 14px; font-size: 13px; color: #333; text-decoration: none; }
  .user-menu-item:hover { background: #f9f9f9; }

  /* ── Page ── */
  .container { max-width: 1100px; margin: 32px auto; padding: 0 24px; }
  .card { background: #fff; border: 1px solid #e5e5e5; border-radius: 10px; padding: 24px 28px; margin-bottom: 20px; }
  .btn { display: inline-block; padding: 9px 20px; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; }
  .btn-outline { background: transparent; border: 1px solid #d1d1d1; color: #555; }
  .btn-outline.active { background: #1a1a1a; color: #fff; border-color: #1a1a1a; }
  .btn-outline:hover:not(.active) { border-color: #999; color: #333; }
  .tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
  .tabs .hint { font-size: 12px; color: #aaa; margin-left: 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; padding: 10px 14px; font-size: 11px; text-transform: uppercase; color: #888; border-bottom: 2px solid #f0f0f0; white-space: nowrap; }
  td { padding: 12px 14px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #fafafa; }
  td a { color: #1a1a1a; text-decoration: none; font-weight: 500; }
  td a:hover { text-decoration: underline; }
  .empty { text-align: center; padding: 60px 24px; color: #aaa; font-size: 14px; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
  .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #78350f; padding: 12px 18px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .alert-warning a { color: #92400e; font-weight: 600; white-space: nowrap; }
  .pagination { display: flex; align-items: center; gap: 8px; justify-content: flex-end; padding: 16px 20px; border-top: 1px solid #f0f0f0; }
  .pagination a { padding: 6px 14px; border: 1px solid #d1d1d1; border-radius: 6px; text-decoration: none; font-size: 13px; color: #333; }
  .pagination a:hover { background: #f5f5f5; }
  .pagination .page-info { font-size: 13px; color: #888; }
  .amount { font-weight: 600; white-space: nowrap; }
  .due-overdue { color: #ef4444; font-weight: 600; }
</style>
</head>
<body>

<div class="topbar">
  <h1>⚡ Xero Automated Receipt App</h1>

  <div class="topbar-right">

    <!-- ── Org dropdown: same pill for 1 or many orgs ─────────────── -->
    <div class="org-switcher">
      <button class="org-btn" onclick="document.getElementById('orgMenu').classList.toggle('open');event.stopPropagation()">
        <span class="dot"></span>
        <?= htmlspecialchars($activeToken['tenant_name'] ?? '') ?>
        <span class="caret">▼</span>
      </button>

      <div class="org-menu" id="orgMenu">
        <div class="org-menu-header">Organisations</div>

        <?php foreach ($allTokens as $tid => $tok): ?>
          <div class="org-item">
            <?php if ($tid === $activeTenantId): ?>
              <span class="org-switch-link" style="cursor:default"><?= htmlspecialchars($tok['tenant_name'] ?? '') ?></span>
            <?php else: ?>
              <a href="?switch_tenant=<?= urlencode($tid) ?>" class="org-switch-link"><?= htmlspecialchars($tok['tenant_name'] ?? '') ?></a>
            <?php endif; ?>
            <div class="org-actions">
              <?php if ($tid === $activeTenantId): ?>
                <span class="check">✓</span>
              <?php endif; ?>
              <a href="<?= Bootstrap::url('/disconnect.php') ?>?tenant=<?= urlencode($tid) ?>"
                 class="disc" title="Disconnect this org"
                 onclick="event.stopPropagation()">✕</a>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="org-menu-footer">
          <a href="?connect=1" class="org-menu-action add">＋ Connect another org</a>
        </div>
      </div>
    </div>

    <!-- ── Receipt Settings gear button ─────────────────────────────── -->
    <a href="<?= Bootstrap::url('/bank-accounts.php') ?>" class="settings-btn" title="Receipt Settings">
      ⚙️ <span>Receipt Settings</span>
    </a>

    <!-- ── User menu ──────────────────────────────────────────────── -->
    <div class="user-menu-wrap">
      <button class="user-menu-btn" onclick="document.getElementById('userMenu').classList.toggle('open');event.stopPropagation()">
        <div class="user-avatar"><?= strtoupper(substr(Auth::userEmail(), 0, 1)) ?></div>
        <span class="user-email"><?= htmlspecialchars(Auth::userEmail()) ?></span>
        <span class="caret" style="font-size:10px;opacity:.6">▼</span>
      </button>
      <div class="user-menu" id="userMenu">
        <div class="user-menu-header"><?= htmlspecialchars(Auth::userEmail()) ?></div>
        <a href="<?= Bootstrap::url('/logout.php') ?>" class="user-menu-item">Sign out</a>
      </div>
    </div>

  </div>
</div>

<div class="container">

  <?php if (isset($_GET['connected'])): ?>
    <div class="alert-success">✅ Connected to <strong><?= htmlspecialchars($activeToken['tenant_name'] ?? '') ?></strong>.</div>
  <?php endif; ?>
  <?php if (isset($_GET['disconnected'])): ?>
    <div class="alert-success">Disconnected. Now viewing <strong><?= htmlspecialchars($activeToken['tenant_name'] ?? '') ?></strong>.</div>
  <?php endif; ?>
  <?php if ($fetchError): ?>
    <div class="alert-error">⚠️ <?= htmlspecialchars($fetchError) ?></div>
  <?php endif; ?>

  <?php if (!$receiptConfigured): ?>
    <div class="alert-warning">
      <span style="font-size:18px">⚠️</span>
      <span>
        <?php if ($receiptTriggerMethod === 'bank_account'): ?>
          <strong>Auto-receipt is paused.</strong> Bank account trigger is selected, but no bank accounts are ticked.
        <?php else: ?>
          <strong>Action required.</strong> Choose how receipts should be triggered: bank account or chart account code.
        <?php endif; ?>
      </span>
      <a href="<?= Bootstrap::url('/bank-accounts.php') ?>" style="margin-left:auto">Configure now →</a>
    </div>
  <?php endif; ?>

  <div style="margin-bottom:20px">
    <h2 style="font-size:20px;font-weight:600;color:#111">Receipt Log</h2>
    <p style="font-size:13px;color:#777;margin-top:6px">
      View invoices with receipt activity.
    </p>
  </div>

  <!-- Invoice table -->
  <div class="card" style="padding:0;overflow:hidden">
    <?php if (empty($invoices) && !$fetchError): ?>
      <div class="empty">
        No receipt records found for recently paid invoices.
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Invoice #</th><th>Contact</th><th>Invoice Date</th>
            <th>Total</th><th>Paid</th>
            <th>Receipt Sent</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv):
            $currCode    = htmlspecialchars($inv['CurrencyCode'] ?? '');
            $total       = (float)($inv['Total'] ?? 0);
            $amountPaid  = (float)($inv['AmountPaid'] ?? 0);
            $invoiceNum  = $inv['InvoiceNumber'] ?? '';
            $receiptEntry = $receiptLog[$invoiceNum] ?? null;
          ?>
          <tr>
            <td><a href="<?= Bootstrap::url('/invoice.php') ?>?id=<?= urlencode($inv['InvoiceID']) ?>"><?= htmlspecialchars($invoiceNum ?: '—') ?></a></td>
            <td><?= htmlspecialchars($inv['Contact']['Name'] ?? '—') ?></td>
            <td style="white-space:nowrap"><?= parseXeroDate($inv['Date'] ?? '') ?></td>
            <td class="amount"><?= $currCode ?> <?= number_format($total, 2) ?></td>
            <td class="amount" style="color:#22c55e"><?= $amountPaid > 0 ? $currCode . ' ' . number_format($amountPaid, 2) : '—' ?></td>
            <td><?= receiptStatusBadge($receiptEntry) ?></td>
            <td style="white-space:nowrap">
              <a href="<?= Bootstrap::url('/receipt.php') ?>?id=<?= urlencode($inv['InvoiceID']) ?>" style="color:#3b82f6;font-size:12px;text-decoration:none">🧾 Receipt</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($page > 1 || $hasMore): ?>
        <div class="pagination">
          <?php if ($page > 1): ?><a href="<?= pageUrl($page - 1) ?>">← Prev</a><?php endif; ?>
          <span class="page-info">Page <?= $page ?></span>
          <?php if ($hasMore): ?><a href="<?= pageUrl($page + 1) ?>">Next →</a><?php endif; ?>
        </div>
       <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<script>
  document.addEventListener('click', function() {
    document.getElementById('orgMenu')?.classList.remove('open');
    document.getElementById('userMenu')?.classList.remove('open');
  });
</script>
</body>
</html>

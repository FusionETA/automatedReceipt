<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\XeroApiClient;
use App\Xero\OrgStorage;
use App\Helpers\Logger;

Bootstrap::init();

Auth::requireLogin();
Auth::requireOrgAccess();

$userId   = Auth::userId();
$tenantId = Auth::activeTenantId();

$placeholderOptions = [
    ['token' => '[Contact Name]', 'key' => 'contact_name', 'sample' => 'Jane Doe'],
    ['token' => '[Contact First Name]', 'key' => 'contact_first_name', 'sample' => 'Jane'],
    ['token' => '[Contact Email]', 'key' => 'contact_email', 'sample' => 'jane@example.com'],
    ['token' => '[Currency Code]', 'key' => 'currency_code', 'sample' => 'MYR'],
    ['token' => '[Customer Total Without Currency]', 'key' => 'customer_total_without_currency', 'sample' => '1234.50'],
    ['token' => '[Amount Paid]', 'key' => 'amount_paid', 'sample' => 'MYR 1234.50'],
    ['token' => '[Invoice Number]', 'key' => 'invoice_number', 'sample' => 'INV-0001'],
    ['token' => '[Invoice Date]', 'key' => 'invoice_date', 'sample' => '2026-04-16'],
    ['token' => '[Payment Date]', 'key' => 'payment_date', 'sample' => '2026-04-16'],
    ['token' => '[Trading Name]', 'key' => 'trading_name', 'sample' => 'Globe Success Learning'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $triggerMethod = trim((string) ($_POST['trigger_method'] ?? ''));
    $selectedBankAccounts = array_values(array_filter(array_map('trim', (array) ($_POST['account_ids'] ?? []))));
    $selectedChartCodes = array_values(array_filter(array_map('trim', (array) ($_POST['chart_account_codes'] ?? []))));
    $templateIds = array_values(array_filter(array_map('trim', (array) ($_POST['template_ids'] ?? []))));
    $templateNames = (array) ($_POST['template_names'] ?? []);
    $templateSenderNames = (array) ($_POST['template_sender_names'] ?? []);
    $templateSubjects = (array) ($_POST['template_subjects'] ?? []);
    $templateBodies = (array) ($_POST['template_bodies'] ?? []);
    $templateAssignments = (array) ($_POST['template_assignments'] ?? []);

    $templates = [];
    foreach ($templateIds as $templateId) {
        $name = trim((string) ($templateNames[$templateId] ?? ''));
        $senderName = trim((string) ($templateSenderNames[$templateId] ?? ''));
        $subject = trim((string) ($templateSubjects[$templateId] ?? ''));
        $body = trim((string) ($templateBodies[$templateId] ?? ''));

        if ($name === '' && $senderName === '' && $subject === '' && $body === '') {
            continue;
        }

        $templates[$templateId] = [
            'id' => $templateId,
            'name' => $name !== '' ? $name : 'Untitled Template',
            'sender_name' => $senderName,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    $validTemplateIds = array_fill_keys(array_keys($templates), true);
    $assignments = [];
    foreach ($selectedChartCodes as $code) {
        $assignedTemplateId = trim((string) ($templateAssignments[$code] ?? ''));
        $assignments[$code] = isset($validTemplateIds[$assignedTemplateId]) ? $assignedTemplateId : '';
    }

    OrgStorage::saveReceiptTriggerMethod($tenantId, $triggerMethod);
    OrgStorage::saveSelectedAccounts($tenantId, $selectedBankAccounts);
    OrgStorage::saveSelectedChartAccountCodes($tenantId, $selectedChartCodes);
    OrgStorage::saveTemplateLibrary($tenantId, $templates);
    OrgStorage::saveChartAccountTemplateAssignments($tenantId, $assignments);

    Logger::info('receipt_settings', "User {$userId} updated receipt settings for {$tenantId}", [
        'trigger_method' => $triggerMethod,
        'bank_accounts' => count($selectedBankAccounts),
        'chart_account_codes' => $selectedChartCodes,
    ]);

    header('Location: ' . Bootstrap::url('/bank-accounts.php') . '?saved=1');
    exit;
}

$forceRefresh = !empty($_GET['refresh']);
$xero = new XeroApiClient($userId, $tenantId);
$bankAccountsRaw = $xero->getBankAccounts($forceRefresh);
$chartAccountsRaw = $xero->getChartAccounts($forceRefresh);

$triggerMethod = OrgStorage::getReceiptTriggerMethod($tenantId);
$selectedBankAccounts = OrgStorage::getSelectedAccounts($tenantId);
$selectedChartCodes = OrgStorage::getSelectedChartAccountCodes($tenantId);
$savedTemplates = OrgStorage::getTemplateLibrary($tenantId);
$templateAssignments = OrgStorage::getChartAccountTemplateAssignments($tenantId);
$saved = isset($_GET['saved']);
$isConfigured = OrgStorage::isReceiptTriggerConfigured($tenantId);

$onlineKeywords = ['stripe', 'paypal', 'payment gateway', 'paynow', 'fpx', 'online', 'duitnow', 'senangpay', 'ipay88', 'billplz', 'toyyibpay'];
$onlineAccounts = [];
$bankAccounts = [];

foreach ($bankAccountsRaw as $acct) {
    $nameLower = strtolower($acct['name']);
    $isOnline = false;
    foreach ($onlineKeywords as $kw) {
        if (str_contains($nameLower, $kw)) {
            $isOnline = true;
            break;
        }
    }

    if ($isOnline) {
        $onlineAccounts[] = $acct;
    } else {
        $bankAccounts[] = $acct;
    }
}

$chartAccountsByCode = [];
$chartAccountsByCategory = [];

foreach ($chartAccountsRaw as $acct) {
    $code = trim((string) ($acct['code'] ?? ''));
    if ($code === '') {
        continue;
    }

    $status = strtoupper((string) ($acct['status'] ?? ''));
    $type = strtoupper((string) ($acct['type'] ?? ''));
    $systemAccount = trim((string) ($acct['system_account'] ?? ''));
    if ($status === 'ARCHIVED') {
        continue;
    }
    if ($type === 'BANK') {
        continue;
    }
    if ($systemAccount !== '') {
        continue;
    }

    $category = trim((string) ($acct['class'] ?? ''));
    if ($category === '') {
        $category = trim((string) ($acct['type'] ?? 'Other'));
    }

    $chartAccountsByCode[$code] = $acct;
    $chartAccountsByCategory[$category][] = $acct;
}

ksort($chartAccountsByCategory);

foreach ($chartAccountsByCategory as &$accounts) {
    usort($accounts, static function (array $a, array $b): int {
        return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
    });
}
unset($accounts);

$selectedChartAccounts = [];
foreach ($selectedChartCodes as $code) {
    if (isset($chartAccountsByCode[$code])) {
        $selectedChartAccounts[$code] = $chartAccountsByCode[$code];
    } else {
        $selectedChartAccounts[$code] = [
            'code' => $code,
            'name' => $code,
            'type' => '',
            'class' => '',
        ];
    }
}

$savedTemplates = OrgStorage::getTemplateLibrary($tenantId);
$templateAssignments = OrgStorage::getChartAccountTemplateAssignments($tenantId);
$saved = isset($_GET['saved']);
$isConfigured = OrgStorage::isReceiptTriggerConfigured($tenantId);

if (!is_array($savedTemplates)) {
    $savedTemplates = [];
}

uasort($savedTemplates, static function (array $a, array $b): int {
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt Settings — Xero Receipt App</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; color: #333; }
  .topbar { background: #1e3a5f; color: #fff; padding: 18px 32px; display: flex; align-items: center; gap: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.18); }
  .topbar h1 { font-size: 20px; font-weight: 700; letter-spacing: .2px; }
  .container { max-width: 1180px; margin: 36px auto; padding: 0 24px 60px; }
  .back-link { display: inline-flex; align-items: center; gap: 6px; color: #1e3a5f; text-decoration: none; font-size: 14px; font-weight: 700; margin-bottom: 20px; }
  .back-link:hover { color: #2563eb; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 14px 18px; border-radius: 10px; font-size: 14px; margin-bottom: 24px; font-weight: 600; }
  .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 16px 20px; border-radius: 10px; font-size: 14px; margin-bottom: 24px; display: flex; gap: 14px; align-items: flex-start; }
  .alert-warning .icon { font-size: 22px; line-height: 1; }
  .alert-warning strong { display: block; margin-bottom: 4px; font-size: 15px; }
  .page-header { margin-bottom: 28px; }
  .page-header h2 { font-size: 26px; font-weight: 800; color: #1e3a5f; margin-bottom: 8px; }
  .page-header p { font-size: 15px; color: #555; line-height: 1.7; }

  /* Section headers with colour strips */
  .section-heading { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
  .section-heading-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
  .section-heading-icon.blue  { background: #dbeafe; }
  .section-heading-icon.green { background: #dcfce7; }
  .section-heading-icon.purple{ background: #ede9fe; }
  .section-heading-icon.orange{ background: #ffedd5; }
  .section-heading-text h3 { font-size: 19px; font-weight: 800; color: #1e3a5f; line-height: 1.2; }
  .section-heading-text p { font-size: 13px; color: #666; margin-top: 3px; line-height: 1.5; }

  .method-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 28px; }
  .method-option { display: flex; gap: 14px; align-items: flex-start; padding: 20px 22px; background: #fff; border: 2px solid #e5e5e5; border-radius: 10px; cursor: pointer; transition: border-color .15s; }
  .method-option.checked { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.15); }
  .method-option input { width: 19px; height: 19px; margin-top: 2px; accent-color: #2563eb; flex-shrink: 0; }
  .method-title { font-size: 16px; font-weight: 800; color: #111; margin-bottom: 5px; }
  .method-copy { font-size: 14px; color: #666; line-height: 1.6; }
  .method-panel[hidden] { display: none; }
  .subtabs { display: flex; gap: 6px; padding: 16px 20px 0; border-bottom: 1px solid #e8e8e8; background: #fff; }
  .subtab-btn { border: 1px solid #d8d8d8; background: #f7f7f7; color: #555; border-radius: 8px 8px 0 0; padding: 10px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
  .subtab-btn.active { background: #fff; border-color: #2563eb; color: #2563eb; box-shadow: inset 0 -3px 0 #2563eb; }
  .subtab-panel[hidden] { display: none; }
  .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; margin-bottom: 24px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
  .card-title { padding: 16px 22px 13px; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: #fff; border-bottom: 1px solid #e0e0e0; display: flex; align-items: center; gap: 9px; }
  .card-title.blue   { background: #1e3a5f; }
  .card-title.green  { background: #166534; }
  .card-title.purple { background: #5b21b6; }
  .section-copy { padding: 16px 22px 0; font-size: 14px; color: #555; line-height: 1.7; }
  .search-wrap { padding: 14px 22px 0; }
  .search-input { width: 100%; border: 1px solid #d9d9d9; border-radius: 8px; padding: 10px 14px; font: inherit; font-size: 14px; }
  .search-help { padding: 8px 22px 0; font-size: 13px; color: #888; }
  .quick-row { padding: 10px 22px; border-bottom: 1px solid #f0f0f0; display: flex; gap: 10px; }
  .quick-link { font-size: 13px; color: #2563eb; cursor: pointer; background: none; border: none; font-family: inherit; padding: 0; text-decoration: underline; }
  .quick-link:hover { color: #1d4ed8; }
  .account-row { display: flex; align-items: center; gap: 14px; padding: 15px 22px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background .1s; }
  .account-row:last-child { border-bottom: none; }
  .account-row:hover { background: #fafafa; }
  .account-row.checked { background: #eff6ff; }
  .account-row input[type=checkbox] { width: 18px; height: 18px; accent-color: #2563eb; flex-shrink: 0; cursor: pointer; }
  .account-info { flex: 1; }
  .account-name { font-size: 15px; font-weight: 700; color: #111; }
  .account-meta { font-size: 13px; color: #888; margin-top: 3px; }
  .account-badge { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 10px; flex-shrink: 0; }
  .badge-online { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
  .badge-bank { background: #f5f5f5; color: #666; border: 1px solid #e0e0e0; }
  .selection-summary { font-size: 14px; color: #555; padding: 13px 22px; background: #f8f9fb; border-top: 1px solid #ebebeb; font-weight: 600; }
  .empty-accounts { padding: 40px 24px; text-align: center; color: #aaa; font-size: 15px; }
  details.coa-group { border-top: 1px solid #f3f3f3; }
  details.coa-group:first-of-type { border-top: none; }
  details.coa-group summary { list-style: none; cursor: pointer; padding: 15px 22px; font-size: 14px; font-weight: 800; color: #1e3a5f; display: flex; align-items: center; gap: 10px; }
  details.coa-group summary::-webkit-details-marker { display: none; }
  .coa-count { font-size: 12px; color: #888; font-weight: 600; }
  .coa-list { border-top: 1px solid #f5f5f5; }
  .coa-row { display: flex; align-items: center; gap: 14px; padding: 13px 22px; border-bottom: 1px solid #f7f7f7; }
  .coa-row:last-child { border-bottom: none; }
  .coa-row input { width: 17px; height: 17px; accent-color: #2563eb; flex-shrink: 0; }
  .coa-code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-weight: 700; color: #1e3a5f; width: 140px; flex-shrink: 0; }
  .coa-name { font-size: 14px; font-weight: 700; color: #111; }
  .coa-meta { font-size: 12px; color: #888; margin-top: 2px; }
  .table { width: 100%; border-collapse: collapse; }
  .table th { text-align: left; padding: 11px 22px; font-size: 12px; text-transform: uppercase; color: #888; border-bottom: 1px solid #eee; background: #f8f9fb; font-weight: 700; }
  .table td { padding: 13px 22px; border-bottom: 1px solid #f5f5f5; vertical-align: top; font-size: 14px; }
  .table tr:last-child td { border-bottom: none; }
  .template-card { border-top: 1px solid #f3f3f3; padding: 22px; }
  .template-card:first-of-type { border-top: none; }
  .template-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 14px; }
  .template-head h3 { font-size: 17px; font-weight: 800; color: #1e3a5f; }
  .template-head p { font-size: 12px; color: #777; margin-top: 4px; }
  .field-label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .4px; }
  .text-input, .text-area, .select-input { width: 100%; border: 1px solid #d9d9d9; border-radius: 8px; padding: 11px 13px; font: inherit; font-size: 14px; background: #fff; }
  .text-area { min-height: 220px; resize: vertical; line-height: 1.6; }
  .field-active { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14); }
  .template-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 16px; margin-top: 14px; }
  .pill-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
  .pill-btn { border: 1px solid #d7d7d7; background: #fff; border-radius: 999px; padding: 6px 12px; font-size: 12px; cursor: pointer; }
  .pill-btn:hover { border-color: #2563eb; color: #2563eb; }
  .preview-box { border: 1px solid #e7e7e7; border-radius: 8px; background: #fafafa; padding: 14px; min-height: 220px; white-space: pre-wrap; font-size: 14px; line-height: 1.7; color: #222; }
  .placeholder-note { font-size: 13px; color: #666; line-height: 1.6; margin-bottom: 10px; }
  .template-toolbar { padding: 16px 22px 0; }
  .template-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
  .template-actions-label { font-size: 14px; color: #555; font-weight: 600; }
  .template-remove { border: none; background: none; color: #b91c1c; font: inherit; font-size: 13px; cursor: pointer; padding: 0; font-weight: 600; }
  .template-remove:hover { text-decoration: underline; }
  .template-library-list { padding: 0 22px 22px; }
  .template-library-row { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, .9fr) minmax(0, 1.2fr) auto; gap: 14px; align-items: start; padding: 18px 0; border-top: 1px solid #ebebeb; }
  .template-library-row:first-child { border-top: none; }
  .template-library-name { font-size: 16px; font-weight: 800; color: #1e3a5f; }
  .template-library-meta { font-size: 13px; color: #777; margin-top: 4px; line-height: 1.5; }
  .template-library-actions { display: flex; gap: 10px; align-items: center; justify-content: flex-end; flex-wrap: wrap; }
  .text-link-btn { border: none; background: none; color: #2563eb; font: inherit; font-size: 13px; cursor: pointer; padding: 0; font-weight: 600; }
  .text-link-btn:hover { text-decoration: underline; }
  .text-link-btn.duplicate { color: #7c3aed; }
  .text-link-btn.duplicate:hover { text-decoration: underline; }
  .draft-note { padding: 0 22px 16px; font-size: 13px; color: #92400e; font-weight: 600; }
  .modal-backdrop { position: fixed; inset: 0; background: rgba(17, 17, 17, 0.5); display: flex; align-items: center; justify-content: center; padding: 24px; z-index: 1000; }
  .modal-backdrop[hidden] { display: none; }
  .modal-panel { width: min(1100px, 100%); max-height: calc(100vh - 48px); overflow: auto; background: #fff; border-radius: 14px; box-shadow: 0 24px 80px rgba(0, 0, 0, 0.26); }
  .modal-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 20px 24px; border-bottom: 1px solid #ececec; background: #1e3a5f; border-radius: 14px 14px 0 0; }
  .modal-title { font-size: 20px; font-weight: 800; color: #fff; }
  .modal-close { border: none; background: rgba(255,255,255,0.15); color: #fff; font-size: 22px; line-height: 1; cursor: pointer; padding: 4px 10px; border-radius: 6px; }
  .modal-close:hover { background: rgba(255,255,255,0.3); }
  .modal-body { padding-bottom: 20px; }
  .modal-footer { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 18px 24px; border-top: 1px solid #ececec; background: #f8f9fb; position: sticky; bottom: 0; }
  .modal-footer-note { font-size: 13px; color: #777; line-height: 1.4; }
  .actions { display: flex; gap: 10px; align-items: center; margin-top: 28px; flex-wrap: wrap; }
  .btn-primary { padding: 12px 30px; background: #1e3a5f; color: #fff; border: none; border-radius: 9px; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit; box-shadow: 0 2px 6px rgba(30,58,95,0.25); }
  .btn-primary:hover { background: #163050; }
  .btn-secondary { padding: 11px 22px; background: #fff; color: #444; border: 1px solid #ccc; border-radius: 9px; font-size: 15px; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; font-weight: 600; }
  .btn-secondary:hover { border-color: #1e3a5f; color: #1e3a5f; }
  .refresh-link { font-size: 13px; color: #aaa; text-decoration: none; margin-left: auto; }
  .refresh-link:hover { color: #555; }
  @media (max-width: 980px) {
    .method-grid, .template-grid { grid-template-columns: 1fr; }
    .template-library-row { grid-template-columns: 1fr; }
    .template-library-actions { justify-content: flex-start; }
  }
</style>
</head>
<body>

<div class="topbar">
  <h1>⚙️ Receipt Settings</h1>
  <span style="margin-left:auto;font-size:13px;opacity:.7;">Configure how receipts are triggered and sent</span>
</div>

<div class="container">

  <?php if ($saved): ?>
    <div class="alert-success">✅ Settings saved.</div>
  <?php endif; ?>

  <?php if (!$isConfigured): ?>
    <div class="alert-warning">
      <span class="icon">⚠️</span>
      <div>
        <strong>Action required — auto-receipt is paused</strong>
        Choose a trigger method below. If you use bank account trigger, tick at least one bank account. If you use chart account code trigger, select at least one chart account code.
      </div>
    </div>
  <?php endif; ?>

  <a class="back-link" href="<?= Bootstrap::url('/index.php') ?>">← Back to Dashboard</a>

  <div class="page-header">
    <h2>⚡ Choose Receipt Trigger Method</h2>
    <p>When a Xero invoice is marked as paid, the system will use the selected method below to decide whether to generate and send a receipt email.</p>
  </div>

  <form id="receiptSettingsForm" method="POST" action="<?= Bootstrap::url('/bank-accounts.php') ?>">
    <div class="method-grid">
      <label class="method-option <?= $triggerMethod === 'bank_account' ? 'checked' : '' ?>">
        <input type="radio" name="trigger_method" value="bank_account" <?= $triggerMethod === 'bank_account' ? 'checked' : '' ?> required>
        <span>
          <div class="method-title">Bank account</div>
          <div class="method-copy">Receipts trigger only when payment is received into selected bank or payment gateway accounts.</div>
        </span>
      </label>
      <label class="method-option <?= $triggerMethod === 'account_code' ? 'checked' : '' ?>">
        <input type="radio" name="trigger_method" value="account_code" <?= $triggerMethod === 'account_code' ? 'checked' : '' ?> required>
        <span>
          <div class="method-title">Chart account code</div>
          <div class="method-copy">Receipts trigger only when an invoice line item uses one of the selected chart account codes.</div>
        </span>
      </label>
    </div>

    <div id="bankAccountPanel" class="method-panel" <?= $triggerMethod === 'bank_account' ? '' : 'hidden' ?>>
      <?php if (!empty($onlineAccounts)): ?>
        <div class="card">
          <div class="card-title green">💳 Online Payment Accounts</div>
          <div class="quick-row">
            <button type="button" class="quick-link" onclick="tickGroup('online', true)">Select all</button>
            <button type="button" class="quick-link" onclick="tickGroup('online', false)">Deselect all</button>
          </div>
          <?php foreach ($onlineAccounts as $acct): ?>
            <?php $isChecked = in_array($acct['account_id'], $selectedBankAccounts, true); ?>
            <label class="account-row <?= $isChecked ? 'checked' : '' ?>" data-group="online">
              <input type="checkbox" name="account_ids[]" value="<?= htmlspecialchars($acct['account_id']) ?>" <?= $isChecked ? 'checked' : '' ?> onchange="this.closest('label').classList.toggle('checked', this.checked); updateBankCount()">
              <div class="account-info">
                <div class="account-name"><?= htmlspecialchars($acct['name']) ?></div>
                <div class="account-meta">
                  <?php if ($acct['code']): ?>Code: <?= htmlspecialchars($acct['code']) ?> &nbsp;·&nbsp; <?php endif; ?>
                  <?= htmlspecialchars($acct['currency_code']) ?>
                </div>
              </div>
              <span class="account-badge badge-online">Payment Gateway</span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-title blue">🏦 Bank Accounts</div>
        <?php if (!empty($bankAccounts)): ?>
          <div class="quick-row">
            <button type="button" class="quick-link" onclick="tickGroup('bank', true)">Select all</button>
            <button type="button" class="quick-link" onclick="tickGroup('bank', false)">Deselect all</button>
          </div>
          <?php foreach ($bankAccounts as $acct): ?>
            <?php $isChecked = in_array($acct['account_id'], $selectedBankAccounts, true); ?>
            <label class="account-row <?= $isChecked ? 'checked' : '' ?>" data-group="bank">
              <input type="checkbox" name="account_ids[]" value="<?= htmlspecialchars($acct['account_id']) ?>" <?= $isChecked ? 'checked' : '' ?> onchange="this.closest('label').classList.toggle('checked', this.checked); updateBankCount()">
              <div class="account-info">
                <div class="account-name"><?= htmlspecialchars($acct['name']) ?></div>
                <div class="account-meta">
                  <?php if ($acct['code']): ?>Code: <?= htmlspecialchars($acct['code']) ?> &nbsp;·&nbsp; <?php endif; ?>
                  <?= htmlspecialchars($acct['currency_code']) ?>
                </div>
              </div>
              <span class="account-badge badge-bank">Bank</span>
            </label>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-accounts">No BANK-type accounts found in this Xero organisation.</div>
        <?php endif; ?>
        <div class="selection-summary"><strong id="selCount"><?= count($selectedBankAccounts) ?></strong> bank account<?= count($selectedBankAccounts) !== 1 ? 's' : '' ?> selected</div>
      </div>
    </div>

    <div id="accountCodePanel" class="method-panel" <?= $triggerMethod === 'account_code' ? '' : 'hidden' ?>>
      <div class="card">
        <div class="subtabs">
          <button type="button" class="subtab-btn active" data-subtab="coa-overview">Overview</button>
          <button type="button" class="subtab-btn" data-subtab="coa-select">Select account codes</button>
          <button type="button" class="subtab-btn" data-subtab="coa-templates">Templates</button>
        </div>

        <div id="coa-overview" class="subtab-panel">
          <?php if (!empty($selectedChartAccounts)): ?>
            <div class="section-copy">This is the current chart-account-code setup. Choose which saved template each selected account code should use.</div>
            <table class="table">
              <thead>
                <tr>
                  <th>Account Code</th>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Assigned Template</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($selectedChartAccounts as $code => $acct): ?>
                  <?php
                    $assignedTemplateId = trim((string) ($templateAssignments[$code] ?? ''));
                  ?>
                  <tr>
                    <td><strong><?= htmlspecialchars((string) $code) ?></strong></td>
                    <td><?= htmlspecialchars((string) ($acct['name'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($acct['type'] ?? '')) ?></td>
                    <td>
                      <select class="select-input template-assignment-select" name="template_assignments[<?= htmlspecialchars((string) $code) ?>]">
                        <option value="">No template yet</option>
                        <?php foreach ($savedTemplates as $templateId => $template): ?>
                        <option value="<?= htmlspecialchars((string) $templateId) ?>" <?= $assignedTemplateId === (string) $templateId ? 'selected' : '' ?>>
                            <?= htmlspecialchars(trim((string) ($template['name'] ?? '')) !== '' ? (string) $template['name'] : 'Untitled Template') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="empty-accounts">No chart account codes selected yet.</div>
          <?php endif; ?>
        </div>

        <div id="coa-select" class="subtab-panel" hidden>
          <div class="section-copy">Chart accounts are grouped by Xero account class so users can expand only the categories they need. You can select more than one code.</div>
          <div class="search-wrap">
            <input
              type="search"
              id="chartAccountSearch"
              class="search-input"
              placeholder="Search by account code, name, type, or category"
              oninput="filterChartAccounts(this.value)">
          </div>
          <div class="search-help" id="chartAccountSearchHelp">Showing all chart accounts.</div>
          <?php foreach ($chartAccountsByCategory as $category => $accounts): ?>
            <details class="coa-group" data-category="<?= htmlspecialchars(strtolower($category)) ?>" <?= array_intersect(array_column($accounts, 'code'), $selectedChartCodes) ? 'open' : '' ?>>
              <summary>
                <span><?= htmlspecialchars($category) ?></span>
                <span class="coa-count"><?= count($accounts) ?> account<?= count($accounts) !== 1 ? 's' : '' ?></span>
              </summary>
              <div class="coa-list">
                <?php foreach ($accounts as $acct): ?>
                  <?php $code = (string) ($acct['code'] ?? ''); $isChecked = in_array($code, $selectedChartCodes, true); ?>
                  <label
                    class="coa-row"
                    data-search="<?= htmlspecialchars(strtolower(implode(' ', [
                      $category,
                      (string) ($acct['code'] ?? ''),
                      (string) ($acct['name'] ?? ''),
                      (string) ($acct['type'] ?? ''),
                    ]))) ?>">
                    <input type="checkbox" name="chart_account_codes[]" value="<?= htmlspecialchars($code) ?>" <?= $isChecked ? 'checked' : '' ?>>
                    <span class="coa-code"><?= htmlspecialchars($code) ?></span>
                    <span>
                      <div class="coa-name"><?= htmlspecialchars((string) ($acct['name'] ?? '')) ?></div>
                      <div class="coa-meta"><?= htmlspecialchars((string) ($acct['type'] ?? '')) ?></div>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </div>

        <div id="coa-templates" class="subtab-panel" hidden>
          <div class="section-copy">Templates are shared. Create them here once, then assign them to account codes in the Overview tab.</div>
          <div class="template-toolbar">
            <div class="template-actions">
              <div class="template-actions-label">📋 Manage your email templates — create, edit, duplicate or remove them here.</div>
              <button type="button" class="btn-secondary" id="addTemplateBtn">➕ Add Template</button>
            </div>
          </div>
          <div class="draft-note" id="templateDraftNote" hidden>New templates are drafts until you click Save Settings.</div>

          <div class="template-library-list" id="templateLibraryList"></div>

          <div id="templateLibraryCards" hidden>
            <?php foreach ($savedTemplates as $templateId => $template): ?>
              <?php
                $templateIdString = (string) ($template['id'] ?? $templateId);
                $savedName = (string) ($template['name'] ?? '');
                $savedSenderName = (string) ($template['sender_name'] ?? '');
                $savedSubject = (string) ($template['subject'] ?? '');
                $savedBody = (string) ($template['body'] ?? '');
              ?>
              <div class="template-card template-library-card" data-template-id="<?= htmlspecialchars($templateIdString) ?>" data-is-new="0">
                <input type="hidden" name="template_ids[]" value="<?= htmlspecialchars($templateIdString) ?>">
                <div class="template-head">
                  <div>
                    <h3><?= htmlspecialchars($savedName !== '' ? $savedName : 'Untitled Template') ?></h3>
                    <p>Reusable template</p>
                  </div>
                  <button type="button" class="template-remove">Remove template</button>
                </div>

                <label class="field-label" for="template-name-<?= htmlspecialchars($templateIdString) ?>">Template Name</label>
                <input class="text-input template-name-input template-focusable" id="template-name-<?= htmlspecialchars($templateIdString) ?>" name="template_names[<?= htmlspecialchars($templateIdString) ?>]" value="<?= htmlspecialchars($savedName) ?>">

                <div style="margin-top:14px;">
                  <label class="field-label" for="template-sender-name-<?= htmlspecialchars($templateIdString) ?>">Sender Name</label>
                  <input class="text-input template-sender-name template-focusable" id="template-sender-name-<?= htmlspecialchars($templateIdString) ?>" name="template_sender_names[<?= htmlspecialchars($templateIdString) ?>]" value="<?= htmlspecialchars($savedSenderName) ?>" data-preview="template-sender-name-preview-<?= htmlspecialchars($templateIdString) ?>">
                </div>

                <div style="margin-top:14px;">
                  <label class="field-label" for="template-subject-<?= htmlspecialchars($templateIdString) ?>">Email Subject</label>
                  <input class="text-input template-subject template-focusable" id="template-subject-<?= htmlspecialchars($templateIdString) ?>" name="template_subjects[<?= htmlspecialchars($templateIdString) ?>]" value="<?= htmlspecialchars($savedSubject) ?>" data-preview="template-subject-preview-<?= htmlspecialchars($templateIdString) ?>">
                </div>

                <div class="template-grid">
                  <div>
                    <label class="field-label" for="template-body-<?= htmlspecialchars($templateIdString) ?>">Email Content</label>
                    <textarea class="text-area template-body template-focusable" id="template-body-<?= htmlspecialchars($templateIdString) ?>" name="template_bodies[<?= htmlspecialchars($templateIdString) ?>]" data-preview="template-preview-<?= htmlspecialchars($templateIdString) ?>"><?= htmlspecialchars($savedBody) ?></textarea>
                  </div>
                  <div>
                    <label class="field-label">Preview</label>
                    <div class="field-label" style="margin-bottom:6px;">Sender Name</div>
                    <div class="preview-box" id="template-sender-name-preview-<?= htmlspecialchars($templateIdString) ?>" style="min-height:72px;margin-bottom:14px;"></div>
                    <div class="field-label" style="margin-bottom:6px;">Email Subject</div>
                    <div class="preview-box" id="template-subject-preview-<?= htmlspecialchars($templateIdString) ?>" style="min-height:72px;margin-bottom:14px;"></div>
                    <div class="field-label" style="margin-bottom:6px;">Email Body</div>
                    <div class="preview-box" id="template-preview-<?= htmlspecialchars($templateIdString) ?>"></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="actions">
      <button type="submit" class="btn-primary">Save Settings</button>
      <a href="<?= Bootstrap::url('/index.php') ?>" class="btn-secondary">Cancel</a>
      <a href="<?= Bootstrap::url('/bank-accounts.php') ?>?refresh=1" class="refresh-link">🔄 Refresh from Xero</a>
    </div>
  </form>
</div>

<div id="templateEditorModal" class="modal-backdrop" hidden>
  <div class="modal-panel">
    <div class="modal-header">
      <div class="modal-title">Edit Template</div>
      <button type="button" class="modal-close" id="closeTemplateModal" aria-label="Close">×</button>
    </div>
    <div class="modal-body">
      <div class="template-toolbar">
        <div class="placeholder-note">Click inside Sender Name, Email Subject, or Email Content first. Then click any placeholder below to insert it into the field you are editing.</div>
        <div class="pill-row" id="sharedPlaceholderRow">
          <?php foreach ($placeholderOptions as $placeholder): ?>
            <button type="button" class="pill-btn shared-placeholder-btn" data-token="<?= htmlspecialchars($placeholder['token']) ?>"><?= htmlspecialchars($placeholder['token']) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="search-help" id="templateFocusHint">Select a field to start inserting placeholders.</div>
      </div>
      <div id="templateEditorMount"></div>
    </div>
    <div class="modal-footer">
      <div class="modal-footer-note">Save this template here, then click Save Settings to make all receipt settings permanent.</div>
      <button type="button" class="btn-primary" id="saveTemplateModal">Save Template</button>
    </div>
  </div>
</div>

<script>
  const previewSamples = <?= json_encode(array_column($placeholderOptions, 'sample', 'key'), JSON_UNESCAPED_SLASHES) ?>;
  let activeTemplateField = null;
  let templateCounter = Date.now();
  let openTemplateCard = null;

  function updateBankCount() {
    const n = document.querySelectorAll('input[name="account_ids[]"]:checked').length;
    const el = document.getElementById('selCount');
    if (el) {
      el.textContent = n;
    }
  }

  function tickGroup(group, state) {
    document.querySelectorAll('[data-group="' + group + '"] input[type=checkbox]').forEach(function (cb) {
      cb.checked = state;
      cb.closest('label').classList.toggle('checked', state);
    });
    updateBankCount();
  }

  function setTriggerMethod(method) {
    document.querySelectorAll('input[name="trigger_method"]').forEach(function (input) {
      input.checked = input.value === method;
    });

    document.querySelectorAll('.method-option').forEach(function (option) {
      const input = option.querySelector('input[name="trigger_method"]');
      option.classList.toggle('checked', !!input && input.checked);
    });

    document.getElementById('bankAccountPanel').hidden = method !== 'bank_account';
    document.getElementById('accountCodePanel').hidden = method !== 'account_code';
  }

  function setCoaSubtab(tabId) {
    document.querySelectorAll('.subtab-btn').forEach(function (button) {
      button.classList.toggle('active', button.dataset.subtab === tabId);
    });

    document.querySelectorAll('.subtab-panel').forEach(function (panel) {
      panel.hidden = panel.id !== tabId;
    });
  }

  function insertAtCursor(textarea, text) {
    const start = textarea.selectionStart || 0;
    const end = textarea.selectionEnd || 0;
    const value = textarea.value;
    textarea.value = value.slice(0, start) + text + value.slice(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + text.length;
    renderPreview(textarea);
  }

  function placeholderSample(text) {
    return (text || '').replace(/\[([^\]]+)\]/g, function (_, raw) {
      const key = raw.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_');
      return Object.prototype.hasOwnProperty.call(previewSamples, key) ? previewSamples[key] : '[' + raw + ']';
    });
  }

  function renderPreview(textarea) {
    const previewId = textarea.dataset.preview;
    const preview = document.getElementById(previewId);
    if (!preview) {
      return;
    }

    const content = placeholderSample(textarea.value || '');

    const fallback = textarea.classList.contains('template-subject')
      ? 'Subject preview will appear here.'
      : textarea.classList.contains('template-sender-name')
      ? 'Sender name preview will appear here.'
      : 'Preview will appear here.';
    preview.textContent = content || fallback;
  }

  function setActiveTemplateField(field) {
    document.querySelectorAll('.template-focusable').forEach(function (input) {
      input.classList.toggle('field-active', input === field);
    });
    activeTemplateField = field;

    const hint = document.getElementById('templateFocusHint');
    if (!hint) {
      return;
    }

    if (!field) {
      hint.textContent = 'Select a field to start inserting placeholders.';
      return;
    }

    const label = field.closest('.template-card')?.querySelector('h3')?.textContent || 'template';
    const fieldName = field.previousElementSibling?.textContent || 'selected field';
    hint.textContent = 'Inserting into ' + fieldName.toLowerCase() + ' for ' + label + '.';
  }

  function bindTemplateField(field) {
    field.addEventListener('focus', function () {
      setActiveTemplateField(field);
    });
    field.addEventListener('click', function () {
      setActiveTemplateField(field);
    });
    field.addEventListener('input', function () {
      if (field.classList.contains('template-name-input')) {
        const title = field.closest('.template-card')?.querySelector('h3');
        if (title) {
          title.textContent = field.value.trim() || 'Untitled Template';
        }
        refreshAssignmentOptions();
        refreshTemplateList();
        if (openTemplateCard && openTemplateCard.contains(field)) {
          const modalTitle = document.querySelector('#templateEditorModal .modal-title');
          if (modalTitle) {
            modalTitle.textContent = field.value.trim() || 'Edit Template';
          }
        }
      }
      renderPreview(field);
    });
    renderPreview(field);
  }

  function bindTemplateCard(card) {
    card.querySelectorAll('.template-focusable').forEach(bindTemplateField);

    const removeBtn = card.querySelector('.template-remove');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (activeTemplateField && card.contains(activeTemplateField)) {
          setActiveTemplateField(null);
        }
        if (openTemplateCard === card) {
          closeTemplateEditor();
        }
        card.remove();
        refreshAssignmentOptions();
        refreshTemplateList();
      });
    }
  }

  function templateOptionsData() {
    return Array.from(document.querySelectorAll('.template-library-card')).map(function (card) {
      if (card.dataset.isNew === '1') {
        return null;
      }
      const id = card.dataset.templateId || '';
      const name = card.querySelector('.template-name-input')?.value.trim() || 'Untitled Template';
      return { id: id, name: name };
    }).filter(function (item) {
      return item && item.id !== '';
    });
  }

  function refreshAssignmentOptions() {
    const options = templateOptionsData();
    document.querySelectorAll('.template-assignment-select').forEach(function (select) {
      const current = select.value;
      select.innerHTML = '';

      const blank = document.createElement('option');
      blank.value = '';
      blank.textContent = 'No template yet';
      select.appendChild(blank);

      options.forEach(function (item) {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.name;
        if (item.id === current) {
          option.selected = true;
        }
        select.appendChild(option);
      });

      if (!options.some(function (item) { return item.id === current; })) {
        select.value = '';
      }
    });
  }

  function refreshTemplateList() {
    const list = document.getElementById('templateLibraryList');
    if (!list) {
      return;
    }

    const cards = Array.from(document.querySelectorAll('.template-library-card'));
    list.innerHTML = '';

    const hasDrafts = cards.some(function (card) {
      return card.dataset.isNew === '1';
    });
    const draftNote = document.getElementById('templateDraftNote');
    if (draftNote) {
      draftNote.hidden = !hasDrafts;
    }

    const visibleCards = cards.filter(function (card) {
      return card.dataset.isNew !== '1';
    });

    if (visibleCards.length === 0) {
      list.innerHTML = '<div class="empty-accounts">No templates yet. Click Add Template to create one.</div>';
      return;
    }

    visibleCards.forEach(function (card) {
      const templateId = card.dataset.templateId || '';
      const name = card.querySelector('.template-name-input')?.value.trim() || 'Untitled Template';
      const senderName = card.querySelector('.template-sender-name')?.value.trim() || 'Not set';
      const subject = card.querySelector('.template-subject')?.value.trim() || 'No subject';

      const row = document.createElement('div');
      row.className = 'template-library-row';
      row.innerHTML = `
        <div>
          <div class="template-library-name">${escapeHtml(name)}</div>
          <div class="template-library-meta">Template ID: ${escapeHtml(templateId)}</div>
        </div>
        <div>
          <div class="field-label" style="margin-bottom:4px;">Sender Name</div>
          <div class="template-library-meta">${escapeHtml(senderName)}</div>
        </div>
        <div>
          <div class="field-label" style="margin-bottom:4px;">Email Subject</div>
          <div class="template-library-meta">${escapeHtml(subject)}</div>
        </div>
        <div class="template-library-actions">
          <button type="button" class="text-link-btn" data-action="edit">Edit</button>
          <button type="button" class="text-link-btn duplicate" data-action="duplicate">Duplicate</button>
          <button type="button" class="template-remove" data-action="remove">Remove</button>
        </div>
      `;

      row.querySelector('[data-action="edit"]').addEventListener('click', function () {
        openTemplateEditor(card);
      });

      row.querySelector('[data-action="duplicate"]').addEventListener('click', function () {
        duplicateTemplateCard(card);
      });

      row.querySelector('[data-action="remove"]').addEventListener('click', function () {
        card.querySelector('.template-remove')?.click();
      });

      list.appendChild(row);
    });
  }

  function openTemplateEditor(card) {
    const modal = document.getElementById('templateEditorModal');
    const mount = document.getElementById('templateEditorMount');
    const title = modal?.querySelector('.modal-title');
    if (!modal || !mount) {
      return;
    }

    if (openTemplateCard && openTemplateCard !== card) {
      closeTemplateEditor();
    }

    openTemplateCard = card;
    mount.appendChild(card);
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    if (title) {
      title.textContent = card.querySelector('.template-name-input')?.value.trim() || 'Edit Template';
    }
  }

  function closeTemplateEditor() {
    const modal = document.getElementById('templateEditorModal');
    const hiddenStore = document.getElementById('templateLibraryCards');
    if (!modal || !hiddenStore) {
      return;
    }

    if (openTemplateCard) {
      hiddenStore.appendChild(openTemplateCard);
      openTemplateCard = null;
    }

    modal.hidden = true;
    document.body.style.overflow = '';
    setActiveTemplateField(null);
    refreshTemplateList();
  }

  function saveTemplateEditor() {
    if (openTemplateCard) {
      openTemplateCard.dataset.isNew = '0';
      const nameField = openTemplateCard.querySelector('.template-name-input');
      const title = openTemplateCard.querySelector('h3');
      if (title) {
        title.textContent = nameField?.value.trim() || 'Untitled Template';
      }
    }

    closeTemplateEditor();
    refreshAssignmentOptions();
    refreshTemplateList();
  }

  function createTemplateCard(templateId, values) {
    const card = document.createElement('div');
    card.className = 'template-card template-library-card';
    card.dataset.templateId = templateId;
    card.dataset.isNew = '1';

    card.innerHTML = `
      <input type="hidden" name="template_ids[]" value="${templateId}">
      <div class="template-head">
        <div>
          <h3>${values.name ? escapeHtml(values.name) : 'Untitled Template'}</h3>
          <p>Reusable template</p>
        </div>
        <button type="button" class="template-remove">Remove template</button>
      </div>

      <label class="field-label" for="template-name-${templateId}">Template Name</label>
      <input class="text-input template-name-input template-focusable" id="template-name-${templateId}" name="template_names[${templateId}]" value="${escapeAttr(values.name || '')}">

      <div style="margin-top:14px;">
        <label class="field-label" for="template-sender-name-${templateId}">Sender Name</label>
        <input class="text-input template-sender-name template-focusable" id="template-sender-name-${templateId}" name="template_sender_names[${templateId}]" value="${escapeAttr(values.sender_name || '')}" data-preview="template-sender-name-preview-${templateId}">
      </div>

      <div style="margin-top:14px;">
        <label class="field-label" for="template-subject-${templateId}">Email Subject</label>
        <input class="text-input template-subject template-focusable" id="template-subject-${templateId}" name="template_subjects[${templateId}]" value="${escapeAttr(values.subject || '')}" data-preview="template-subject-preview-${templateId}">
      </div>

      <div class="template-grid">
        <div>
          <label class="field-label" for="template-body-${templateId}">Email Content</label>
          <textarea class="text-area template-body template-focusable" id="template-body-${templateId}" name="template_bodies[${templateId}]" data-preview="template-preview-${templateId}">${escapeHtml(values.body || '')}</textarea>
        </div>
        <div>
          <label class="field-label">Preview</label>
          <div class="field-label" style="margin-bottom:6px;">Sender Name</div>
          <div class="preview-box" id="template-sender-name-preview-${templateId}" style="min-height:72px;margin-bottom:14px;"></div>
          <div class="field-label" style="margin-bottom:6px;">Email Subject</div>
          <div class="preview-box" id="template-subject-preview-${templateId}" style="min-height:72px;margin-bottom:14px;"></div>
          <div class="field-label" style="margin-bottom:6px;">Email Body</div>
          <div class="preview-box" id="template-preview-${templateId}"></div>
        </div>
      </div>
    `;

    return card;
  }

  function duplicateTemplateCard(sourceCard) {
    templateCounter += 1;
    const templateId = 'template-' + templateCounter;
    const sourceName = sourceCard.querySelector('.template-name-input')?.value.trim() || '';
    const values = {
      name: (sourceName ? 'Copy of ' + sourceName : 'Copy of Untitled Template'),
      sender_name: sourceCard.querySelector('.template-sender-name')?.value || '',
      subject: sourceCard.querySelector('.template-subject')?.value || '',
      body: sourceCard.querySelector('.template-body')?.value || '',
    };
    const card = createTemplateCard(templateId, values);
    card.dataset.isNew = '1';
    document.getElementById('templateLibraryCards').appendChild(card);
    bindTemplateCard(card);
    refreshAssignmentOptions();
    refreshTemplateList();
    openTemplateEditor(card);
    card.querySelector('.template-name-input')?.focus();
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/'/g, '&#039;');
  }

  document.querySelectorAll('.shared-placeholder-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!activeTemplateField) {
        const firstField = document.querySelector('.template-focusable');
        if (firstField) {
          setActiveTemplateField(firstField);
        }
      }
      if (activeTemplateField) {
        insertAtCursor(activeTemplateField, button.dataset.token);
      }
    });
  });

  document.querySelectorAll('.template-library-card').forEach(bindTemplateCard);
  refreshAssignmentOptions();
  refreshTemplateList();

  document.getElementById('closeTemplateModal')?.addEventListener('click', closeTemplateEditor);
  document.getElementById('saveTemplateModal')?.addEventListener('click', saveTemplateEditor);
  document.getElementById('templateEditorModal')?.addEventListener('click', function (event) {
    if (event.target.id === 'templateEditorModal') {
      closeTemplateEditor();
    }
  });

  const addTemplateBtn = document.getElementById('addTemplateBtn');
  if (addTemplateBtn) {
    addTemplateBtn.addEventListener('click', function () {
      templateCounter += 1;
      const templateId = 'template-' + templateCounter;
      const card = createTemplateCard(templateId, { name: '', sender_name: '', subject: '', body: '' });
      document.getElementById('templateLibraryCards').appendChild(card);
      bindTemplateCard(card);
      refreshAssignmentOptions();
      refreshTemplateList();
      openTemplateEditor(card);
      card.querySelector('.template-name-input')?.focus();
    });
  }

  document.querySelectorAll('input[name="trigger_method"]').forEach(function (input) {
    input.addEventListener('change', function () {
      if (input.checked) {
        setTriggerMethod(input.value);
      }
    });

    input.closest('.method-option').addEventListener('click', function () {
      setTriggerMethod(input.value);
    });

    if (input.checked) {
      setTriggerMethod(input.value);
    }
  });

  document.querySelectorAll('.subtab-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      setCoaSubtab(button.dataset.subtab);
    });
  });

  setCoaSubtab('coa-overview');

  function filterChartAccounts(query) {
    const term = (query || '').trim().toLowerCase();
    const groups = document.querySelectorAll('.coa-group');
    let visibleRows = 0;

    groups.forEach(function (group) {
      const rows = group.querySelectorAll('.coa-row');
      let groupVisible = 0;

      rows.forEach(function (row) {
        const haystack = row.dataset.search || '';
        const match = term === '' || haystack.indexOf(term) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) {
          groupVisible += 1;
          visibleRows += 1;
        }
      });

      group.style.display = groupVisible > 0 ? '' : 'none';
      if (term !== '' && groupVisible > 0) {
        group.open = true;
      }
    });

    const help = document.getElementById('chartAccountSearchHelp');
    if (!help) {
      return;
    }

    if (term === '') {
      help.textContent = 'Showing all chart accounts.';
    } else if (visibleRows === 0) {
      help.textContent = 'No chart accounts matched your search.';
    } else {
      help.textContent = 'Found ' + visibleRows + ' matching chart account' + (visibleRows === 1 ? '' : 's') + '.';
    }
  }
</script>
</body>
</html>
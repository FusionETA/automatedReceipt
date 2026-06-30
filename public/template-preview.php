<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;

Bootstrap::init();

Auth::requireLogin();
Auth::requireOrgAccess();

// ── Pick template ─────────────────────────────────────────────────────
$template     = $_GET['template'] ?? $_ENV['RECEIPT_TEMPLATE'] ?? 'default';
$templatePath = dirname(__DIR__) . '/templates/receipt/' . $template . '.php';

if (!file_exists($templatePath)) {
    $template     = 'default';
    $templatePath = dirname(__DIR__) . '/templates/receipt/default.php';
}

$availableTemplates = [];
$templateDir = dirname(__DIR__) . '/templates/receipt/';
if (is_dir($templateDir)) {
    foreach (glob($templateDir . '*.php') as $file) {
        $availableTemplates[] = pathinfo($file, PATHINFO_FILENAME);
    }
}

// ── Placeholder data ──────────────────────────────────────────────────
$invoiceId = 'PREVIEW';

$data = [
    'invoice_number' => '<<Invoice No>>',
    'invoice_date'   => '<<Invoice Date>>',
    'payment_date'   => '<<Payment Date>>',
    'contact_name'   => '<<Customer Name>>',
    'contact_email'  => '<<customer@email.com>>',
    'contact_address' => "<<Address Line 1>>\n<<City, Postcode>>\n<<Country>>",
    'amount_paid'    => '<<Amount Paid>>',
    'sub_total'      => '<<Subtotal>>',
    'total_tax'      => '<<Tax>>',
    'total'          => '<<Total>>',
    'amount_due'     => '<<Still Owing>>',
    'currency_code'  => '<<Currency>>',
    'invoice_reference' => '<<Invoice Ref>>',
    'payment_reference' => '<<Payment Ref>>',
    'line_items'     => [
        [
            'ItemCode'    => '<<Item Code>>',
            'Description' => '<<Item Description 1>>',
            'Quantity'    => '<<Qty>>',
            'LineAmount'  => '<<Line Amount>>',
        ],
        [
            'ItemCode'    => '<<Item Code 2>>',
            'Description' => '<<Item Description 2>>',
            'Quantity'    => '<<Qty 2>>',
            'LineAmount'  => '<<Line Amount 2>>',
        ],
    ],
];

$business = [
    'name'    => $_ENV['BUSINESS_NAME']      ?? '<<Business Name>>',
    'email'   => $_ENV['BUSINESS_EMAIL']     ?? '<<business@email.com>>',
    'address' => $_ENV['BUSINESS_ADDRESS']   ?? "<<Address Line 1>>\n<<City, Postcode>>\n<<Country>>",
    'phone'   => $_ENV['BUSINESS_PHONE']     ?? '<<Phone>>',
    'website' => $_ENV['BUSINESS_WEBSITE']   ?? '<<https://website.com>>',
    'logo'    => $_ENV['BUSINESS_LOGO_PATH'] ?? 'public/assets/globe-logo-receipt-clean.png',
];

$paymentMethod = '<<Payment Method>>';

// ── Preview banner ────────────────────────────────────────────────────
$currentActive = $_ENV['RECEIPT_TEMPLATE'] ?? 'default';
$bannerHtml = '
<style>
  .preview-banner{background:#7c3aed;color:#fff;padding:10px 20px;display:flex;
    align-items:center;justify-content:space-between;gap:12px;font-size:13px;
    flex-wrap:wrap;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
  .preview-banner strong{font-size:14px}
  .preview-banner .note{opacity:.75;font-size:12px}
  .preview-banner .right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .preview-banner select{padding:5px 10px;border-radius:5px;border:none;font-size:13px;cursor:pointer}
  .pb-btn{padding:6px 14px;border-radius:5px;border:none;font-size:13px;font-weight:500;
    cursor:pointer;text-decoration:none;font-family:inherit}
  .pb-back{background:rgba(255,255,255,.2);color:#fff}
  .pb-back:hover{background:rgba(255,255,255,.3)}
  @media print{.preview-banner{display:none!important}}
</style>
<div class="preview-banner">
  <div>
    <strong>🎨 Template Preview</strong>
    <span class="note"> — placeholders shown, not real data</span>
  </div>
  <div class="right">';

if (count($availableTemplates) > 1) {
    $bannerHtml .= '<form method="GET" style="display:inline"><select name="template" onchange="this.form.submit()">';
    foreach ($availableTemplates as $tpl) {
        $sel = $tpl === $template ? ' selected' : '';
        $bannerHtml .= '<option value="' . htmlspecialchars($tpl) . '"' . $sel . '>' . htmlspecialchars($tpl) . '</option>';
    }
    $bannerHtml .= '</select></form>';
} else {
    $bannerHtml .= '<span style="opacity:.7">Template: <strong>' . htmlspecialchars($template) . '</strong></span>';
}

if ($template === $currentActive) {
    $bannerHtml .= '<span style="background:rgba(255,255,255,.15);padding:5px 12px;border-radius:5px;font-size:12px">✅ Active template</span>';
} else {
    $bannerHtml .= '<span style="opacity:.7;font-size:12px">Set <code>RECEIPT_TEMPLATE=' . htmlspecialchars($template) . '</code> in .env to activate</span>';
}

$bannerHtml .= '
    <a href="' . Bootstrap::url('/index.php') . '" class="pb-btn pb-back">← Back</a>
  </div>
</div>';

// ── Render ────────────────────────────────────────────────────────────
ob_start();
require $templatePath;
$output = ob_get_clean();

echo str_replace('<body>', '<body>' . $bannerHtml, $output);
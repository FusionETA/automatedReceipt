<?php

declare(strict_types=1);

/**
 * public/receipt.php
 *
 * Controller — fetches data, then renders a receipt template.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\XeroApiClient;
use App\Xero\OrgStorage;

Bootstrap::init();

Auth::requireLogin();
Auth::requireOrgAccess();

// ── Input ─────────────────────────────────────────────────────────────
$invoiceId = $_GET['id'] ?? '';
if (!$invoiceId) {
    header('Location: ' . Bootstrap::url('/index.php'));
    exit;
}

// ── Fetch invoice from Xero ───────────────────────────────────────────
$xero     = new XeroApiClient(Auth::userId(), Auth::activeTenantId());
$raw      = $xero->getInvoice($invoiceId);
$tenantId = Auth::activeTenantId();

if (!$raw) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:32px;color:red">Invoice not found. <a href="' . Bootstrap::url('/index.php') . '">Back</a></p>');
}

// ── Build template data ───────────────────────────────────────────────
$data = $xero->extractReceiptData($raw);

// ── Resolve business info: name+address from Xero, rest from .env ─────
$orgProfile = OrgStorage::getOrgProfile($tenantId);
if (
    !$orgProfile
    || empty($orgProfile['address'])
    || empty($orgProfile['email'])
    || empty($orgProfile['phone'])
) {
    $freshOrgProfile = $xero->getOrganisation();
    if ($freshOrgProfile) {
        $orgProfile = array_merge($orgProfile ?? [], $freshOrgProfile);
        OrgStorage::saveOrgProfile($tenantId, $orgProfile);
    }
}

$business = [
    'name'    => $orgProfile['name']    ?? $_ENV['BUSINESS_NAME']    ?? 'Your Business',
    'address' => $orgProfile['address'] ?? $_ENV['BUSINESS_ADDRESS'] ?? '',
    'email'   => $orgProfile['email']   ?? $_ENV['BUSINESS_EMAIL'] ?? '',
    'phone'   => $orgProfile['phone']   ?? $_ENV['BUSINESS_PHONE'] ?? '',
    'logo'    => $_ENV['BUSINESS_LOGO_PATH'] ?? 'public/assets/globe-logo-receipt-clean.png',
];

$paymentMethod = 'Paid in Full';
if (!empty($raw['Payments'][0]['Reference'])) {
    $paymentMethod = $raw['Payments'][0]['Reference'];
}

// ── Choose template ───────────────────────────────────────────────────
$template     = $_ENV['RECEIPT_TEMPLATE'] ?? 'default';
$templatePath = dirname(__DIR__) . '/templates/receipt/' . $template . '.php';

if (!file_exists($templatePath)) {
    $templatePath = dirname(__DIR__) . '/templates/receipt/default.php';
}

// ── Render ────────────────────────────────────────────────────────────
require $templatePath;
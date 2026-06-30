<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\XeroApiClient;
use App\Xero\OrgStorage;
use App\PDF\ReceiptGenerator;

Bootstrap::init();

Auth::requireLogin();

$invoiceId = trim($_GET['id'] ?? '');
if (!$invoiceId) {
    http_response_code(400);
    die('Missing invoice ID.');
}

$userId   = Auth::userId();
$tenantId = Auth::activeTenantId();

Auth::requireOrgAccess($tenantId);

// ── Fetch invoice ─────────────────────────────────────────────────────
$xero    = new XeroApiClient($userId, $tenantId);
$invoice = $xero->getInvoice($invoiceId);

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

$data = $xero->extractReceiptData($invoice);

$generator = new ReceiptGenerator($tenantId);
$pdfPath   = $generator->generate($data);

if (!$pdfPath || !file_exists($pdfPath)) {
    http_response_code(500);
    die('PDF generation failed.');
}

// ── Stream to browser ─────────────────────────────────────────────────
while (ob_get_level()) { ob_end_clean(); }

$filename = 'Receipt-' . preg_replace('/[^a-zA-Z0-9\-_]/', '_', $data['invoice_number']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($pdfPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($pdfPath);
exit;
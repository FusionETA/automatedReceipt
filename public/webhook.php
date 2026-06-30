<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Xero\WebhookVerifier;
use App\Helpers\Logger;

Bootstrap::init();

// 1) Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// 2) Read raw body + signature
$rawBody   = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_XERO_SIGNATURE'] ?? '';

Logger::info('webhook', 'Webhook received', [
    'sig_present' => !empty($signature),
    'body_length' => strlen($rawBody),
]);

// 3) Verify signature
// Xero expects 401 for invalid signature
if (!WebhookVerifier::verify($rawBody, $signature)) {
    Logger::warning('webhook', 'Bad signature — returning 401.');
    http_response_code(401);
    exit;
}

// 4) Decode payload
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    Logger::warning('webhook', 'Invalid JSON payload — returning 200 to avoid retries.');
    http_response_code(200);
    exit;
}

// 5) Handshake / empty events => acknowledge immediately
if (empty($payload['events']) || !is_array($payload['events'])) {
    Logger::info('webhook', 'Empty events — acknowledge 200.');
    http_response_code(200);
    exit;
}

// 6) Ensure queue directory exists
$queueDir = dirname(__DIR__) . '/storage/webhook_queue';

if (!is_dir($queueDir) && !mkdir($queueDir, 0755, true) && !is_dir($queueDir)) {
    Logger::error('webhook', 'Failed to create webhook queue directory.', [
        'queue_dir' => $queueDir,
    ]);
    http_response_code(500);
    exit;
}

// 7) Save raw payload into queue for cron/process-queue.php
try {
    $fileName = sprintf(
        '%s_%s_%s.json',
        date('Ymd_His'),
        substr(bin2hex(random_bytes(4)), 0, 8),
        preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($payload['firstEventSequence'] ?? 'evt'))
    );

    $tmpPath   = $queueDir . '/.' . $fileName . '.tmp';
    $finalPath = $queueDir . '/' . $fileName;

    $written = file_put_contents($tmpPath, $rawBody, LOCK_EX);

    if ($written === false) {
        throw new RuntimeException('file_put_contents returned false');
    }

    if (!rename($tmpPath, $finalPath)) {
        @unlink($tmpPath);
        throw new RuntimeException('rename failed');
    }

    Logger::info('webhook', 'Webhook queued successfully.', [
        'queue_file'   => basename($finalPath),
        'event_count'  => count($payload['events']),
    ]);

    http_response_code(200);
    exit;
} catch (\Throwable $e) {
    Logger::error('webhook', 'Failed to queue webhook payload: ' . $e->getMessage());
    http_response_code(500);
    exit;
}
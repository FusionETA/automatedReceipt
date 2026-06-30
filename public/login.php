<?php

declare(strict_types=1);

/**
 * public/login.php
 *
 * Entry point for all users. Redirects to Xero for authentication.
 * No username/password — Xero is the identity provider.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\OAuthClient;

Bootstrap::init();

// Already logged in — go to dashboard
if (Auth::check()) {
    header('Location: ' . Bootstrap::url('/index.php'));
    exit;
}

$oauth   = new OAuthClient();
$authUrl = $oauth->getAuthorizationUrl();

$error = $_GET['error'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — <?= htmlspecialchars($_ENV['BUSINESS_NAME'] ?? 'Xero Receipt App') ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #f5f5f5; color: #333;
    display: flex; align-items: center; justify-content: center; min-height: 100vh;
  }
  .card {
    background: #fff; border: 1px solid #e5e5e5; border-radius: 12px;
    padding: 48px 44px; width: 100%; max-width: 400px;
    box-shadow: 0 4px 24px rgba(0,0,0,.07);
    text-align: center;
  }
  .logo {
    width: 48px; height: 48px; background: #1a1a1a; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; margin: 0 auto 20px;
  }
  h1 { font-size: 20px; font-weight: 700; color: #111; margin-bottom: 8px; }
  .sub { font-size: 14px; color: #888; margin-bottom: 32px; line-height: 1.5; }
  .btn-xero {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; padding: 13px 20px;
    background: #13B5EA; color: #fff;
    border: none; border-radius: 8px;
    font-size: 15px; font-weight: 600; cursor: pointer;
    text-decoration: none; font-family: inherit;
    transition: background .15s;
  }
  .btn-xero:hover { background: #0fa3d4; }
  .xero-icon { width: 22px; height: 22px; }
  .divider { margin: 20px 0; font-size: 12px; color: #ccc; }
  .note { font-size: 12px; color: #aaa; line-height: 1.6; margin-top: 24px; }
  .alert-error {
    background: #fef2f2; border: 1px solid #fecaca; color: #dc2626;
    padding: 10px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 20px;
    text-align: left;
  }
</style>
</head>
<body>
<div class="card">
  <div class="logo">⚡</div>
  <h1><?= htmlspecialchars($_ENV['BUSINESS_NAME'] ?? 'Xero Receipt App') ?></h1>
  <p class="sub">Sign in with your Xero account to access your receipt dashboard.</p>

  <?php if ($error): ?>
    <div class="alert-error">
      <?php if ($error === 'no_orgs'): ?>
        ⚠️ No Xero organisations found. Make sure you have access to at least one org.
      <?php elseif ($error === 'oauth_failed'): ?>
        ⚠️ Sign in failed. Please try again.
      <?php else: ?>
        ⚠️ <?= htmlspecialchars($error) ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <a href="<?= htmlspecialchars($authUrl) ?>" class="btn-xero">
    <!-- Xero logo SVG -->
    <svg class="xero-icon" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="20" cy="20" r="20" fill="white"/>
      <path d="M13.5 20l-4.2-4.2 1.4-1.4 4.2 4.2-4.2 4.2-1.4-1.4L13.5 20zm13 0l4.2 4.2-1.4 1.4L25.1 21.4l-4.2 4.2-1.4-1.4L23.7 20l-4.2-4.2 1.4-1.4L25.1 18.6l4.2-4.2 1.4 1.4L26.5 20z" fill="#13B5EA"/>
    </svg>
    Continue with Xero
  </a>

  <p class="note">
    You'll be redirected to Xero to sign in.<br>
    Only users with Xero access can log in.
  </p>
</div>
</body>
</html>
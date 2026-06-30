<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\OAuthClient;
use App\Xero\UserTokenStorage;
use App\Xero\XeroApiClient;
use App\Xero\OrgStorage;
use App\Helpers\Logger;

Bootstrap::init();

$oauth = new OAuthClient();

// ── Xero returned an error ────────────────────────────────────────────
if (!empty($_GET['error'])) {
    Logger::error('oauth', 'Xero returned error: ' . $_GET['error']);
    header('Location: ' . Bootstrap::url('/login.php') . '?error=oauth_failed');
    exit;
}

// ── User submitted org choice ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tenant_id'])) {
    $accessToken  = $_SESSION['pending_access_token'] ?? '';
    $refreshToken = $_SESSION['pending_refresh_token'] ?? '';
    $expiresAt    = (int) ($_SESSION['pending_expires_at'] ?? 0);
    $tenants      = $_SESSION['pending_tenants'] ?? [];
    $identity     = $_SESSION['pending_identity'] ?? [];

    if (!$accessToken || !$tenants || !$identity) {
        die('Session expired. Please <a href="' . Bootstrap::url('/login.php') . '">sign in again</a>.');
    }

    $chosenId = $_POST['tenant_id'];
    $tenant   = null;

    foreach ($tenants as $t) {
        if ($t['tenantId'] === $chosenId) {
            $tenant = $t;
            break;
        }
    }

    if (!$tenant) {
        die('Invalid organisation selected. Please <a href="' . Bootstrap::url('/login.php') . '">try again</a>.');
    }

    $userId = $identity['sub'];

    UserTokenStorage::save(
        $userId,
        $tenant['tenantId'],
        $tenant['tenantName'] ?? 'Xero Org',
        $accessToken,
        $refreshToken,
        $expiresAt
    );

    Auth::login($userId, $identity['email'], $identity['name'], $tenant['tenantId']);

    try {
        $xero       = new XeroApiClient($userId, $tenant['tenantId']);
        $orgProfile = $xero->getOrganisation();

        if ($orgProfile) {
            OrgStorage::saveOrgProfile($tenant['tenantId'], $orgProfile);
            Logger::info('oauth', "Org profile cached for {$tenant['tenantName']}");
        }

        // Pre-warm account caches so settings are ready immediately
        $xero->getBankAccounts();
        Logger::info('oauth', "Bank accounts cached for {$tenant['tenantName']}");
        $xero->getChartAccounts();
        Logger::info('oauth', "Chart accounts cached for {$tenant['tenantName']}");
    } catch (\Throwable $e) {
        Logger::warning('oauth', 'Could not cache org data: ' . $e->getMessage());
    }

    unset(
        $_SESSION['pending_access_token'],
        $_SESSION['pending_refresh_token'],
        $_SESSION['pending_expires_at'],
        $_SESSION['pending_tenants'],
        $_SESSION['pending_identity']
    );

    Logger::info('oauth', "User {$identity['email']} connected org: {$tenant['tenantName']} ({$tenant['tenantId']})");

    header('Location: ' . Bootstrap::url('/index.php') . '?connected=1');
    exit;
}

// ── Step 1: Handle OAuth callback from Xero ───────────────────────────
try {
    $code  = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';

    if (!$code || !$state) {
        die('Missing code or state parameter.');
    }

    if (!$oauth->verifyState($state)) {
        Logger::warning('oauth', 'State mismatch — possible CSRF attack.');
        header('Location: ' . Bootstrap::url('/login.php') . '?error=oauth_failed');
        exit;
    }

    $tokens = $oauth->exchangeCode($code);

    $accessToken  = $tokens['access_token'];
    $refreshToken = $tokens['refresh_token'];
    $expiresIn    = (int) $tokens['expires_in'];
    $expiresAt    = time() + $expiresIn;
    $idToken      = $tokens['id_token'] ?? '';

    if (!$idToken) {
        throw new \RuntimeException('No id_token returned. Ensure openid scope is requested.');
    }

    $identity = $oauth->decodeIdentity($idToken);

    if (empty($identity['sub'])) {
        throw new \RuntimeException('Could not extract user identity from id_token.');
    }

    Logger::info('oauth', "Identity resolved: {$identity['email']} ({$identity['sub']})");

    $tenants = $oauth->getTenants($accessToken);

    if (empty($tenants)) {
        Logger::warning('oauth', "User {$identity['email']} has no Xero orgs.");
        header('Location: ' . Bootstrap::url('/login.php') . '?error=no_orgs');
        exit;
    }

    $userId = $identity['sub'];

    if (count($tenants) === 1) {
        $tenant = $tenants[0];

        UserTokenStorage::save(
            $userId,
            $tenant['tenantId'],
            $tenant['tenantName'] ?? 'Xero Org',
            $accessToken,
            $refreshToken,
            $expiresAt
        );

        Auth::login($userId, $identity['email'], $identity['name'], $tenant['tenantId']);

        try {
            $xero       = new XeroApiClient($userId, $tenant['tenantId']);
            $orgProfile = $xero->getOrganisation();

            if ($orgProfile) {
                OrgStorage::saveOrgProfile($tenant['tenantId'], $orgProfile);
                Logger::info('oauth', "Org profile cached for {$tenant['tenantName']}");
            }

            // Pre-warm account caches so settings are ready immediately
            $xero->getBankAccounts();
            Logger::info('oauth', "Bank accounts cached for {$tenant['tenantName']}");
            $xero->getChartAccounts();
            Logger::info('oauth', "Chart accounts cached for {$tenant['tenantName']}");
        } catch (\Throwable $e) {
            Logger::warning('oauth', 'Could not cache org data: ' . $e->getMessage());
        }

        Logger::info('oauth', "User {$identity['email']} connected: {$tenant['tenantName']}");
        header('Location: ' . Bootstrap::url('/index.php') . '?connected=1');
        exit;
    }

    $_SESSION['pending_access_token']  = $accessToken;
    $_SESSION['pending_refresh_token'] = $refreshToken;
    $_SESSION['pending_expires_at']    = $expiresAt;
    $_SESSION['pending_tenants']       = $tenants;
    $_SESSION['pending_identity']      = $identity;

} catch (\Throwable $e) {
    Logger::error('oauth', 'OAuth callback error: ' . $e->getMessage());
    header('Location: ' . Bootstrap::url('/login.php') . '?error=' . urlencode($e->getMessage()));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Choose Xero Organisation</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #f5f5f5; color: #333;
    display: flex; align-items: center; justify-content: center; min-height: 100vh;
  }
  .card {
    background: #fff; border: 1px solid #e5e5e5; border-radius: 12px;
    padding: 36px 40px; width: 100%; max-width: 440px;
    box-shadow: 0 4px 20px rgba(0,0,0,.08);
  }
  .user-pill {
    display: inline-flex; align-items: center; gap: 8px;
    background: #f5f5f5; border-radius: 20px; padding: 6px 12px;
    font-size: 13px; color: #555; margin-bottom: 20px;
  }
  .user-pill .dot { width: 8px; height: 8px; background: #22c55e; border-radius: 50%; }
  h2  { font-size: 20px; margin-bottom: 6px; color: #111; }
  .sub { font-size: 14px; color: #888; margin-bottom: 24px; }
  .org-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
  .org-option {
    display: flex; align-items: center; gap: 12px;
    border: 2px solid #e5e5e5; border-radius: 8px;
    padding: 14px 16px; cursor: pointer;
    transition: border-color .15s, background .15s;
  }
  .org-option:hover { border-color: #1a1a1a; background: #fafafa; }
  .org-option:has(input:checked) { border-color: #1a1a1a; background: #f9f9f9; }
  .org-option input[type=radio] { accent-color: #1a1a1a; width: 16px; height: 16px; flex-shrink: 0; }
  .org-name { font-weight: 600; font-size: 15px; color: #111; }
  .org-type { font-size: 12px; color: #aaa; margin-top: 2px; }
  .btn {
    width: 100%; padding: 12px; background: #1a1a1a; color: #fff;
    border: none; border-radius: 8px; font-size: 15px; font-weight: 600;
    cursor: pointer; font-family: inherit;
  }
  .btn:hover { background: #333; }
  .cancel { display: block; text-align: center; margin-top: 14px; font-size: 13px; color: #aaa; text-decoration: none; }
  .cancel:hover { color: #555; }
</style>
</head>
<body>
<div class="card">
  <div class="user-pill">
    <span class="dot"></span>
    <?= htmlspecialchars($identity['email']) ?>
  </div>

  <h2>Choose Organisation</h2>
  <p class="sub">
    You have access to <?= count($tenants) ?> Xero organisations.
    Select which one to connect.
  </p>

  <form method="POST" action="<?= Bootstrap::url('/callback.php') ?>">
    <input type="hidden" name="tenant_id" id="tenantIdInput" value="<?= htmlspecialchars($tenants[0]['tenantId']) ?>">

    <div class="org-list">
      <?php foreach ($tenants as $i => $t): ?>
      <label class="org-option">
        <input type="radio" name="selected_tenant"
          value="<?= htmlspecialchars($t['tenantId']) ?>"
          <?= $i === 0 ? 'checked' : '' ?>
          onchange="document.getElementById('tenantIdInput').value = this.value">
        <div>
          <div class="org-name"><?= htmlspecialchars($t['tenantName'] ?? 'Unknown') ?></div>
          <div class="org-type"><?= htmlspecialchars($t['tenantType'] ?? 'ORGANISATION') ?></div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="btn">Connect →</button>
  </form>

  <a href="<?= Bootstrap::url('/login.php') ?>" class="cancel">Cancel</a>
</div>
</body>
</html>

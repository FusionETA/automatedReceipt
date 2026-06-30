<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Bootstrap;
use App\Auth\Auth;
use App\Xero\UserTokenStorage;
use App\Xero\OrgStorage;

Bootstrap::init();
Auth::requireLogin();

$userId   = Auth::userId();
$tenantId = $_GET['tenant'] ?? Auth::activeTenantId();
$allTokens = UserTokenStorage::getAll($userId);

// Validate tenant belongs to this user
if (!isset($allTokens[$tenantId])) {
    header('Location: ' . Bootstrap::url('/index.php'));
    exit;
}

$tenantName = $allTokens[$tenantId]['tenant_name'] ?? 'this organisation';
$orgCount    = count($allTokens);
$isLastOrg   = $orgCount === 1;

// ── GET — show confirmation ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Disconnect — <?= htmlspecialchars($tenantName) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; height: 100vh; }
  .card { background: #fff; border-radius: 10px; padding: 40px 48px; text-align: center; border: 1px solid #e5e5e5; max-width: 400px; width: 100%; }
  h2 { font-size: 18px; margin-bottom: 10px; color: #111; }
  p { color: #777; font-size: 14px; margin-bottom: 8px; line-height: 1.5; }
  .warn { color: #dc2626; font-size: 13px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 10px 14px; margin-bottom: 24px; }
  .btns { display: flex; gap: 12px; justify-content: center; }
  .btn { padding: 10px 28px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; border: none; font-family: inherit; }
  .btn-cancel { background: #f0f0f0; color: #333; }
  .btn-confirm { background: #ef4444; color: #fff; }
  .btn-cancel:hover { background: #e0e0e0; }
  .btn-confirm:hover { background: #dc2626; }
</style>
</head>
<body>
  <div class="card">
    <h2>Disconnect from Xero?</h2>
    <p>You are about to disconnect <strong><?= htmlspecialchars($tenantName) ?></strong>.</p>

    <?php if ($isLastOrg): ?>
      <p class="warn" style="margin-top:12px">
        This is your only connected organisation. Disconnecting will sign you out.
      </p>
    <?php else: ?>
      <p style="color:#888;font-size:13px;margin-top:8px;margin-bottom:24px">
        You have <?= $orgCount - 1 ?> other organisation<?= $orgCount - 1 !== 1 ? 's' : '' ?> connected.
        Those will remain active.
      </p>
    <?php endif; ?>

    <div class="btns">
      <a href="<?= Bootstrap::url('/index.php') ?>" class="btn btn-cancel">Cancel</a>
      <form method="POST" action="<?= Bootstrap::url('/disconnect.php') ?>" style="display:inline">
        <input type="hidden" name="tenant_id" value="<?= htmlspecialchars($tenantId) ?>">
        <button type="submit" class="btn btn-confirm">Yes, Disconnect</button>
      </form>
    </div>
  </div>
</body>
</html>
<?php
    exit;
}

// ── POST — execute disconnect ─────────────────────────────────────────
$tenantId = $_POST['tenant_id'] ?? $tenantId;

UserTokenStorage::remove($userId, $tenantId);

// Check if org has any other users connected
$otherUserHasOrg = UserTokenStorage::findUserIdForOrg($tenantId) !== null;

// If no user has this org anymore, we could clean up org data
// For now, we keep receipts and events (audit trail)
// OrgStorage::destroy($tenantId); // uncomment to hard-delete

$remaining = UserTokenStorage::getAll($userId);

if (empty($remaining)) {
    // No orgs left — log out completely
    Auth::logout();
    header('Location: ' . Bootstrap::url('/login.php'));
    exit;
}

$firstRemaining = array_key_first($remaining);
Auth::setActiveTenant($firstRemaining);

header('Location: ' . Bootstrap::url('/index.php') . '?disconnected=1');
exit;

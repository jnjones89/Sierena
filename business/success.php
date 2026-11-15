<?php
/*
 * Stripe redirects here after payment. We confirm the session with Stripe
 * (never trust the URL alone), create the license, and show the key.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

$sessionId = (string) ($_GET['session_id'] ?? '');
$license   = null;
$pending   = false;
$error     = '';

if (!preg_match('/^cs_[A-Za-z0-9_]{10,255}$/', $sessionId)) {
    $error = 'Missing or invalid checkout reference.';
} else {
    try {
        $session = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
        $license = fulfill_checkout_session($session);
        if (!$license) {
            $pending = true; // e.g. bank payment still processing
        }
    } catch (Throwable $e) {
        error_log('[SWA success] ' . $e->getMessage());
        $error = 'We could not confirm your payment yet. Refresh this page in a moment — if it keeps failing, reply to your Stripe receipt email and we will sort it out.';
    }
}

render_header('Payment confirmed');
?>
<?php if ($license): ?>
  <h1>You're on Business 🎉</h1>
  <p class="lead">Payment confirmed for <?= h($license['company'] ?: $license['email']) ?>. Here is your license key — we've also emailed it to <?= h($license['email']) ?>.</p>

  <section class="card">
    <h2>Your license key</h2>
    <div class="key" id="licenseKey"><?= h($license['license_key']) ?></div>
    <button class="btn primary" type="button" data-copy="licenseKey">Copy key</button>
    <p class="muted small">Keep it private. Share it only with teammates who should sync audits to your company portal.</p>
  </section>

  <section class="card">
    <h2>Next steps</h2>
    <ol class="steps">
      <li>Open the <b>Workflow Shadow Audit</b> extension, paste the key in the unlock box and click <b>Unlock</b>.</li>
      <li>Give the same key to each teammate you want audited — they paste it into their extension too.</li>
      <li>After a few days of shadowing, click <b>Sync to Business Portal</b> in each extension.</li>
      <li><a href="portal.php">Sign in to your Business Portal</a> with your email and key, and fill in your business profile (social media, tools, goals).</li>
    </ol>
    <a class="btn primary" href="portal.php">Open Business Portal →</a>
  </section>
<?php elseif ($pending): ?>
  <h1>Payment processing…</h1>
  <p class="lead">Stripe hasn't confirmed your payment yet (this can take a few minutes for some payment methods). Your key will be emailed as soon as it clears, or refresh this page.</p>
<?php else: ?>
  <h1>Something went wrong</h1>
  <div class="alert error"><?= h($error) ?></div>
  <a class="btn" href="./">Back</a>
<?php endif; ?>
<?php render_footer();

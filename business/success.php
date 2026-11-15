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
  <h1>Payment confirmed 🎉</h1>
  <p class="lead"><?= h(plan_name($license)) ?> for <?= h($license['company'] ?: $license['email']) ?> — up to <?= (int) $license['seats'] ?> people, open until <?= h(format_date($license['access_until'])) ?>. We've also emailed this key to <?= h($license['email']) ?>.</p>

  <section class="card">
    <h2>Your team key</h2>
    <div class="key" id="licenseKey"><?= h($license['license_key']) ?></div>
    <button class="btn primary" type="button" data-copy="licenseKey">Copy key</button>
    <p class="muted small">Share it only with the teammates you want audited. Each person's browser uses one of your <?= (int) $license['seats'] ?> seats.</p>
  </section>

  <section class="card">
    <h2>Next steps</h2>
    <ol class="steps">
      <li>Each person installs the <b>Workflow Shadow Audit</b> extension, clicks <b>Have a team key?</b>, pastes the key and turns tracking on.</li>
      <li>Everyone works normally for a few days.</li>
      <li>Each person clicks <b>Send to my Business Portal</b>.</li>
      <li><a href="portal.php">Sign in to your Business Portal</a> with your email and key, fill in your business profile and the Back-Office Check, then get your instant AI report. Our team follows up with your final plan.</li>
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

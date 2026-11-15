<?php
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

$canceled = isset($_GET['canceled']);
$error    = (string) ($_GET['error'] ?? '');
$prefill  = filter_var((string) ($_GET['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';

render_header('Upgrade to Business');
?>
<h1>Workflow Shadow Audit <span class="badge">Business</span></h1>
<p class="lead">See exactly where your team's time and software budget go — then get a ranked plan of the AI agents that close the gaps.</p>

<?php if ($canceled): ?>
  <div class="alert error">Checkout was canceled. No charge was made — you can try again any time.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert error"><?= h($error) ?></div>
<?php endif; ?>

<div class="grid">
  <section class="card plan">
    <h2>Lite <span class="price">Free</span></h2>
    <ul>
      <li>One free audit: 5 hours of your work</li>
      <li>Your most repeated task</li>
      <li>The AI agent that can do it for you</li>
      <li>A simple step-by-step fix</li>
      <li>One person, one browser</li>
    </ul>
    <p class="muted small">Install the Chrome extension and start today.</p>
  </section>

  <section class="card plan featured">
    <h2>Business <span class="price"><?= h((string) cfg('price_label')) ?></span></h2>
    <ul>
      <li>Everything in Lite, with no time limit</li>
      <li>One key for your whole team</li>
      <li>One-click sync of every audit to your company portal</li>
      <li>Business profile: social media, tools, departments, goals</li>
      <li>Team-wide AI Agent Gap Map &amp; 30-day roadmap prompt</li>
      <li>Webhook delivery to Make.com / n8n</li>
      <li>Reviewed by the <?= h((string) cfg('brand_name')) ?> team</li>
    </ul>

    <form method="post" action="checkout.php">
      <label>Work email
        <input type="email" name="email" required maxlength="255" value="<?= h($prefill) ?>" placeholder="you@company.com">
      </label>
      <label>Company name
        <input type="text" name="company" required maxlength="200" placeholder="Acme Inc.">
      </label>
      <button class="btn primary block" type="submit">Continue to secure checkout →</button>
    </form>
    <p class="muted small">You'll be sent to Stripe to pay. Your license key appears right after payment and is emailed to you.</p>
  </section>
</div>

<p class="muted small">Already a customer? <a href="portal.php">Sign in to your Business Portal</a>.</p>
<?php render_footer();

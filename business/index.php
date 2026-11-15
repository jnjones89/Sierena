<?php
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

$canceled = isset($_GET['canceled']);
$error    = (string) ($_GET['error'] ?? '');
$prefill  = filter_var((string) ($_GET['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
$selected = (string) ($_GET['plan'] ?? 'team');
$plans    = (array) cfg('plans');
if (!isset($plans[$selected])) {
    $selected = (string) array_key_first($plans);
}
$monitoring = (array) cfg('monitoring');
$industry   = (string) ($_GET['industry'] ?? '');
$days       = (int) cfg('audit_access_days');

render_header('Team Audit');
?>
<h1>Find the bottlenecks across your whole team</h1>
<p class="lead">Everyone runs the private, read-only audit for a few days. You get one report: your biggest bottlenecks,
the repetitive tasks behind them, the AI agents you need, and a step-by-step plan to fix them.</p>

<?php if ($canceled): ?>
  <div class="alert error">Checkout was canceled. No charge was made.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post" action="checkout.php">
  <div class="grid">
    <?php foreach ($plans as $key => $plan): ?>
      <label class="card plan selectable<?= $key === $selected ? ' featured' : '' ?>">
        <input type="radio" name="plan" value="<?= h($key) ?>" <?= $key === $selected ? 'checked' : '' ?>>
        <h2><?= h($plan['name']) ?> <span class="price"><?= h($plan['price_label']) ?></span></h2>
        <p class="muted small">One-time payment · up to <?= (int) $plan['people'] ?> people</p>
        <ul>
          <li>Browser audit for up to <?= (int) $plan['people'] ?> people</li>
          <li>Back-Office Check for your industry</li>
          <li>Instant AI report, plus a final plan from our team</li>
          <li><?= $days ?> days to run the audit</li>
        </ul>
      </label>
    <?php endforeach; ?>
  </div>

  <section class="card" style="max-width:560px">
    <h2>Your details</h2>
    <label>Work email
      <input type="email" name="email" required maxlength="255" value="<?= h($prefill) ?>" placeholder="you@company.com">
    </label>
    <label>Company name
      <input type="text" name="company" required maxlength="200" placeholder="Acme Inc.">
    </label>
    <button class="btn primary block" type="submit">Continue to secure checkout →</button>
    <p class="muted small">You'll pay on Stripe. Your team key appears right after payment and is emailed to you.
      If we build your agents, the audit price is credited toward the build.</p>
  </section>
</form>

<section class="card">
  <h2>What every Team Audit includes</h2>
  <ul class="steps">
    <li><b>Browser audit</b> — each person runs the private, read-only extension for a few days.</li>
    <li><b>Back-Office Check</b> — your processes and SOPs, follow-up, contracts, compliance and data security, set up the way <?= h((string) cfg('brand_name')) ?> would set up your back office.</li>
    <li><b>Instant AI report</b> — your bottlenecks, the AI agents you need, a step-by-step fix and what it is costing you.</li>
    <li><b>Final plan from our team</b> — reviewed by a person, with the SOPs to write first. If we build your agents, the audit price is credited toward the build.</li>
  </ul>
  <h3>Every industry is checked differently</h3>
  <div class="grid industry-grid">
    <?php foreach (industry_highlights() as $key => $checks): ?>
      <div class="card<?= $key === $industry ? ' featured' : '' ?>" style="margin:0">
        <b><?= h(industry_label($key)) ?></b>
        <ul class="small"><?php foreach ($checks as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="disclaimer">Compliance checks are general guidance for your industry, not legal advice.</p>
</section>

<section class="card">
  <h2>After the audit: <?= h((string) $monitoring['name']) ?> <span class="badge"><?= h((string) $monitoring['price_label']) ?></span></h2>
  <p class="muted">Optional. Keep the audit running every month after your agents are built, and see what improved:
    hours tracked, repeated tasks per hour and tab-jumping, month by month. Turn it on from your Business Portal; cancel any time.</p>
</section>

<section class="card">
  <h2>Just you? Run a free audit</h2>
  <p class="muted">The free Chrome extension runs one 5-hour audit and shows your #1 repetitive task, the AI agent that can do it,
    and a step-by-step fix.</p>
</section>

<p class="muted small">Already a customer? <a href="portal.php">Sign in to your Business Portal</a>.</p>
<script>
document.querySelectorAll('.selectable input[type=radio]').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelectorAll('.selectable').forEach(function (c) { c.classList.remove('featured'); });
    r.closest('.selectable').classList.add('featured');
  });
});
</script>
<?php render_footer();

<?php
/* Public help page (linked from the extension). */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

render_header('Help');
?>
  <h1>Help</h1>
  <p class="lead">Quick answers about the Workflow Shadow Audit extension.</p>
  <section class="card">
    <?php include __DIR__ . '/includes/faq.php'; ?>
  </section>
  <section class="card">
    <h2>Still have a question?</h2>
    <div class="actions">
      <a class="btn primary" href="<?= h((string) cfg('booking_url')) ?>" target="_blank" rel="noopener">Book a call</a>
      <span class="muted">or email <b><?= h((string) cfg('owner_email')) ?></b></span>
    </div>
  </section>
<?php
render_footer();

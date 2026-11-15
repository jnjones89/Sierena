<?php
/*
 * Team invite page. The owner shares join.php?k=TEAM-KEY with their team.
 * Shows a teammate exactly how to connect their browser to the company's Team Audit.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

$key     = normalize_key((string) ($_GET['k'] ?? ''));
$license = $key !== '' ? find_license_by_key($key) : null;
$ok      = license_is_active($license);
$company = $license ? ($license['company'] ?: 'your company') : '';
$extUrl  = (string) cfg_opt('extension_url', '');

render_header('Join your team');

if (!$ok):
?>
  <h1>This invite link isn't working</h1>
  <p class="lead">It may have been typed wrong, or the audit has ended. Ask the person who sent it for a new link.</p>
<?php
    render_footer();
    exit;
endif;
?>
  <h1>Join <?= h($company) ?>'s Team Audit</h1>
  <p class="lead">It takes about 2 minutes. After that, just work like normal.</p>

  <section class="card">
    <div class="step"><div class="num">1</div><div class="body">
      <h2>Add the extension to Chrome</h2>
      <p>Install <b>Workflow Shadow Audit</b><?= $extUrl ? '' : ' (your manager will send you the link)' ?>.</p>
    </div>
    <?php if ($extUrl): ?><div class="go"><a class="btn primary" href="<?= h($extUrl) ?>" target="_blank" rel="noopener">Add to Chrome</a></div><?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="step"><div class="num">2</div><div class="body">
      <h2>Paste your team key</h2>
      <p>Click the extension icon, then <b>Have a team key?</b>, and paste this:</p>
    </div></div>
    <div class="key" id="teamKey"><?= h($license['license_key']) ?></div>
    <button class="btn primary" type="button" data-copy="teamKey">Copy team key</button>
  </section>

  <section class="card">
    <div class="step"><div class="num">3</div><div class="body">
      <h2>Turn tracking on and work like normal</h2>
      <p>After a few days, click <b>Send to my Business Portal</b> in the extension. That's it.</p>
    </div></div>
  </section>

  <p class="note">We only see website names and time spent. Never what you type, read or click.</p>
<?php
render_footer();

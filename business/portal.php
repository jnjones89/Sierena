<?php
/*
 * Business Portal — customers sign in with email + team key.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';
require __DIR__ . '/includes/report.php';

start_session();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function flash_redirect(string $msg, string $kind = 'ok', string $anchor = ''): never
{
    $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
    redirect(site_url('portal.php') . ($anchor ? '#' . $anchor : ''));
}

$license = isset($_SESSION['license_id']) ? find_license_by_id((int) $_SESSION['license_id']) : null;
$active  = license_is_active($license);

/* ---------------------------- Actions ---------------------------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        if (!rate_limit('login', 10, 600)) {
            flash_redirect('Too many sign-in attempts. Please wait 10 minutes.', 'error');
        }
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $found = find_license_by_key((string) ($_POST['key'] ?? ''));
        if (!$found || !hash_equals(strtolower($found['email']), $email)) {
            flash_redirect('Email and key do not match.', 'error');
        }
        session_regenerate_id(true);
        $_SESSION['license_id'] = (int) $found['id'];
        flash_redirect('Welcome back' . ($found['company'] ? ', ' . $found['company'] : '') . '.');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        flash_redirect('Signed out.');
    }

    if (!$license) {
        flash_redirect('Please sign in.', 'error');
    }
    if (!$active) {
        flash_redirect('Your audit window has ended. Turn on Results Tracking below to keep going.', 'error');
    }
    $lid = (int) $license['id'];

    if ($action === 'save_profile') {
        $saved = save_profile($lid, $_POST);
        send_mail(
            (string) cfg('owner_email'),
            'Business profile updated: ' . ($saved['company'] ?: $license['email']),
            "A customer updated their business profile.\n\nCompany: {$saved['company']}\nEmail: {$license['email']}\n\nReview it: "
            . site_url('admin.php?view=' . $lid) . "\n"
        );
        flash_redirect('Business profile saved.', 'ok', 'profile');
    }

    if ($action === 'save_checklist') {
        $industry = load_profile($lid)['industry'] ?: 'other';
        $answers  = save_checklist($lid, $industry, $_POST);
        $summary  = checklist_summary($industry, $answers);
        flash_redirect('Back-Office Check saved: ' . $summary['answered'] . ' of ' . $summary['total'] . ' answered.', 'ok', 'backoffice');
    }

    if ($action === 'upload_audit') {
        $file = $_FILES['audit_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] > 2_000_000) {
            flash_redirect('Please choose an exported audit file (max 2 MB).', 'error', 'team');
        }
        $clean = sanitize_audit_payload(json_decode((string) file_get_contents($file['tmp_name']), true));
        if ($clean === null) {
            flash_redirect('That file is not a Workflow Shadow Audit export.', 'error', 'team');
        }
        store_audit($lid, $clean);
        flash_redirect('Audit uploaded (' . $clean['auditMetadata']['roleTag'] . ').', 'ok', 'team');
    }

    if ($action === 'delete_audit') {
        db()->prepare('DELETE FROM audits WHERE id = ? AND license_id = ?')->execute([(int) ($_POST['audit_id'] ?? 0), $lid]);
        flash_redirect('Audit deleted.', 'ok', 'team');
    }

    if ($action === 'generate_report') {
        if (!ai_reports_enabled()) {
            flash_redirect('The instant AI report is not available right now. Our team will send your final plan.', 'error', 'report');
        }
        if (!list_audits($lid)) {
            flash_redirect('Sync at least one audit first.', 'error', 'report');
        }
        if (reports_today($lid) >= (int) cfg('reports_per_day')) {
            flash_redirect('You have reached today\'s report limit. Try again tomorrow.', 'error', 'report');
        }
        // Writing the report can take a minute or two.
        @set_time_limit(300);
        ignore_user_abort(true);
        session_write_close();
        try {
            $prompt = build_business_prompt($license, load_profile($lid), aggregate_audits(list_audits($lid)), load_checklist($lid));
            $result = generate_ai_report($prompt);
            save_report($lid, 'ai', $result['text'], $result['model']);
        } catch (Throwable $e) {
            error_log('[SWA report] ' . $e->getMessage());
            start_session();
            flash_redirect('We could not write your instant AI report right now. Please try again in a few minutes.', 'error', 'report');
        }
        send_mail(
            (string) cfg('owner_email'),
            'Instant report generated: ' . ($license['company'] ?: $license['email']),
            "{$license['company']} ({$license['email']}) just generated their instant AI report.\n"
            . "Follow up with the full plan here: " . site_url('admin.php?view=' . $lid) . "\n"
        );
        start_session();
        flash_redirect('Your instant AI report is ready.', 'ok', 'report');
    }

    flash_redirect('Unknown action.', 'error');
}

/* ----------------------------- View ------------------------------ */

render_header('Business Portal');

if ($flash) {
    echo '<div class="alert ' . ($flash['kind'] === 'error' ? 'error' : 'ok') . '">' . h($flash['msg']) . '</div>';
}

if (!$license):
?>
  <h1>Business Portal</h1>
  <p class="lead">Sign in with the email you paid with and your team key.</p>
  <section class="card" style="max-width:480px">
    <form method="post" action="portal.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label>Email <input type="email" name="email" required autocomplete="email"></label>
      <label>Team key <input type="text" name="key" required placeholder="AUDIT-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false"></label>
      <button class="btn primary block" type="submit">Sign in</button>
    </form>
    <p class="muted small">No key yet? <a href="./">Get a Team Audit</a>.</p>
  </section>
<?php
    render_footer();
    exit;
endif;

$lid        = (int) $license['id'];
$profile    = load_profile($lid);
$audits     = list_audits($lid);
$aggregate  = aggregate_audits($audits);
$aiReport   = latest_report($lid, 'ai');
$finalPlan  = latest_report($lid, 'final');
$monitoring = license_monitoring($license);
$monCfg     = (array) cfg('monitoring');
$used       = seats_used($lid);
$industry   = $profile['industry'] ?: 'other';
$checklist  = load_checklist($lid);
$summary    = checklist_summary($industry, $checklist);
?>
  <div class="actions" style="justify-content:space-between">
    <h1 style="margin:0"><?= h($profile['company'] ?: ($license['company'] ?: 'Your company')) ?></h1>
    <div class="actions">
      <?php if (!empty($license['stripe_customer_id'])): ?>
        <form class="inline" method="post" action="billing.php"><?= csrf_field() ?><button class="btn" type="submit">Billing &amp; invoices</button></form>
      <?php endif; ?>
      <form class="inline" method="post" action="portal.php"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn" type="submit">Sign out</button></form>
    </div>
  </div>

  <div class="stats" style="margin-top:14px">
    <div class="stat"><b><?= h(plan_name($license)) ?></b><span>Plan</span></div>
    <div class="stat"><b><?= $used ?> / <?= (int) $license['seats'] ?></b><span>People connected</span></div>
    <div class="stat"><b><?= $monitoring ? 'Monthly' : h(format_date($license['access_until'])) ?></b><span><?= $monitoring ? 'Results Tracking on' : 'Audit open until' ?></span></div>
    <div class="stat"><b><?= $active ? '<span class="badge green">Active</span>' : '<span class="badge red">Ended</span>' ?></b><span>Status</span></div>
  </div>
  <p class="muted small">Team key: <code><?= h($license['license_key']) ?></code> — share it with each person you want audited.</p>

  <?php if (!$active): ?>
    <div class="alert error">Your audit window has ended. Your data and reports are still here. Turn on <b><?= h((string) $monCfg['name']) ?></b> below to keep auditing.</div>
  <?php endif; ?>

  <section class="card" id="report">
    <h2>Your report</h2>
    <?php if ($finalPlan): ?>
      <div class="alert ok">Your plan from the <?= h((string) cfg('brand_name')) ?> team is ready (<?= h(format_date($finalPlan['created_at'])) ?>).</div>
      <?= render_markdown($finalPlan['content']) ?>
      <hr class="sep">
    <?php endif; ?>

    <?php if ($aiReport): ?>
      <h3 class="muted small" style="text-transform:uppercase;letter-spacing:.06em">Instant AI report · <?= h(format_date($aiReport['created_at'])) ?></h3>
      <?= render_markdown($aiReport['content']) ?>
    <?php elseif (!$audits): ?>
      <p class="muted">Your report appears here once your team has sent in their audits. In the meantime, fill in your business profile and the Back-Office Check below.</p>
    <?php else: ?>
      <p class="muted">Your team's data is in. Get your instant AI report now. Our team follows up with your final plan.</p>
    <?php endif; ?>

    <div class="actions" style="margin-top:12px">
      <?php if ($audits && $active && ai_reports_enabled()): ?>
        <form class="inline" method="post" action="portal.php" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Writing your instant AI report… (1–2 minutes)';">
          <?= csrf_field() ?><input type="hidden" name="action" value="generate_report">
          <button class="btn primary" type="submit"><?= $aiReport ? 'Update my instant AI report' : 'Get my instant AI report' ?></button>
        </form>
      <?php endif; ?>
      <a class="btn" href="<?= h((string) cfg('booking_url')) ?>" target="_blank" rel="noopener">Book a call to build these agents →</a>
    </div>
    <p class="muted small">If we build your agents, your audit price is credited toward the build.</p>

    <details class="more-box">
      <summary class="muted small">Copy the report prompt for ChatGPT or Claude</summary>
      <?php render_prompt_box(build_business_prompt($license, $profile, $aggregate, $checklist)); ?>
    </details>
  </section>

  <section class="card" id="profile">
    <h2>Business profile</h2>
    <p class="muted small">Tell us about your channels, team and tools. It's combined with your team's audits to find the gaps.</p>
    <?php render_profile_form($profile, $active); ?>
  </section>

  <section class="card" id="backoffice">
    <h2>Back-Office Check<?= $profile['industry'] ? ' · ' . h(industry_label($industry)) : '' ?></h2>
    <p class="muted small">Your processes, SOPs, follow-up, contracts, compliance and data security — the way
      <?= h((string) cfg('brand_name')) ?> would set up your back office. Answer honestly; this stays private to you and our team.</p>
    <?php if (!$profile['industry']): ?>
      <div class="alert error">Choose your industry in your business profile above to add the checks for your industry.</div>
    <?php endif; ?>
    <?php render_checklist_summary($summary); ?>
    <p class="disclaimer">Compliance items are general guidance for your industry, not legal advice. Confirm the requirements that apply to you with your attorney or compliance advisor.</p>
    <details class="more-box"<?= $summary['answered'] === 0 ? ' open' : '' ?>>
      <summary><b><?= $summary['answered'] === 0 ? 'Start the Back-Office Check' : 'Edit your answers' ?></b> <span class="muted small">(about 5 minutes)</span></summary>
      <?php render_checklist_form($industry, $checklist, $active); ?>
    </details>
  </section>

  <section class="card" id="team">
    <h2>Team audit data</h2>
    <?php render_telemetry($aggregate, $audits, $active); ?>
    <?php if ($active): ?>
      <form method="post" action="portal.php" enctype="multipart/form-data" style="margin-top:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_audit">
        <label>Upload an exported audit file
          <input type="file" name="audit_file" accept="application/json,.json" required>
        </label>
        <button class="btn" type="submit">Upload</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="card" id="tracking">
    <h2><?= h((string) $monCfg['name']) ?> <span class="badge"><?= h((string) $monCfg['price_label']) ?></span></h2>
    <?php if ($monitoring): ?>
      <p class="muted">On. Have your team send in their audits each month and compare below. Manage or cancel under <b>Billing &amp; invoices</b>.</p>
    <?php else: ?>
      <p class="muted">After your agents are built, keep auditing every month and see what improved. Your team key keeps working while this is on.</p>
      <form method="post" action="monitor.php"><?= csrf_field() ?><button class="btn primary" type="submit">Turn on <?= h((string) $monCfg['name']) ?></button></form>
    <?php endif; ?>
    <?php render_progress(progress_by_month($audits)); ?>
  </section>
<?php
render_footer();

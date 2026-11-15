<?php
/*
 * Business Portal — customers sign in with email + license key.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';
require __DIR__ . '/includes/report.php';

start_session();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function flash_redirect(string $msg, string $kind = 'ok'): never
{
    $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
    redirect(site_url('portal.php'));
}

$license = isset($_SESSION['license_id']) ? find_license_by_id((int) $_SESSION['license_id']) : null;
if ($license && !license_is_active($license)) {
    $inactive = true;
} else {
    $inactive = false;
}

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
            flash_redirect('Email and license key do not match.', 'error');
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
    if ($inactive) {
        flash_redirect('Your subscription is inactive. Renew to make changes.', 'error');
    }
    $lid = (int) $license['id'];

    if ($action === 'save_profile') {
        $saved = save_profile($lid, $_POST);
        send_mail(
            (string) cfg('owner_email'),
            'Business profile updated: ' . ($saved['company'] ?: $license['email']),
            "A Business customer updated their profile.\n\nCompany: {$saved['company']}\nEmail: {$license['email']}\n\nReview it: "
            . site_url('admin.php?view=' . $lid) . "\n"
        );
        flash_redirect('Business profile saved.');
    }

    if ($action === 'upload_audit') {
        $file = $_FILES['audit_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] > 2_000_000) {
            flash_redirect('Please choose an exported audit JSON file (max 2 MB).', 'error');
        }
        $clean = sanitize_audit_payload(json_decode((string) file_get_contents($file['tmp_name']), true));
        if ($clean === null) {
            flash_redirect('That file is not a Workflow Shadow Audit export.', 'error');
        }
        store_audit($lid, $clean);
        flash_redirect('Audit uploaded (' . $clean['auditMetadata']['roleTag'] . ').');
    }

    if ($action === 'delete_audit') {
        db()->prepare('DELETE FROM audits WHERE id = ? AND license_id = ?')->execute([(int) ($_POST['audit_id'] ?? 0), $lid]);
        flash_redirect('Audit deleted.');
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
  <p class="lead">Sign in with the email you paid with and your license key.</p>
  <section class="card" style="max-width:480px">
    <form method="post" action="portal.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label>Email <input type="email" name="email" required autocomplete="email"></label>
      <label>License key <input type="text" name="key" required placeholder="AUDIT-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false"></label>
      <button class="btn primary block" type="submit">Sign in</button>
    </form>
    <p class="muted small">No key yet? <a href="./">Upgrade to Business</a>.</p>
  </section>
<?php
    render_footer();
    exit;
endif;

$lid       = (int) $license['id'];
$profile   = load_profile($lid);
$audits    = list_audits($lid);
$aggregate = aggregate_audits($audits);
?>
  <div class="actions" style="justify-content:space-between">
    <h1 style="margin:0"><?= h($profile['company'] ?: ($license['company'] ?: 'Your company')) ?></h1>
    <div class="actions">
      <?php if (!empty($license['stripe_customer_id'])): ?>
        <form class="inline" method="post" action="billing.php"><?= csrf_field() ?><button class="btn" type="submit">Manage billing</button></form>
      <?php endif; ?>
      <form class="inline" method="post" action="portal.php"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn" type="submit">Sign out</button></form>
    </div>
  </div>
  <p class="muted">License <code><?= h($license['license_key']) ?></code> ·
    <?= $inactive ? '<span class="badge red">Inactive</span>' : '<span class="badge green">Active</span>' ?></p>

  <?php if ($inactive): ?>
    <div class="alert error">Your subscription is inactive, so syncing and edits are paused. Use <b>Manage billing</b> to renew.</div>
  <?php endif; ?>

  <section class="card">
    <h2>1 · Business profile</h2>
    <p class="muted small">Tell us about your channels, team and tools. This is combined with your team's audit data to find the gaps.</p>
    <?php render_profile_form($profile); ?>
  </section>

  <section class="card">
    <h2>2 · Team audit data</h2>
    <?php render_telemetry($aggregate, $audits, !$inactive); ?>
    <form method="post" action="portal.php" enctype="multipart/form-data" style="margin-top:14px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_audit">
      <label>Upload an exported audit JSON
        <input type="file" name="audit_file" accept="application/json,.json" required>
      </label>
      <button class="btn" type="submit">Upload</button>
    </form>
  </section>

  <section class="card">
    <h2>3 · AI Agent Gap Analysis</h2>
    <p class="muted small">Combines your profile and every synced audit into one executive prompt: SaaS consolidation, payroll waste, the AI agents you should build, and a 30-day roadmap.</p>
    <?php render_prompt_box(build_business_prompt($license, $profile, $aggregate)); ?>
  </section>
<?php
render_footer();

<?php
/*
 * Owner dashboard — see every Business customer, their profile, team telemetry
 * and the ready-made report prompt. Also lists free audit leads.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';
require __DIR__ . '/includes/report.php';

start_session();

$password = (string) cfg('admin_password');
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect(site_url('admin.php'));
    }
    if (!empty($_SESSION['is_admin']) && in_array($_POST['action'] ?? '', ['save_final', 'free_seats', 'extend'], true)) {
        $lid = (int) ($_POST['license_id'] ?? 0);
        $lic = find_license_by_id($lid);
        if ($lic) {
            $act = (string) $_POST['action'];
            if ($act === 'save_final') {
                $content = trim((string) ($_POST['content'] ?? ''));
                if ($content !== '') {
                    save_report($lid, 'final', mb_substr($content, 0, 60000), 'team');
                    if (!empty($_POST['notify'])) {
                        send_mail(
                            $lic['email'],
                            'Your plan from ' . cfg('brand_name') . ' is ready',
                            "Your full AI agent plan is ready in your Business Portal:\n\n    " . site_url('portal.php')
                            . "\n\nWhen you're ready to build it, book a call: " . cfg('booking_url') . "\n\n— " . cfg('brand_name') . "\n"
                        );
                    }
                }
            } elseif ($act === 'free_seats') {
                db()->prepare('DELETE FROM devices WHERE license_id = ?')->execute([$lid]);
            } elseif ($act === 'extend') {
                $base = max(time(), (int) strtotime((string) $lic['access_until']));
                db()->prepare('UPDATE licenses SET access_until = ?, updated_at = ? WHERE id = ?')
                    ->execute([gmdate('Y-m-d\TH:i:s\Z', $base + 30 * 86400), now_iso(), $lid]);
            }
        }
        redirect(site_url('admin.php?view=' . $lid));
    }
    if (($_POST['action'] ?? '') === 'login') {
        if (!rate_limit('admin_login', 5, 900)) {
            $error = 'Too many attempts. Wait 15 minutes.';
        } elseif (strlen($password) < 12 || str_contains($password, 'REPLACE')) {
            $error = 'Set a real admin_password (12+ characters) in your config file first.';
        } elseif (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            redirect(site_url('admin.php'));
        } else {
            $error = 'Wrong password.';
        }
    }
}

render_header('Admin');

if (empty($_SESSION['is_admin'])):
?>
  <h1>Admin</h1>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
  <section class="card" style="max-width:420px">
    <form method="post" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label>Admin password <input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn primary block" type="submit">Sign in</button>
    </form>
  </section>
<?php
    render_footer();
    exit;
endif;

$logout = '<form class="inline" method="post" action="admin.php">' . csrf_field()
    . '<input type="hidden" name="action" value="logout"><button class="btn" type="submit">Sign out</button></form>';

/* ---------- Single customer view ---------- */
if (isset($_GET['view'])) {
    $license = find_license_by_id((int) $_GET['view']);
    if (!$license) {
        echo '<div class="alert error">Customer not found.</div><a class="btn" href="admin.php">Back</a>';
        render_footer();
        exit;
    }
    $profile   = load_profile((int) $license['id']);
    $audits    = list_audits((int) $license['id']);
    $aggregate = aggregate_audits($audits);
    ?>
    <div class="actions" style="justify-content:space-between">
      <h1 style="margin:0"><?= h($profile['company'] ?: ($license['company'] ?: $license['email'])) ?></h1>
      <div class="actions"><a class="btn" href="admin.php">← All customers</a><?= $logout ?></div>
    </div>
    <?php
      $lid      = (int) $license['id'];
      $devices  = list_devices($lid);
      $aiReport = latest_report($lid, 'ai');
      $final    = latest_report($lid, 'final');
      $hidden   = csrf_field() . '<input type="hidden" name="license_id" value="' . $lid . '">';
    ?>
    <p class="muted"><?= h($license['email']) ?> · <code><?= h($license['license_key']) ?></code> ·
      <?= license_is_active($license) ? '<span class="badge green">Active</span>' : '<span class="badge red">Ended</span>' ?></p>
    <div class="stats">
      <div class="stat"><b><?= h(plan_name($license)) ?></b><span>Plan</span></div>
      <div class="stat"><b><?= count($devices) ?> / <?= (int) $license['seats'] ?></b><span>Seats used</span></div>
      <div class="stat"><b><?= h(format_date($license['access_until'])) ?></b><span>Audit open until</span></div>
      <div class="stat"><b><?= license_monitoring($license) ? 'On' : h($license['monitor_status'] === 'none' ? 'Off' : $license['monitor_status']) ?></b><span>Results Tracking</span></div>
    </div>
    <div class="actions" style="margin-bottom:18px">
      <form class="inline" method="post" action="admin.php"><?= $hidden ?><input type="hidden" name="action" value="extend"><button class="btn" type="submit">Extend audit +30 days</button></form>
      <form class="inline" method="post" action="admin.php" onsubmit="return confirm('Disconnect every browser from this key? Teammates re-connect by entering the key again.')"><?= $hidden ?><input type="hidden" name="action" value="free_seats"><button class="btn danger" type="submit">Free all seats</button></form>
    </div>

    <section class="card">
      <h2>Final plan for the customer</h2>
      <p class="muted small">Write or paste the full plan (Markdown: ## headings, - bullets, 1. steps, | tables |). It appears at the top of their portal.</p>
      <form method="post" action="admin.php">
        <?= $hidden ?><input type="hidden" name="action" value="save_final">
        <textarea name="content" style="min-height:260px" placeholder="## Your AI agent plan&#10;&#10;1. ..."><?= h($final['content'] ?? ($aiReport['content'] ?? '')) ?></textarea>
        <label style="margin-top:10px"><input type="checkbox" name="notify" value="1" checked> Email the customer that their plan is ready</label>
        <button class="btn primary" type="submit"><?= $final ? 'Update final plan' : 'Publish final plan' ?></button>
      </form>
      <?php if ($final): ?><p class="muted small">Last published <?= h(format_date($final['created_at'])) ?>.</p><?php endif; ?>
    </section>

    <section class="card">
      <h2>Instant AI report</h2>
      <?= $aiReport ? '<p class="muted small">Generated ' . h(format_date($aiReport['created_at'])) . ' · ' . h($aiReport['model']) . '</p>' . render_markdown($aiReport['content']) : '<p class="muted">The customer has not generated one yet.</p>' ?>
    </section>

    <section class="card"><h2>Business profile</h2><?php render_profile_readonly($profile); ?></section>
    <?php $ts = tools_summary(load_tools($lid), $aggregate); ?>
    <section class="card"><h2>Software they pay for</h2>
      <?php if (!$ts['rows']): ?><p class="muted">Not listed yet.</p><?php else: ?>
        <p><b><?= h(money($ts['monthly'])) ?></b>/month · <b><?= h(money($ts['unseen_cost'])) ?></b> on tools not seen in the audit</p>
        <div class="table-wrap"><table><tr><th>Tool</th><th>Per month</th><th>Seats</th><th>In the audit</th></tr>
        <?php foreach ($ts['rows'] as $t): ?><tr><td><?= h($t['name']) ?></td><td><?= h(money((float) $t['cost'])) ?></td><td><?= (int) $t['seats'] ?></td><td><?= $t['minutes'] !== null ? h(fmt_hours((float) $t['minutes'])) . ' hrs' : ($t['desktop'] ? 'Desktop app' : 'Not seen') ?></td></tr><?php endforeach; ?>
        </table></div>
      <?php endif; ?></section>
    <section class="card"><h2>Back-Office Check<?= $profile['industry'] ? ' · ' . h(industry_label($profile['industry'])) : '' ?></h2>
      <?php render_checklist_summary(checklist_summary($profile['industry'] ?: 'other', load_checklist($lid))); ?></section>
    <section class="card"><h2>Team telemetry</h2><?php render_telemetry($aggregate, $audits, false); ?><?php render_progress(progress_by_month($audits)); ?></section>
    <section class="card"><h2>Connected browsers</h2>
      <?php if (!$devices): ?><p class="muted">None yet.</p><?php else: ?>
        <div class="table-wrap"><table><tr><th>Role</th><th>First seen</th><th>Last seen</th></tr>
        <?php foreach ($devices as $d): ?><tr><td><?= h($d['role_tag'] ?: '—') ?></td><td><?= h(format_date($d['first_seen'])) ?></td><td><?= h(format_date($d['last_seen'])) ?></td></tr><?php endforeach; ?>
        </table></div>
      <?php endif; ?>
    </section>
    <section class="card"><h2>Report prompt</h2><?php render_prompt_box(build_business_prompt($license, $profile, $aggregate, load_checklist($lid), load_tools($lid))); ?></section>
    <?php
    render_footer();
    exit;
}

/* ---------- Customer list ---------- */
$customers = db()->query(
    'SELECT l.*, (SELECT COUNT(*) FROM audits a WHERE a.license_id = l.id) AS audit_count,
            (SELECT COUNT(*) FROM reports r WHERE r.license_id = l.id AND r.kind = \'ai\') AS ai_count,
            (SELECT COUNT(*) FROM reports r WHERE r.license_id = l.id AND r.kind = \'final\') AS final_count
     FROM licenses l ORDER BY l.id DESC'
)->fetchAll();
$leads = db()->query('SELECT * FROM leads ORDER BY id DESC LIMIT 200')->fetchAll();
?>
  <div class="actions" style="justify-content:space-between">
    <h1 style="margin:0">Admin</h1><?= $logout ?>
  </div>

  <section class="card">
    <h2>Team Audit customers (<?= count($customers) ?>)</h2>
    <?php if (!$customers): ?>
      <p class="muted">No customers yet.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>Company</th><th>Plan</th><th>Status</th><th>Audits</th><th>Report</th><th>Since</th><th></th></tr>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td><?= h($c['company']) ?><br><span class="muted small"><?= h($c['email']) ?></span></td>
            <td><?= h(plan_name($c)) ?><?= license_monitoring($c) ? '<br><span class="badge">Tracking</span>' : '' ?></td>
            <td><?= license_is_active($c) ? '<span class="badge green">Active</span>' : '<span class="badge red">Ended</span>' ?></td>
            <td><?= (int) $c['audit_count'] ?></td>
            <td><?= $c['final_count'] ? '<span class="badge green">Plan sent</span>' : ($c['ai_count'] ? '<span class="badge">Needs follow-up</span>' : '<span class="muted">—</span>') ?></td>
            <td><?= h(substr((string) $c['created_at'], 0, 10)) ?></td>
            <td><a class="btn" style="padding:4px 10px" href="admin.php?view=<?= (int) $c['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Free audit leads (latest <?= count($leads) ?>)</h2>
    <?php if (!$leads): ?>
      <p class="muted">No leads yet.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>Email</th><th>Role</th><th>Date</th></tr>
        <?php foreach ($leads as $l): ?>
          <tr><td><?= h($l['email']) ?></td><td><?= h($l['role_tag']) ?></td><td><?= h(substr((string) $l['created_at'], 0, 10)) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </section>
<?php
render_footer();

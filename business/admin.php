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
    if (!empty($_SESSION['is_admin']) && in_array($_POST['action'] ?? '', ['save_final', 'free_seats', 'extend', 'set_stage', 'save_tests'], true)) {
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
                        ghl_event('final_plan_sent', ghl_license_contact($lic), ghl_license_fields($lic));
                    }
                }
            } elseif ($act === 'save_tests') {
                $tests = load_tests($lid);
                foreach (STATION_TESTS as $tid => $_) {
                    $result = (string) ($_POST['result_' . $tid] ?? '');
                    $detail = trim(mb_substr((string) ($_POST['detail_' . $tid] ?? ''), 0, 200));
                    if (!array_key_exists($result, TEST_RESULTS)) {
                        continue;
                    }
                    $changed = ($tests[$tid]['result'] ?? '') !== $result || ($tests[$tid]['detail'] ?? '') !== $detail;
                    $tests[$tid] = [
                        'result' => $result,
                        'detail' => $detail,
                        'date'   => $changed ? now_iso() : ($tests[$tid]['date'] ?? now_iso()),
                    ];
                }
                write_profile_data($lid, array_merge(profile_data($lid), ['tests' => $tests]));
            } elseif ($act === 'set_stage') {
                $stage = (string) ($_POST['stage'] ?? '');
                if (array_key_exists($stage, BUILD_STAGES)) {
                    write_profile_data($lid, array_merge(profile_data($lid), ['stage' => $stage]));
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
    if (!empty($_SESSION['is_admin']) && ($_POST['action'] ?? '') === 'ghl_setup') {
        $msgs = [];
        foreach (ghl_destinations() as $name => $dest) {
            try {
                $made = ghl_setup_fields($dest);
                $msgs[] = $name . ': ' . ($made ? 'created ' . implode(', ', $made) : 'all fields already there');
            } catch (Throwable $e) {
                $msgs[] = $name . ': ' . $e->getMessage();
                log_issue('ghl', 'Field setup for ' . $name . ': ' . $e->getMessage());
            }
        }
        $_SESSION['admin_flash'] = $msgs ? implode(' · ', $msgs) : 'No GHL destinations set up yet. Add a token in the config file.';
        redirect(site_url('admin.php') . '#ghl');
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
    <?php $stage = (string) (profile_data($lid)['stage'] ?? ''); ?>
    <form class="actions" method="post" action="admin.php" style="margin-bottom:10px">
      <?= $hidden ?><input type="hidden" name="action" value="set_stage">
      <label style="margin:0">Build stage (the customer sees this on their Home page)
        <select name="stage"><?php foreach (BUILD_STAGES as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $k === $stage ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
      </label>
      <button class="btn" type="submit">Save stage</button>
    </form>
    <?php
      $fb = db()->prepare('SELECT * FROM feedback WHERE license_id = ? ORDER BY id DESC');
      $fb->execute([$lid]);
      $fbRows = $fb->fetchAll();
    ?>
    <?php if ($fbRows): ?>
      <section class="card"><h2>What they told us</h2>
        <div class="table-wrap"><table><tr><th>Who</th><th>Accurate?</th><th>Recommend</th><th>Wants help</th><th>Comment</th></tr>
        <?php foreach ($fbRows as $f): ?><tr><td><?= h($f['context'] === 'owner' ? 'Owner' : 'Team member') ?></td><td><?= h(ACCURACY_ANSWERS[$f['accurate']] ?? '—') ?></td><td><?= $f['nps'] === null ? '—' : (int) $f['nps'] ?></td><td><?= $f['wants_help'] ? 'Yes' : '—' ?></td><td><?= h($f['comment']) ?></td></tr><?php endforeach; ?>
        </table></div></section>
    <?php endif; ?>
    <div class="actions" style="margin-bottom:18px">
      <form class="inline" method="post" action="admin.php"><?= $hidden ?><input type="hidden" name="action" value="extend"><button class="btn" type="submit">Extend audit +30 days</button></form>
      <form class="inline" method="post" action="admin.php" onsubmit="return confirm('Disconnect every browser from this key? Teammates re-connect by entering the key again.')"><?= $hidden ?><input type="hidden" name="action" value="free_seats"><button class="btn danger" type="submit">Free all seats</button></form>
    </div>

    <?php
      $cProfile = load_profile($lid);
      $cStatus  = chain_status($cProfile['industry'] ?: 'other', load_chain($lid), chain_evidence($aggregate), load_tests($lid));
      $sStatus  = stations_status(load_stations($lid), station_evidence($aggregate), load_tests($lid));
      $cTests   = load_tests($lid);
    ?>
    <section class="card">
      <h2>7 stations</h2>
      <div class="stations">
        <?php $n = 0; foreach ($sStatus['rows'] as $r): $n++; $a = $r['answers']; ?>
          <div class="station-tile <?= h($r['status']) ?>">
            <span class="st-num"><?= $n ?></span><b><?= h($r['title']) ?></b>
            <span class="st-status"><?= $r['score'] !== null ? (int) $r['score'] . '%' : 'Not answered' ?></span>
            <span class="st-three"><span>P: <?= h(STATION_PROCESS[$a['process'] ?? ''] ?? '—') ?></span><span>O: <?= h(STATION_OWNER[$a['owner'] ?? ''] ?? '—') ?><?= ($a['owner_name'] ?? '') !== '' ? ' (' . h($a['owner_name']) . ')' : '' ?></span><span>A: <?= h(STATION_AUTOMATION[$a['automation'] ?? ''] ?? '—') ?></span></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php foreach ($sStatus['rows'] as $r): foreach (array_merge($r['flags'], $r['evidence']) as $note): ?><p class="evidence"><b><?= h($r['title']) ?>:</b> <?= h($note) ?></p><?php endforeach; endforeach; ?>
    </section>

    <section class="card">
      <h2>Client journey</h2>
      <div class="chain">
        <?php $i = 0; foreach ($cStatus['rows'] as $r): ?><?php if ($i++ > 0): ?><span class="arrow">→</span><?php endif; ?><span class="link <?= h($r['status']) ?>"><?= h($r['label']) ?></span><?php endforeach; ?>
      </div>
      <?php foreach ($cStatus['rows'] as $r): if ($r['evidence']): ?><p class="evidence"><b><?= h($r['label']) ?>:</b> <?= h($r['evidence']['text']) ?></p><?php endif; endforeach; ?>
    </section>

    <section class="card">
      <h2>Handoff tests</h2>
      <p class="muted small">Run these against the customer's real website and booking link. Results show to the customer as "Tested by our team" on the matching station and Client journey step.</p>
      <form method="post" action="admin.php">
        <?= $hidden ?><input type="hidden" name="action" value="save_tests">
        <?php foreach (STATION_TESTS as $tid => $t): $cur = $cTests[$tid] ?? ['result' => '', 'detail' => '', 'date' => null]; ?>
          <div class="test-row">
            <div><b><?= h($t['label']) ?></b><div class="muted small"><?= h($t['how']) ?></div>
              <?php if (!empty($cur['date']) && $cur['result'] !== ''): ?><div class="muted small">Last updated <?= h(format_date($cur['date'])) ?></div><?php endif; ?></div>
            <select name="result_<?= h($tid) ?>"><?php foreach (TEST_RESULTS as $k => $lbl): ?><option value="<?= h($k) ?>"<?= $cur['result'] === $k ? ' selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
            <input type="text" name="detail_<?= h($tid) ?>" maxlength="200" value="<?= h($cur['detail']) ?>" placeholder="e.g. reply in 3 hours">
          </div>
        <?php endforeach; ?>
        <button class="btn primary" type="submit">Save tests</button>
      </form>
    </section>

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
      <?= $aiReport ? '<p class="muted small">Generated ' . h(format_date($aiReport['created_at'])) . ' · ' . h($aiReport['model']) . '</p>' . render_markdown($aiReport['content'])
          : (ai_reports_enabled() ? '<p class="muted">The customer has not generated one yet.</p>' : '<p class="muted">The instant AI report is turned off. Copy the report prompt at the bottom of this page into Claude or ChatGPT for a first draft, then edit it into the final plan.</p>') ?>
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
  <?php if (!empty($_SESSION['admin_flash'])): ?><div class="alert ok"><?= h($_SESSION['admin_flash']) ?></div><?php unset($_SESSION['admin_flash']); endif; ?>

  <?php $sb = scoreboard(); ?>
  <section class="card">
    <h2>Is the audit doing its job?</h2>
    <div class="stats">
      <div class="stat"><b><?= (int) $sb['free_audits'] ?></b><span>Free audits finished (emails)</span></div>
      <div class="stat"><b><?= (int) $sb['purchases'] ?></b><span>Paid audits<?= $sb['conversion'] !== null ? ' · ' . (int) $sb['conversion'] . '% of free' : '' ?></span></div>
      <div class="stat"><b><?= (int) $sb['reports'] ?> → <?= (int) $sb['final_plans'] ?></b><span>Instant reports → final plans</span></div>
      <div class="stat"><b><?= (int) $sb['tracking'] ?></b><span>On Results Tracking</span></div>
      <div class="stat"><b><?= $sb['accuracy'] === null ? '—' : (int) $sb['accuracy'] . '%' ?></b><span>Said it found something real</span></div>
      <div class="stat"><b><?= $sb['nps'] === null ? '—' : (int) $sb['nps'] ?></b><span>Recommend score (NPS, <?= (int) $sb['nps_count'] ?> answers)</span></div>
      <div class="stat"><b><?= (int) $sb['wants_help'] ?></b><span>Asked for help fixing it</span></div>
      <div class="stat"><b><?= $sb['fallback_rate'] === null ? '—' : (int) $sb['fallback_rate'] . '%' ?></b><span>Free results with no specific agent match</span></div>
    </div>
    <?php if ($sb['by_plan']): ?><p class="muted small">By plan: <?= h(implode(' · ', array_map(fn($k, $v) => (plan_info((string) $k)['name'] ?? $k) . ' ' . $v, array_keys($sb['by_plan']), $sb['by_plan']))) ?></p><?php endif; ?>
    <?php if ($sb['unknown_tools']): ?>
      <p class="muted small">Tools teams use that the tool list doesn't recognize yet (add them to <code>insights.js</code>, then <code>tools.php</code>):
        <?= h(implode(', ', array_map(fn($d, $m) => $d . ' (' . round($m / 60, 1) . ' h)', array_keys($sb['unknown_tools']), $sb['unknown_tools']))) ?></p>
    <?php endif; ?>
  </section>

  <?php
    $recentFb = db()->query("SELECT * FROM feedback ORDER BY id DESC LIMIT 25")->fetchAll();
    $issues   = db()->query('SELECT * FROM issues ORDER BY id DESC LIMIT 20')->fetchAll();
  ?>
  <?php if ($issues): ?>
    <section class="card"><h2>Needs attention</h2>
      <div class="table-wrap"><table><tr><th>When</th><th>What</th><th>Details</th></tr>
      <?php foreach ($issues as $i): ?><tr><td><?= h(format_date($i['created_at'])) ?></td><td><?= h(ucfirst(str_replace('_', ' ', $i['kind']))) ?></td><td><?= h($i['detail']) ?></td></tr><?php endforeach; ?>
      </table></div></section>
  <?php endif; ?>

  <section class="card"><h2>Survey answers (latest <?= count($recentFb) ?>)</h2>
    <?php if (!$recentFb): ?><p class="muted">No answers yet.</p><?php else: ?>
      <div class="table-wrap"><table><tr><th>Date</th><th>Who</th><th>Accurate?</th><th>Recommend</th><th>Wants help</th><th>Comment</th></tr>
      <?php foreach ($recentFb as $f): ?><tr>
        <td><?= h(format_date($f['created_at'])) ?></td>
        <td><?= h($f['email'] ?: ($f['context'] === 'team_member' ? 'Team member' : 'Owner')) ?><?= $f['license_id'] ? ' · <a href="admin.php?view=' . (int) $f['license_id'] . '">open</a>' : '' ?></td>
        <td><?= h(ACCURACY_ANSWERS[$f['accurate']] ?? '—') ?></td><td><?= $f['nps'] === null ? '—' : (int) $f['nps'] ?></td>
        <td><?= $f['wants_help'] ? '<span class="badge green">Yes</span>' : '—' ?></td><td><?= h($f['comment']) ?></td></tr><?php endforeach; ?>
      </table></div>
    <?php endif; ?></section>

  <section class="card">
    <h2>Paid customers (<?= count($customers) ?>)</h2>
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
            <td><?= $c['final_count'] ? '<span class="badge green">Plan sent</span>' : ($c['ai_count'] ? '<span class="badge">Needs follow-up</span>' : ((!ai_reports_enabled() && $c['audit_count'] > 0 && plan_includes_final_plan($c)) ? '<span class="badge red">Needs final plan</span>' : '<span class="muted">—</span>')) ?></td>
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
        <tr><th>Email</th><th>Industry</th><th>Bottleneck</th><th>Agent suggested</th><th>Date</th></tr>
        <?php foreach ($leads as $l): ?>
          <tr><td><?= h($l['email']) ?><?= $l['role_tag'] ? '<br><span class="muted small">' . h($l['role_tag']) . '</span>' : '' ?></td>
            <td><?= $l['industry'] ? h(industry_label($l['industry'])) : '—' ?></td><td><?= h($l['bottleneck'] ?: '—') ?></td>
            <td><?= h($l['agent'] ?: '—') ?></td><td><?= h(substr((string) $l['created_at'], 0, 10)) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </section>
  <section class="card" id="ghl"><h2>GoHighLevel</h2>
    <?php $dests = ghl_destinations(); ?>
    <?php if (!$dests): ?>
      <p class="muted">Not connected. Add a Private Integration token for each sub-account under <code>ghl</code> in your config file.</p>
    <?php else: ?>
      <p>Sending to: <b><?= h(implode(', ', array_keys($dests))) ?></b></p>
      <form method="post" action="admin.php"><?= csrf_field() ?><input type="hidden" name="action" value="ghl_setup"><button class="btn" type="submit">Create GHL fields</button></form>
      <?php $log = db()->query('SELECT * FROM ghl_log ORDER BY id DESC LIMIT 15')->fetchAll(); ?>
      <?php if ($log): ?>
        <div class="table-wrap" style="margin-top:12px"><table><tr><th>When</th><th>To</th><th>Event</th><th>Contact</th><th>Result</th></tr>
        <?php foreach ($log as $g): ?><tr><td><?= h(format_date($g['created_at'])) ?></td><td><?= h($g['destination']) ?></td><td><?= h($g['event']) ?></td><td><?= h($g['email']) ?></td>
          <td><?= $g['ok'] ? '<span class="badge green">Sent</span> <span class="muted small">' . h($g['detail']) . '</span>' : '<span class="badge red">Failed</span> <span class="muted small">' . h($g['detail']) . '</span>' ?></td></tr><?php endforeach; ?>
        </table></div>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php
render_footer();

<?php
/*
 * Owner dashboard — see every Business customer, their profile, team telemetry
 * and the ready-made AI Gap Analysis prompt. Also lists free (Lite) leads.
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
    <p class="muted"><?= h($license['email']) ?> · <code><?= h($license['license_key']) ?></code> ·
      <?= license_is_active($license) ? '<span class="badge green">Active</span>' : '<span class="badge red">Inactive</span>' ?></p>
    <section class="card"><h2>Business profile</h2><?php render_profile_readonly($profile); ?></section>
    <section class="card"><h2>Team telemetry</h2><?php render_telemetry($aggregate, $audits, false); ?></section>
    <section class="card"><h2>AI Agent Gap Analysis prompt</h2><?php render_prompt_box(build_business_prompt($license, $profile, $aggregate)); ?></section>
    <?php
    render_footer();
    exit;
}

/* ---------- Customer list ---------- */
$customers = db()->query(
    'SELECT l.*, (SELECT COUNT(*) FROM audits a WHERE a.license_id = l.id) AS audit_count,
            (SELECT p.updated_at FROM profiles p WHERE p.license_id = l.id) AS profile_updated
     FROM licenses l ORDER BY l.id DESC'
)->fetchAll();
$leads = db()->query('SELECT * FROM leads ORDER BY id DESC LIMIT 200')->fetchAll();
?>
  <div class="actions" style="justify-content:space-between">
    <h1 style="margin:0">Admin</h1><?= $logout ?>
  </div>

  <section class="card">
    <h2>Business customers (<?= count($customers) ?>)</h2>
    <?php if (!$customers): ?>
      <p class="muted">No customers yet.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>Company</th><th>Email</th><th>Status</th><th>Audits</th><th>Profile</th><th>Since</th><th></th></tr>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td><?= h($c['company']) ?></td>
            <td><?= h($c['email']) ?></td>
            <td><?= $c['status'] === 'active' ? '<span class="badge green">Active</span>' : '<span class="badge red">' . h($c['status']) . '</span>' ?></td>
            <td><?= (int) $c['audit_count'] ?></td>
            <td><?= $c['profile_updated'] ? h(substr((string) $c['profile_updated'], 0, 10)) : '<span class="muted">—</span>' ?></td>
            <td><?= h(substr((string) $c['created_at'], 0, 10)) ?></td>
            <td><a class="btn" style="padding:4px 10px" href="admin.php?view=<?= (int) $c['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Lite leads (latest <?= count($leads) ?>)</h2>
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

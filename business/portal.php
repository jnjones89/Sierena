<?php
/*
 * Business Portal — customers sign in with email + team key.
 *
 * Pages (?page=…): home · business · questions · team · report · numbers
 * Home shows 4 simple steps; each page does one thing.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';
require __DIR__ . '/includes/report.php';

start_session();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

const PORTAL_PAGES = [
    'home'      => 'Home',
    'business'  => 'Your business',
    'tools'     => 'Your tools',
    'questions' => 'Questions',
    'journey'   => 'Client journey',
    'team'      => 'Your team',
    'report'    => 'Report',
    'numbers'   => 'Numbers',
    'help'      => 'Help',
];

function portal_url(string $page = 'home', array $query = []): string
{
    $query = $page === 'home' ? $query : ['page' => $page] + $query;
    return site_url('portal.php') . ($query ? '?' . http_build_query($query) : '');
}

function go(string $page, string $msg = '', string $kind = 'ok', array $query = []): never
{
    if ($msg !== '') {
        $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
    }
    redirect(portal_url($page, $query));
}

$license = isset($_SESSION['license_id']) ? find_license_by_id((int) $_SESSION['license_id']) : null;
$active  = license_is_active($license);

/* ---------------------------- Actions ---------------------------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        if (!rate_limit('login', 10, 600)) {
            go('home', 'Too many tries. Please wait 10 minutes.', 'error');
        }
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $found = find_license_by_key((string) ($_POST['key'] ?? ''));
        if (!$found || !hash_equals(strtolower($found['email']), $email)) {
            go('home', "That email and team key don't match. Check both and try again.", 'error');
        }
        session_regenerate_id(true);
        $_SESSION['license_id'] = (int) $found['id'];
        go('home');
    }

    if ($action === 'request_refund' && $license) {
        $reason = trim(mb_substr((string) ($_POST['reason'] ?? ''), 0, 1000));
        log_issue('refund', ($license['company'] ?: $license['email']) . ' asked for a refund. Reason: ' . ($reason ?: '(none given)'));
        send_mail((string) cfg('owner_email'), 'Refund request: ' . ($license['company'] ?: $license['email']),
            "{$license['company']} ({$license['email']}) asked for their money back.\n\nReason: " . ($reason ?: '(none given)')
            . "\nPlan: " . plan_name($license) . "\nBought: " . format_date($license['created_at'])
            . "\n\nRefund it in Stripe (Payments → find the payment → Refund) and reply to them.\n");
        ghl_event('refund_requested', ghl_license_contact($license), ghl_license_fields($license));
        go('help', "We got your request. We'll refund you and email you within 2 business days.");
    }

    if ($action === 'download_data' && $license) {
        $json = json_encode(export_customer_data($license), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="workflow-shadow-audit-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($license['company'] ?: 'data')) . '.json"');
        echo $json;
        exit;
    }

    if ($action === 'delete_data' && $license) {
        if (trim((string) ($_POST['confirm'] ?? '')) !== 'DELETE') {
            go('help', 'To delete your data, type DELETE in the box.', 'error');
        }
        delete_customer_data($license);
        log_issue('data_deleted', ($license['company'] ?: $license['email']) . ' deleted their data.');
        $_SESSION = [];
        session_regenerate_id(true);
        go('home', 'Your data has been deleted.');
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        go('home', 'You are signed out.');
    }

    if (!$license) {
        go('home', 'Please sign in.', 'error');
    }
    if (!$active) {
        go('home', 'Your audit has ended. Turn on Results Tracking to keep going.', 'error');
    }
    $lid = (int) $license['id'];

    if ($action === 'save_profile') {
        $saved = save_profile($lid, $_POST);
        ghl_event('profile_updated', ghl_license_contact(find_license_by_id($lid)), ghl_license_fields(find_license_by_id($lid)),
            ghl_industry_tag($saved['industry']));
        send_mail(
            (string) cfg('owner_email'),
            'Business profile updated: ' . ($saved['company'] ?: $license['email']),
            "A customer updated their business profile.\n\nCompany: {$saved['company']}\nEmail: {$license['email']}\n\nReview it: "
            . site_url('admin.php?view=' . $lid) . "\n"
        );
        go('home', 'Saved. Thanks!');
    }

    if ($action === 'save_industry') {
        $industry = (string) ($_POST['industry'] ?? '');
        if (!isset(industries()[$industry])) {
            go('questions', 'Please pick your industry.', 'error');
        }
        write_profile_data($lid, array_merge(profile_data($lid), ['industry' => $industry]));
        go('questions', '', 'ok', ['q' => 0]);
    }

    if ($action === 'save_chain') {
        $industry = load_profile($lid)['industry'] ?: 'other';
        $chain = load_chain($lid);
        foreach (chain_links($industry) as $id => $_) {
            $v = (string) ($_POST['link_' . $id] ?? '');
            if (isset(CHAIN_ANSWERS[$v])) {
                $chain[$id] = $v;
            }
        }
        write_profile_data($lid, array_merge(profile_data($lid), ['chain' => $chain]));
        go('journey', 'Saved. Thank you!');
    }

    if ($action === 'answer') {
        $industry = load_profile($lid)['industry'] ?: 'other';
        $items    = array_values(checklist_items($industry));
        $q        = max(0, (int) ($_POST['q'] ?? 0));
        $id       = (string) ($_POST['id'] ?? '');
        $answer   = (string) ($_POST['answer'] ?? '');
        if (isset($items[$q]) && $items[$q]['id'] === $id && isset(CHECK_ANSWERS[$answer])) {
            save_checklist($lid, $industry, [$id => $answer]);
        }
        if ($q + 1 >= count($items)) {
            go('home', "That's all the questions. Thank you!");
        }
        go('questions', '', 'ok', ['q' => $q + 1]);
    }

    if ($action === 'add_tool') {
        $tool = clean_tool_input($_POST);
        if ($tool === null) {
            go('tools', 'Please enter the tool name.', 'error');
        }
        $tools = load_tools($lid);
        $tools[] = $tool;
        save_tools($lid, $tools);
        go('tools', 'Added ' . $tool['name'] . '.');
    }

    if ($action === 'remove_tool') {
        $tools = load_tools($lid);
        $i = (int) ($_POST['index'] ?? -1);
        if (isset($tools[$i])) {
            $name = $tools[$i]['name'];
            array_splice($tools, $i, 1);
            save_tools($lid, $tools);
            go('tools', 'Removed ' . $name . '.');
        }
        go('tools');
    }

    if ($action === 'owner_survey') {
        if (!feedback_given($lid, 'owner')) {
            $wants = ($_POST['wants_help'] ?? '') === 'yes';
            save_feedback([
                'context' => 'owner', 'email' => $license['email'], 'license_id' => $lid,
                'accurate' => (string) ($_POST['accurate'] ?? ''), 'nps' => $_POST['nps'] ?? null,
                'wants_help' => $wants, 'comment' => (string) ($_POST['comment'] ?? ''),
                'industry' => load_profile($lid)['industry'],
            ]);
            ghl_event($wants ? 'wants_help' : 'profile_updated', ghl_license_contact($license), [
                'swa_nps' => ($_POST['nps'] ?? '') !== '' ? (int) $_POST['nps'] : null,
                'swa_accuracy' => ACCURACY_ANSWERS[(string) ($_POST['accurate'] ?? '')] ?? null,
            ]);
            if (trim((string) ($_POST['comment'] ?? '')) !== '' || ($_POST['accurate'] ?? '') !== 'spot_on') {
                send_mail((string) cfg('owner_email'), 'Report feedback: ' . ($license['company'] ?: $license['email']),
                    'Accuracy: ' . (ACCURACY_ANSWERS[(string) ($_POST['accurate'] ?? '')] ?? '-') . "\nRecommend: " . ($_POST['nps'] ?? '-')
                    . "\nWhat to correct: " . ($_POST['comment'] ?? '') . "\n\nUse this in the final plan: " . site_url('admin.php?view=' . $lid) . "\n");
            }
        }
        go('report', 'Thank you. We use this when we write your final plan.');
    }

    if ($action === 'upload_audit') {
        $file = $_FILES['audit_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] > 2_000_000) {
            go('team', 'Please choose an audit file (2 MB max).', 'error');
        }
        $clean = sanitize_audit_payload(json_decode((string) file_get_contents($file['tmp_name']), true));
        if ($clean === null) {
            go('team', "That file isn't a Workflow Shadow Audit file.", 'error');
        }
        store_audit($lid, $clean);
        go('team', 'Audit added (' . $clean['auditMetadata']['roleTag'] . ').');
    }

    if ($action === 'delete_audit') {
        db()->prepare('DELETE FROM audits WHERE id = ? AND license_id = ?')->execute([(int) ($_POST['audit_id'] ?? 0), $lid]);
        go('team', 'Audit removed.');
    }

    if ($action === 'generate_report') {
        if (!ai_reports_enabled()) {
            go('report', 'The instant AI report is not available right now. Our team will send your final plan.', 'error');
        }
        if (!list_audits($lid)) {
            go('report', 'We need at least one audit from your team first.', 'error');
        }
        if (reports_today($lid) >= (int) cfg('reports_per_day')) {
            go('report', "You've reached today's limit. Please try again tomorrow.", 'error');
        }
        // Writing the report can take a minute or two.
        @set_time_limit(300);
        ignore_user_abort(true);
        session_write_close();
        try {
            $prompt = build_business_prompt($license, load_profile($lid), aggregate_audits(list_audits($lid)), load_checklist($lid), load_tools($lid));
            $result = generate_ai_report($prompt);
            save_report($lid, 'ai', $result['text'], $result['model']);
        } catch (Throwable $e) {
            error_log('[SWA report] ' . $e->getMessage());
            log_issue('report', ($license['company'] ?: $license['email']) . ': ' . $e->getMessage());
            start_session();
            go('report', "We couldn't write your report right now. Please try again in a few minutes.", 'error');
        }
        send_mail(
            (string) cfg('owner_email'),
            'Instant report generated: ' . ($license['company'] ?: $license['email']),
            "{$license['company']} ({$license['email']}) just generated their instant AI report.\n"
            . 'Follow up with the final plan here: ' . site_url('admin.php?view=' . $lid) . "\n"
        );
        $sum = checklist_summary(load_profile($lid)['industry'] ?: 'other', load_checklist($lid));
        ghl_event('instant_report_ready', ghl_license_contact($license),
            ['swa_backoffice_score' => $sum['score']] + ghl_license_fields($license));
        start_session();
        go('report', 'Your instant AI report is ready.');
    }

    go('home', 'Something went wrong. Please try again.', 'error');
}

/* ----------------------------- Sign in ----------------------------- */

render_header('Business Portal');

$showFlash = function () use ($flash) {
    if ($flash) {
        echo '<div class="alert ' . ($flash['kind'] === 'error' ? 'error' : 'ok') . '">' . h($flash['msg']) . '</div>';
    }
};

if (!$license):
    $showFlash();
?>
  <h1>Sign in</h1>
  <p class="lead">Use the email you paid with and your team key.</p>
  <section class="card" style="max-width:460px">
    <form method="post" action="portal.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label>Email <input type="email" name="email" required autocomplete="email"></label>
      <label>Team key <span class="hint">It starts with AUDIT- and was in your email.</span>
        <input type="text" name="key" required placeholder="AUDIT-XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false"></label>
      <button class="btn primary block" type="submit">Sign in</button>
    </form>
  </section>
  <p class="muted small">Don't have a team key yet? <a href="./">See Team Audits</a>.</p>
<?php
    render_footer();
    exit;
endif;

/* ----------------------------- Data ------------------------------ */

$page       = (string) ($_GET['page'] ?? 'home');
$page       = isset(PORTAL_PAGES[$page]) ? $page : 'home';
$lid        = (int) $license['id'];
$profile    = load_profile($lid);
$industry   = $profile['industry'] ?: 'other';
$checklist  = load_checklist($lid);
$summary    = checklist_summary($industry, $checklist);
$audits     = list_audits($lid);
$devices    = list_devices($lid);
$aiReport   = latest_report($lid, 'ai');
$finalPlan  = latest_report($lid, 'final');
$monitoring = license_monitoring($license);
$monCfg     = (array) cfg('monitoring');
$company    = $profile['company'] ?: ($license['company'] ?: 'your business');
$tools      = load_tools($lid);
$toolsSum   = tools_summary($tools, aggregate_audits($audits));
$solo       = license_is_solo($license);
$chainStatus = chain_status($industry, load_chain($lid), chain_evidence(aggregate_audits($audits)), load_tests($lid));
$coverage   = data_coverage($license, $audits, $devices);
$finalIncluded = plan_includes_final_plan($license);
$dis        = $active ? '' : ' disabled';

/* ----------------------------- Layout ---------------------------- */

echo '<nav class="tabs" aria-label="Portal">';
foreach (PORTAL_PAGES as $key => $label) {
    if ($key === 'team' && $solo) {
        $label = 'Your extension';
    }
    echo '<a href="' . h(portal_url($key)) . '"' . ($key === $page ? ' class="on" aria-current="page"' : '') . '>' . h($label) . '</a>';
}
echo '</nav>';
$showFlash();

if (!$active) {
    echo '<div class="note">Your audit has ended. Everything is still here to read. To keep auditing, turn on <b>'
        . h((string) $monCfg['name']) . '</b> on the Home page.</div>';
}

function field_html(string $key, array $profile, string $dis): string
{
    [$label, $type, $ph] = profile_fields()[$key];
    $val = $profile[$key] ?? '';
    if ($type === 'select') {
        $opts = '<option value="">Choose one…</option>';
        foreach (industries() as $k => $lbl) {
            $opts .= '<option value="' . h($k) . '"' . ($k === $val ? ' selected' : '') . '>' . h($lbl) . '</option>';
        }
        return '<label>' . h($label) . '<select name="' . h($key) . '"' . $dis . '>' . $opts . '</select></label>';
    }
    if ($type === 'textarea') {
        return '<label>' . h($label) . '<textarea name="' . h($key) . '" maxlength="4000" placeholder="' . h($ph) . '"' . $dis . '>' . h($val) . '</textarea></label>';
    }
    return '<label>' . h($label) . '<input type="text" name="' . h($key) . '" maxlength="300" value="' . h($val) . '" placeholder="' . h($ph) . '"' . $dis . '></label>';
}

/* ------------------------------ Home ----------------------------- */

if ($page === 'home'):
    $businessDone  = $profile['company'] !== '' && $profile['industry'] !== '';
    $teamDone      = count($devices) > 0 && count($audits) > 0;
    $questionsDone = $summary['answered'] >= $summary['total'];
    $reportDone    = (bool) ($finalPlan || $aiReport);
    $steps = [
        [
            'done'  => $businessDone, 'title' => 'Tell us about your business',
            'text'  => $businessDone ? 'Done. You can update it any time.' : ($solo ? 'Your industry, website and social media. About 3 minutes.' : 'Your industry, team size and social media. About 3 minutes.'),
            'page'  => 'business', 'cta' => $businessDone ? 'Edit' : 'Start',
        ],
        [
            'done'  => count($tools) > 0, 'title' => 'List the software you pay for',
            'text'  => count($tools) > 0 ? count($tools) . ' tools · ' . money($toolsSum['monthly']) . ' a month.' : 'Name and monthly cost. We show which ones your team actually uses. About 3 minutes.',
            'page'  => 'tools', 'cta' => count($tools) > 0 ? 'Edit' : 'Start',
        ],
        $solo ? [
            'done'  => count($audits) > 0, 'title' => 'Run the audit on your computer',
            'text'  => count($audits) > 0 ? $coverage['text'] : 'Paste your key into the extension, work like normal for a few days, then send it here.',
            'page'  => 'team', 'cta' => count($audits) > 0 ? 'View' : 'How to connect',
        ] : [
            'done'  => $teamDone, 'title' => 'Get your team connected',
            'text'  => count($devices) . ' of ' . (int) $license['seats'] . ' people connected · ' . count($audits) . ' audit' . (count($audits) === 1 ? '' : 's') . ' sent in.',
            'page'  => 'team', 'cta' => $teamDone ? 'View team' : 'Invite your team',
        ],
        [
            'done'  => $questionsDone, 'title' => 'Answer some quick questions',
            'text'  => $summary['answered'] . ' of ' . $summary['total'] . ' answered. One question at a time, about 5 minutes.',
            'page'  => 'questions', 'cta' => $summary['answered'] === 0 ? 'Start' : ($questionsDone ? 'Review' : 'Continue'),
        ],
        [
            'done'  => $chainStatus['counts']['unknown'] === 0, 'title' => 'Map your client journey',
            'text'  => $chainStatus['counts']['unknown'] === 0
                ? $chainStatus['counts']['ok'] . ' steps automatic · ' . ($chainStatus['counts']['manual'] + $chainStatus['counts']['weak']) . ' by hand or sometimes · ' . $chainStatus['counts']['broken'] . ' broken.'
                : 'From new lead to paid invoice: does each step trigger the next? About 3 minutes.',
            'page'  => 'journey', 'cta' => $chainStatus['counts']['unknown'] === count($chainStatus['rows']) ? 'Start' : ($chainStatus['counts']['unknown'] === 0 ? 'Review' : 'Continue'),
        ],
        [
            'done'  => $reportDone, 'title' => 'Get your report',
            'text'  => $finalPlan ? 'Your final plan from our team is ready.'
                : ($aiReport ? ($finalIncluded ? 'Your instant AI report is ready. Our team is preparing your final plan.' : 'Your instant AI report is ready. Book a free call to go over it.')
                : ($solo ? 'Ready as soon as you send in your audit.' : 'Ready as soon as your team sends in their audits.')),
            'page'  => 'report', 'cta' => $reportDone ? 'Read it' : 'Open',
        ],
    ];
?>
  <h1>Hi, <?= h($company) ?></h1>
  <?php $journey = journey($license, (bool) $audits, (bool) $aiReport, (bool) $finalPlan); ?>
  <nav class="journey-bar" aria-label="What happens next">
    <?php foreach ($journey as $j): ?>
      <span class="<?= $j['done'] ? 'done' : (!empty($j['now']) ? 'now' : '') ?>"><?= $j['done'] ? '✓ ' : '' ?><?= h($j['label']) ?></span>
    <?php endforeach; ?>
  </nav>
  <p class="lead"><?= h(plan_name($license)) ?> · <?= $monitoring ? 'Results Tracking is on' : 'open until ' . h(format_date($license['access_until'])) ?></p>
  <?php
    $daysLeft = $license['access_until'] ? (int) ceil((strtotime((string) $license['access_until']) - time()) / 86400) : 0;
    if ($active && !$monitoring && $daysLeft <= 7):
  ?>
    <div class="note">Your audit ends in <?= max(1, $daysLeft) ?> day<?= $daysLeft === 1 ? '' : 's' ?>. Ask your team to send in their audits, and get your report before then.</div>
  <?php endif; ?>

  <?php foreach ($steps as $i => $s): ?>
    <section class="card step<?= $s['done'] ? ' done' : '' ?>">
      <div class="num"><?= $s['done'] ? '✓' : $i + 1 ?></div>
      <div class="body">
        <h2><?= h($s['title']) ?> <span class="status <?= $s['done'] ? 'done' : 'todo' ?>"><?= $s['done'] ? 'Done' : 'To do' ?></span></h2>
        <p><?= h($s['text']) ?></p>
      </div>
      <div class="go"><a class="btn<?= $s['done'] ? '' : ' primary' ?>" href="<?= h(portal_url($s['page'])) ?>"><?= h($s['cta']) ?></a></div>
    </section>
  <?php endforeach; ?>

  <section class="card">
    <h2>Want us to build it for you?</h2>
    <p class="muted">We build the AI agents in your plan. Your audit price is credited toward the build.</p>
    <a class="btn" href="<?= h((string) cfg('booking_url')) ?>" target="_blank" rel="noopener">Book a call →</a>
  </section>

  <section class="card">
    <h2><?= h((string) $monCfg['name']) ?> <span class="badge"><?= h(monitoring_tier($license)['price_label']) ?></span></h2>
    <?php if ($monitoring): ?>
      <p class="muted">On. Ask your team to send in their audits each month. See the results on the <a href="<?= h(portal_url('numbers')) ?>">Numbers</a> page.</p>
    <?php else: ?>
      <p class="muted">After your agents are built, keep auditing every month and see what improved. Cancel any time.</p>
      <form method="post" action="monitor.php"><?= csrf_field() ?><button class="btn" type="submit">Turn on <?= h((string) $monCfg['name']) ?></button></form>
    <?php endif; ?>
  </section>

  <div class="actions">
    <?php if (!empty($license['stripe_customer_id'])): ?>
      <form class="inline" method="post" action="billing.php"><?= csrf_field() ?><button class="btn small" type="submit">Billing &amp; invoices</button></form>
    <?php endif; ?>
    <form class="inline" method="post" action="portal.php"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn small" type="submit">Sign out</button></form>
  </div>

<?php
/* ---------------------------- Business --------------------------- */
elseif ($page === 'business'):
?>
  <h1>Your business</h1>
  <p class="lead">Only the first four are needed. The rest helps us make your plan more specific.</p>
  <form method="post" action="portal.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
    <section class="card">
      <div class="row">
        <?= field_html('company', $profile, $dis) ?>
        <?= field_html('industry', $profile, $dis) ?>
        <?= field_html('team_size', $profile, $dis) ?>
        <?= field_html('website', $profile, $dis) ?>
      </div>
    </section>
    <section class="card">
      <details<?= ($profile['facebook'] . $profile['instagram'] . $profile['linkedin']) !== '' ? ' open' : '' ?>>
        <summary>Your social media (optional)</summary>
        <div class="row">
          <?php foreach (['facebook', 'instagram', 'linkedin', 'tiktok', 'x_twitter', 'youtube', 'google_business'] as $k) echo field_html($k, $profile, $dis); ?>
        </div>
        <?= field_html('other_channels', $profile, $dis) ?>
      </details>
    </section>
    <section class="card">
      <details<?= ($profile['bottlenecks'] . $profile['goals']) !== '' ? ' open' : '' ?>>
        <summary>More about how you work (optional)</summary>
        <?php foreach (['bottlenecks', 'goals', 'departments', 'lead_sources'] as $k) echo field_html($k, $profile, $dis); ?>
        <div class="row">
          <?= field_html('software_budget', $profile, $dis) ?>
          <?= field_html('hourly_cost', $profile, $dis) ?>
        </div>
      </details>
    </section>
    <?php if ($active): ?><button class="btn primary" type="submit">Save</button><?php endif; ?>
  </form>

<?php
/* --------------------------- Questions --------------------------- */
elseif ($page === 'questions'):
    $items = array_values(checklist_items($industry));
    $q     = (int) ($_GET['q'] ?? -1);
    if (!$profile['industry']):
?>
  <h1>Quick questions</h1>
  <section class="card" style="max-width:560px">
    <h2>First, what kind of business are you?</h2>
    <p class="muted">We'll ask questions that fit your industry.</p>
    <form method="post" action="portal.php">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_industry">
      <select name="industry" required<?= $dis ?>>
        <option value="">Choose one…</option>
        <?php foreach (industries() as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?>
      </select>
      <?php if ($active): ?><p><button class="btn primary" type="submit">Continue</button></p><?php endif; ?>
    </form>
  </section>
<?php
    elseif ($q >= 0 && isset($items[$q])):
        $item = $items[$q];
        $cur  = $checklist[$item['id']] ?? '';
        $pct  = (int) round(100 * $q / max(1, count($items)));
?>
  <p class="muted small" style="margin:0">Question <?= $q + 1 ?> of <?= count($items) ?> · <?= h(CHECK_AREAS[$item['area']]) ?></p>
  <div class="progress"><div style="width:<?= $pct ?>%"></div></div>
  <section class="card">
    <p class="question"><?= h($item['q']) ?></p>
    <div class="why"><b>Why we ask:</b> <?= h($item['why']) ?></div>
    <?php if ($active): ?>
      <form method="post" action="portal.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="answer">
        <input type="hidden" name="q" value="<?= $q ?>">
        <input type="hidden" name="id" value="<?= h($item['id']) ?>">
        <div class="answers">
          <?php foreach (CHECK_ANSWERS as $val => $lbl): ?>
            <button type="submit" name="answer" value="<?= h($val) ?>"<?= $cur === $val ? ' class="picked"' : '' ?>><?= h($lbl) ?></button>
          <?php endforeach; ?>
        </div>
      </form>
    <?php else: ?>
      <p class="muted">Your answer: <b><?= h(CHECK_ANSWERS[$cur] ?? 'Not answered') ?></b></p>
    <?php endif; ?>
    <div class="wizard-nav">
      <?php if ($q > 0): ?><a href="<?= h(portal_url('questions', ['q' => $q - 1])) ?>">← Back</a><?php else: ?><span></span><?php endif; ?>
      <a href="<?= h(portal_url('questions')) ?>">See all questions</a>
      <?php if ($q + 1 < count($items)): ?><a href="<?= h(portal_url('questions', ['q' => $q + 1])) ?>">Skip →</a><?php else: ?><a href="<?= h(portal_url('home')) ?>">Finish →</a><?php endif; ?>
    </div>
  </section>
  <?php if (in_array($item['area'], ['compliance', 'contracts'], true)): ?>
    <p class="disclaimer">This is general guidance for your industry, not legal advice.</p>
  <?php endif; ?>
<?php
    else:
        $firstOpen = 0;
        foreach ($items as $i => $it) {
            if (!isset($checklist[$it['id']])) { $firstOpen = $i; break; }
        }
?>
  <h1>Quick questions</h1>
  <p class="muted" style="margin:0 0 4px">This is your Back-Office Check: how your processes, follow-up, contracts and compliance are set up.</p>
  <p class="lead"><?= (int) $summary['answered'] ?> of <?= (int) $summary['total'] ?> answered · <?= h(industry_label($industry)) ?>
    · <a href="<?= h(portal_url('business')) ?>">change industry</a></p>
  <?php if ($active): ?>
    <p><a class="btn primary" href="<?= h(portal_url('questions', ['q' => $firstOpen])) ?>"><?= $summary['answered'] === 0 ? 'Start the questions' : 'Continue' ?></a></p>
  <?php endif; ?>
  <section class="card">
    <div class="table-wrap"><table>
      <tr><th>Question</th><th>Your answer</th><th></th></tr>
      <?php foreach ($items as $i => $it): ?>
        <tr>
          <td><?= h($it['q']) ?></td>
          <td><?= isset($checklist[$it['id']]) ? h(CHECK_ANSWERS[$checklist[$it['id']]]) : '<span class="muted">—</span>' ?></td>
          <td><a href="<?= h(portal_url('questions', ['q' => $i])) ?>"><?= isset($checklist[$it['id']]) ? 'Change' : 'Answer' ?></a></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  </section>
  <p class="disclaimer">Compliance questions are general guidance for your industry, not legal advice. Check the rules that apply to you with your attorney or compliance advisor.</p>
<?php
    endif;

/* ------------------------------ Tools ---------------------------- */
elseif ($page === 'tools'):
?>
  <h1>Your tools</h1>
  <p class="lead">List the software you pay for. We match it to your team's audit, so your report shows real savings, not guesses.</p>

  <?php if ($tools): ?>
    <div class="stats">
      <div class="stat"><b><?= h(money($toolsSum['monthly'])) ?></b><span>Total per month</span></div>
      <div class="stat"><b><?= h(money($toolsSum['monthly'] * 12)) ?></b><span>Total per year</span></div>
      <div class="stat"><b><?= h(money($toolsSum['unseen_cost'])) ?></b><span>Paid for, not seen in the audit</span></div>
    </div>
    <section class="card">
      <div class="table-wrap"><table>
        <tr><th>Tool</th><th>Per month</th><th>Seats</th><th>In the audit</th><th></th></tr>
        <?php foreach ($toolsSum['rows'] as $t): ?>
          <tr>
            <td><b><?= h($t['name']) ?></b><?= $t['note'] !== '' ? '<br><span class="muted small">' . h($t['note']) . '</span>' : '' ?></td>
            <td><?= h(money((float) $t['cost'])) ?></td>
            <td><?= $t['seats'] ? (int) $t['seats'] : '<span class="muted">—</span>' ?></td>
            <td><?= $t['minutes'] === null
                ? ($t['desktop'] ? '<span class="badge">Desktop app</span>' : ($audits ? '<span class="badge red">Not seen</span>' : '<span class="muted small">After your team sends audits</span>'))
                : '<span class="badge green">' . h(fmt_hours((float) $t['minutes'])) . ' hrs</span>' ?></td>
            <td><?php if ($active): ?><form class="inline" method="post" action="portal.php"><?= csrf_field() ?><input type="hidden" name="action" value="remove_tool"><input type="hidden" name="index" value="<?= (int) $t['index'] ?>"><button class="btn small" type="submit">Remove</button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
      <?php if ($audits && $toolsSum['unseen_cost'] > 0): ?>
        <p class="note">"Not seen" means no one opened it in a browser during the audit. It may be a desktop app, or it may be something you no longer need. Your report will point these out.</p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($active && $toolsSum['suggestions']): ?>
    <section class="card">
      <h2>Found in your team's audit</h2>
      <p class="muted">Your team used these. Add the ones you pay for.</p>
      <div class="table-wrap"><table>
        <tr><th>Tool</th><th>Time</th><th>Per month</th><th>Seats</th><th></th></tr>
        <?php foreach ($toolsSum['suggestions'] as $i => $sg): ?>
          <tr>
            <td><b><?= h($sg['name']) ?></b></td>
            <td><?= h(fmt_hours((float) $sg['minutes'])) ?> hrs</td>
            <td colspan="3">
              <form class="inline-form" method="post" action="portal.php">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_tool"><input type="hidden" name="name" value="<?= h($sg['name']) ?>">
                <input type="text" name="cost" inputmode="decimal" placeholder="$ / month" aria-label="Monthly cost for <?= h($sg['name']) ?>">
                <input type="text" name="seats" inputmode="numeric" placeholder="Seats" aria-label="Seats for <?= h($sg['name']) ?>">
                <button class="btn small" type="submit">Add</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    </section>
  <?php endif; ?>

  <?php if ($active): ?>
    <section class="card">
      <h2>Add a tool</h2>
      <p class="muted small">Include desktop apps and phone systems too. The audit only sees websites, so this is how they get counted.</p>
      <form method="post" action="portal.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_tool">
        <div class="row">
          <label>Tool name<input type="text" name="name" required maxlength="80" placeholder="e.g. QuickBooks"></label>
          <label>Cost per month<input type="text" name="cost" inputmode="decimal" placeholder="e.g. $90"></label>
          <label>Seats<input type="text" name="seats" inputmode="numeric" placeholder="e.g. 3"></label>
          <label>Note (optional)<input type="text" name="note" maxlength="120" placeholder="e.g. desktop app"></label>
        </div>
        <button class="btn primary" type="submit">Add tool</button>
      </form>
    </section>
  <?php endif; ?>

<?php
/* ------------------------------ Team ----------------------------- */
elseif ($page === 'team' && $solo):
?>
  <h1>Your extension</h1>
  <p class="lead">Connect the extension on your computer, work like normal for a few days, then send your audit here.</p>
  <section class="card">
    <ol class="steps">
      <li>Open the <b>Workflow Shadow Audit</b> extension in Chrome<?= cfg_opt('extension_url', '') ? ' (<a href="' . h((string) cfg_opt('extension_url', '')) . '" target="_blank" rel="noopener">add it here</a>)' : '' ?>.</li>
      <li>Click <b>Have a team key?</b> and paste your key:</li>
    </ol>
    <div class="key" id="teamKey"><?= h($license['license_key']) ?></div>
    <button class="btn primary" type="button" data-copy="teamKey">Copy key</button>
    <ol class="steps" start="3">
      <li>Turn tracking on and work like normal. There's no time limit, so a full week gives the clearest picture.</li>
      <li>Click <b>Send to my Business Portal</b> whenever you like. You can send again later.</li>
    </ol>
    <p class="muted small">Already ran the free audit? Your tracked hours carry over when you paste the key.</p>
  </section>
  <section class="card">
    <h2>Received</h2>
    <p><?= h($coverage['text']) ?></p>
  </section>

<?php
elseif ($page === 'team'):
    $invite = "Hi team! We're running a quick audit to find work we can automate. It's private: it only sees website names and time spent, never what you type.\n\n"
        . "It takes 2 minutes to join: " . join_url($license) . "\n\nAfter that, just work like normal. Thanks!";
?>
  <h1>Your team</h1>
  <p class="lead">Everyone who uses your team key is part of <?= h($company) ?>'s audit. All their results come here.</p>

  <section class="card">
    <h2>Invite your team</h2>
    <p class="muted">Send this message to each person you want included. The link shows them the 3 steps.</p>
    <textarea id="inviteMsg" readonly rows="7" style="min-height:190px"><?= h($invite) ?></textarea>
    <div class="actions" style="margin-top:10px">
      <button class="btn primary" type="button" data-copy="inviteMsg">Copy message</button>
      <a class="btn" href="<?= h(join_url($license)) ?>" target="_blank" rel="noopener">Preview the invite page</a>
    </div>
    <p class="muted small" style="margin-top:12px">Team key: <code id="teamKey"><?= h($license['license_key']) ?></code>
      · works for up to <?= (int) $license['seats'] ?> people (one per computer).</p>
  </section>

  <section class="card">
    <h2>Who's connected (<?= count($devices) ?> of <?= (int) $license['seats'] ?>)</h2>
    <?php if (!$devices): ?>
      <p class="muted">No one yet. People show up here as soon as they paste the team key into the extension.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>Role</th><th>Joined</th><th>Last active</th><th>Audit sent?</th></tr>
        <?php foreach ($devices as $d): ?>
          <tr>
            <td><?= h($d['role_tag'] ?: 'Not set') ?></td>
            <td><?= h(format_date($d['first_seen'])) ?></td>
            <td><?= h(format_date($d['last_seen'])) ?></td>
            <td><?= (int) $d['audits_sent'] > 0 ? '<span class="badge green">Yes</span>' : '<span class="badge">Not yet</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
      <p class="muted small">Someone missing? Ask them to open the extension and click <b>Send to my Business Portal</b>.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <details>
      <summary>Audits received (<?= count($audits) ?>)</summary>
      <?php if (!$audits): ?>
        <p class="muted">None yet.</p>
      <?php else: ?>
        <div class="table-wrap"><table>
          <tr><th>Date</th><th>Role</th><th>Hours</th><th></th></tr>
          <?php foreach ($audits as $a): ?>
            <tr><td><?= h(format_date($a['created_at'])) ?></td><td><?= h($a['role_tag']) ?></td><td><?= h(fmt_hours((float) $a['total_minutes'])) ?></td>
              <td><?php if ($active): ?><form class="inline" method="post" action="portal.php" onsubmit="return confirm('Remove this audit?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_audit"><input type="hidden" name="audit_id" value="<?= (int) $a['id'] ?>"><button class="btn small danger" type="submit">Remove</button></form><?php endif; ?></td></tr>
          <?php endforeach; ?>
        </table></div>
      <?php endif; ?>
    </details>
    <?php if ($active): ?>
      <details>
        <summary>Add an audit from a file</summary>
        <form method="post" action="portal.php" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="upload_audit">
          <label>Audit file <span class="hint">From "Download my data" in the extension.</span><input type="file" name="audit_file" accept="application/json,.json" required></label>
          <button class="btn" type="submit">Add file</button>
        </form>
      </details>
    <?php endif; ?>
  </section>

<?php
/* ----------------------------- Report ---------------------------- */
elseif ($page === 'report'):
?>
  <h1>Your report</h1>
  <?php if ($audits): ?>
    <p class="coverage<?= $coverage['enough'] ? '' : ' thin' ?>"><?= h($coverage['text']) ?>
      <?php if (!$coverage['enough']): ?> For a sharper report, aim for at least <?= (int) $coverage['minimum'] ?> hours.<?php endif; ?></p>
  <?php endif; ?>
  <?php if ($finalPlan): ?>
    <section class="card">
      <span class="badge green">Final plan from our team · <?= h(format_date($finalPlan['created_at'])) ?></span>
      <?= render_markdown($finalPlan['content']) ?>
    </section>
  <?php endif; ?>

  <section class="card">
    <?php if ($aiReport): ?>
      <span class="badge">Instant AI report · <?= h(format_date($aiReport['created_at'])) ?></span>
      <?= render_markdown($aiReport['content']) ?>
    <?php elseif (!$audits): ?>
      <h2>Almost there</h2>
      <p class="muted"><?= $solo ? 'Your report is ready as soon as you send in your audit from the extension.' : 'Your report is ready as soon as your team sends in their audits.' ?>
        <a href="<?= h(portal_url('team')) ?>"><?= $solo ? 'How to connect' : 'Invite your team' ?></a>.</p>
    <?php else: ?>
      <h2><?= $solo ? 'Your results are in' : "Your team's results are in" ?></h2>
      <p class="muted">Get your instant AI report now. It takes 1–2 minutes. <?= $finalIncluded ? 'Our team then follows up with your final plan.' : 'Then book a free call to go over it with us.' ?></p>
      <?php if ($summary['answered'] === 0): ?>
        <p class="note">Tip: <a href="<?= h(portal_url('questions')) ?>">answer the quick questions</a> first so your report covers your back office too.</p>
      <?php endif; ?>
    <?php endif; ?>

    <div class="actions" style="margin-top:14px">
      <?php if ($audits && $active && ai_reports_enabled()): ?>
        <form class="inline" method="post" action="portal.php" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='Writing your report… about 1–2 minutes';">
          <?= csrf_field() ?><input type="hidden" name="action" value="generate_report">
          <button class="btn primary" type="submit"><?= $aiReport ? 'Update my instant AI report' : 'Get my instant AI report' ?></button>
        </form>
      <?php endif; ?>
      <a class="btn" href="<?= h((string) cfg('booking_url')) ?>" target="_blank" rel="noopener">Book a call to build these agents →</a>
    </div>
    <p class="muted small">If we build your agents, your audit price is credited toward the build.</p>
  </section>

  <?php if ($aiReport && !feedback_given($lid, 'owner')): ?>
    <section class="card" id="survey">
      <h2>Is this report right?</h2>
      <p class="muted">Two quick questions. We use your answers when we <?= $finalIncluded ? 'write your final plan' : 'go over it with you' ?>.</p>
      <form method="post" action="portal.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="owner_survey">
        <p class="q">Did it find something real?</p>
        <div class="choice">
          <?php foreach (ACCURACY_ANSWERS as $k => $lbl): ?><label class="pill-radio"><input type="radio" name="accurate" value="<?= h($k) ?>" required><span><?= h($lbl) ?></span></label><?php endforeach; ?>
        </div>
        <p class="q">How likely are you to recommend us? <span class="muted small">(0 = not at all, 10 = very)</span></p>
        <div class="choice nps">
          <?php for ($n = 0; $n <= 10; $n++): ?><label class="pill-radio"><input type="radio" name="nps" value="<?= $n ?>"><span><?= $n ?></span></label><?php endfor; ?>
        </div>
        <label>What should we correct or add? <span class="hint">Optional</span><textarea name="comment" maxlength="2000" rows="3"></textarea></label>
        <label class="check-line"><input type="checkbox" name="wants_help" value="yes"> I'd like help fixing this</label>
        <button class="btn primary" type="submit">Send</button>
      </form>
    </section>
  <?php endif; ?>

  <details>
    <summary>Copy the report prompt for ChatGPT or Claude</summary>
    <?php render_prompt_box(build_business_prompt($license, $profile, aggregate_audits($audits), $checklist, $tools)); ?>
  </details>

<?php
/* ---------------------------- Journey ---------------------------- */
elseif ($page === 'journey'):
    $c = $chainStatus['counts'];
    $statusWord = ['ok' => 'Automatic', 'manual' => 'By hand', 'weak' => 'Sometimes', 'broken' => 'Broken', 'unknown' => 'Not answered'];
?>
  <h1>Your client journey</h1>
  <p class="lead">From a new lead to a paid invoice: does each step trigger the next one? This is where things usually fall through the cracks.</p>

  <section class="card">
    <div class="chain" aria-label="Your client journey at a glance">
      <?php $i = 0; foreach ($chainStatus['rows'] as $r): ?>
        <?php if ($i++ > 0): ?><span class="arrow" aria-hidden="true">→</span><?php endif; ?>
        <span class="link <?= h($r['status']) ?>" title="<?= h($statusWord[$r['status']]) ?>"><?= h($r['label']) ?></span>
      <?php endforeach; ?>
    </div>
    <p class="muted small legend"><span class="dot ok"></span> Automatic · <span class="dot manual"></span> By hand or sometimes · <span class="dot broken"></span> Broken · <span class="dot unknown"></span> Not answered
      <?php if ($c['unknown'] < count($chainStatus['rows'])): ?> — <b><?= (int) $c['ok'] ?></b> automatic, <b><?= (int) ($c['manual'] + $c['weak']) ?></b> by hand or sometimes, <b><?= (int) $c['broken'] ?></b> broken<?php endif; ?></p>
  </section>

  <form method="post" action="portal.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_chain">
    <?php $n = 0; foreach ($chainStatus['rows'] as $id => $r): $n++; ?>
      <section class="card link-card <?= h($r['status']) ?>">
        <div class="link-head"><span class="link-num"><?= $n ?></span><div><b><?= h($r['label']) ?></b><div class="muted"><?= h($r['q']) ?></div></div></div>
        <div class="choice">
          <?php foreach (CHAIN_ANSWERS as $k => $lbl): ?>
            <label class="pill-radio"><input type="radio" name="link_<?= h($id) ?>" value="<?= h($k) ?>"<?= $r['answer'] === $k ? ' checked' : '' ?><?= $dis ?>><span><?= h($lbl) ?></span></label>
          <?php endforeach; ?>
        </div>
        <?php if ($r['evidence']): ?><p class="evidence"><b>What the audit saw:</b> <?= h($r['evidence']['text']) ?></p><?php endif; ?>
        <?php if ($r['test']): ?>
          <p class="tested <?= $r['test']['result'] === 'pass' ? 'pass' : 'fail' ?>"><b>Tested by our team (<?= h($r['test']['label']) ?>):</b> <?= h(TEST_RESULTS[$r['test']['result']]) ?><?= $r['test']['detail'] !== '' ? ' · ' . h($r['test']['detail']) : '' ?></p>
        <?php endif; ?>
        <?php if (in_array($r['status'], ['manual', 'weak', 'broken'], true)): ?>
          <p class="muted small"><b>Fix:</b> <?= h($r['fix']) ?> <span class="badge"><?= h($r['agent']) ?></span></p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
    <?php if ($active): ?><button class="btn primary" type="submit">Save</button><?php endif; ?>
  </form>

<?php
/* ------------------------------ Help ----------------------------- */
elseif ($page === 'help'):
?>
  <h1>Help</h1>
  <section class="card">
    <h2>Common questions</h2>
    <?php include __DIR__ . '/includes/faq.php'; ?>
  </section>
  <section class="card">
    <h2>Talk to us</h2>
    <p class="muted">Questions, or want help with your plan?</p>
    <div class="actions">
      <a class="btn primary" href="<?= h((string) cfg('booking_url')) ?>" target="_blank" rel="noopener">Book a call</a>
      <span class="muted">or email <b><?= h((string) cfg('owner_email')) ?></b></span>
    </div>
  </section>
  <section class="card" id="refund">
    <h2>Our guarantee</h2>
    <p><?= h(guarantee_text()) ?></p>
    <details>
      <summary>Ask for your money back</summary>
      <form method="post" action="portal.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="request_refund">
        <label>Anything we should know? <span class="hint">Optional</span><textarea name="reason" maxlength="1000" rows="3"></textarea></label>
        <button class="btn" type="submit">Request a refund</button>
      </form>
    </details>
  </section>
  <section class="card">
    <h2>Your data</h2>
    <p class="muted">Download everything we have about your audit, or delete it.</p>
    <form class="inline" method="post" action="portal.php"><?= csrf_field() ?><input type="hidden" name="action" value="download_data"><button class="btn" type="submit">Download my data</button></form>
    <details>
      <summary>Delete my company's data</summary>
      <p class="muted small">This deletes your audits, answers, tools, reports and connected computers. It can't be undone. We keep only your purchase record for receipts.</p>
      <form method="post" action="portal.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_data">
        <label>Type DELETE to confirm<input type="text" name="confirm" autocomplete="off" required></label>
        <button class="btn danger" type="submit">Delete everything</button>
      </form>
    </details>
  </section>
  <section class="card">
    <h2>Account</h2>
    <div class="actions">
      <?php if (!empty($license['stripe_customer_id'])): ?>
        <form class="inline" method="post" action="billing.php"><?= csrf_field() ?><button class="btn" type="submit">Billing &amp; invoices</button></form>
      <?php endif; ?>
      <form class="inline" method="post" action="portal.php"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn" type="submit">Sign out</button></form>
    </div>
  </section>

<?php
/* ---------------------------- Numbers ---------------------------- */
else:
?>
  <h1>Numbers</h1>
  <p class="lead">The details behind your report.</p>
  <section class="card">
    <h2>Back office · <?= h(industry_label($industry)) ?></h2>
    <?php render_checklist_summary($summary); ?>
    <p class="disclaimer">Compliance items are general guidance for your industry, not legal advice.</p>
  </section>
  <section class="card">
    <h2>Software spend</h2>
    <?php if ($tools): ?>
      <p><b><?= h(money($toolsSum['monthly'])) ?></b> a month on <?= count($tools) ?> tools · <b><?= h(money($toolsSum['unseen_cost'])) ?></b> on tools not seen in the audit. <a href="<?= h(portal_url('tools')) ?>">See your tools</a></p>
    <?php else: ?>
      <p class="muted"><a href="<?= h(portal_url('tools')) ?>">List your tools</a> to see your software spend here.</p>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>What your team's browsers show</h2>
    <?php render_telemetry(aggregate_audits($audits), $audits, false); ?>
  </section>
  <section class="card">
    <h2>Month by month</h2>
    <?php $months = progress_by_month($audits); if ($months) { render_progress($months); } else { echo '<p class="muted">Shows up after your first audit.</p>'; } ?>
  </section>
<?php
endif;

render_footer();

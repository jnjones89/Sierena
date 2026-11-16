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
        go('home', 'Your subscription has ended. Restart it on the Home page to keep going.', 'error');
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

    if ($action === 'save_station') {
        $sid = (string) ($_POST['station'] ?? '');
        $ids = array_keys(stations());
        if (!in_array($sid, $ids, true)) {
            go('journey');
        }
        $all = load_stations($lid);
        $all[$sid] = clean_station_input($sid, $_POST);
        $chain = load_chain($lid);
        foreach (chain_links(load_profile($lid)['industry'] ?: 'other') as $linkId => $link) {
            $v = (string) ($_POST['link_' . $linkId] ?? '');
            if ($link['station'] === $sid && isset(CHAIN_ANSWERS[$v])) {
                $chain[$linkId] = $v;
            }
        }
        write_profile_data($lid, array_merge(profile_data($lid), ['stations' => $all, 'chain' => $chain]));
        $next = $ids[array_search($sid, $ids, true) + 1] ?? null;
        if (($_POST['go'] ?? '') === 'stay') {
            go('journey', 'Saved.', 'ok', ['s' => $sid]);
        }
        if ($next) {
            go('journey', '', 'ok', ['s' => $next]);
        }
        go('journey', $next ? 'Saved.' : "That's all 7 stations. Thank you!");
    }

    if ($action === 'crm_connect') {
        $err = crm_connect($lid, (string) ($_POST['location_id'] ?? ''), (string) ($_POST['token'] ?? ''));
        if ($err !== '') {
            go('crm', $err, 'error');
        }
        go('crm', 'Connected. Your CRM numbers are now on your Client journey.');
    }

    if ($action === 'crm_refresh') {
        $last = crm_ts(crm_load($lid)['stats']['checked_at'] ?? null) ?? 0;
        if ($last > time() - 600) {
            go('crm', 'Your numbers were checked a few minutes ago. Try again in 10 minutes.', 'error');
        }
        $err = crm_refresh($lid);
        go('crm', $err !== '' ? $err : 'Checked again. Your numbers are up to date.', $err !== '' ? 'error' : 'ok');
    }

    if ($action === 'crm_disconnect') {
        crm_save($lid, null);
        go('crm', 'Disconnected. We deleted your token and your CRM numbers.');
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
        if (reports_left($license) <= 0) {
            go('report', reports_today($lid) >= (int) cfg_opt('reports_per_day', 5)
                ? "You've reached today's limit. Please try again tomorrow."
                : "You've used all the instant AI reports included with your audit. Our team's final plan and the copy-and-paste prompt are still available.", 'error');
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
$page       = isset(PORTAL_PAGES[$page]) || $page === 'crm' ? $page : 'home';
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
$company    = $profile['company'] ?: ($license['company'] ?: 'your business');
$tools      = load_tools($lid);
$toolsSum   = tools_summary($tools, aggregate_audits($audits));
$solo       = license_is_solo($license);
$journeyAll = journey_full($lid, $industry, aggregate_audits($audits));
$chainStatus = $journeyAll['chain'];
$stStatus   = $journeyAll['stations'];
$crm        = $journeyAll['crm'];
$coverage   = data_coverage($license, $audits, $devices);
$finalIncluded = plan_includes_final_plan($license);
$dis        = $active ? '' : ' disabled';

/* ----------------------------- Layout ---------------------------- */

echo '<nav class="tabs" aria-label="Portal">';
foreach (PORTAL_PAGES as $key => $label) {
    if ($key === 'team' && $solo) {
        $label = 'Your extension';
    }
    echo '<a href="' . h(portal_url($key)) . '"' . (($key === $page || ($key === 'journey' && $page === 'crm')) ? ' class="on" aria-current="page"' : '') . '>' . h($label) . '</a>';
}
echo '</nav>';
$showFlash();

if (!$active) {
    echo '<div class="note">Your subscription has ended. Everything is still here to read. To keep auditing, '
        . '<b>restart your subscription</b> on the Home page.</div>';
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
            'done'  => $stStatus['done'] === $stStatus['total'], 'title' => 'Map your client journey',
            'text'  => $stStatus['done'] === $stStatus['total']
                ? $stStatus['counts']['ok'] . ' green · ' . $stStatus['counts']['weak'] . ' amber · ' . $stStatus['counts']['broken'] . ' red. From first lead to referral.'
                : $stStatus['done'] . ' of ' . $stStatus['total'] . ' stations done. From first lead to referral: each step, who owns it, and what is automated. About 8 minutes.',
            'page'  => 'journey', 'cta' => $stStatus['done'] === 0 ? 'Start' : ($stStatus['done'] === $stStatus['total'] ? 'Review' : 'Continue'),
        ],
        [
            'done'  => $reportDone, 'title' => 'Get your report',
            'text'  => $finalPlan ? 'Your final plan from our team is ready.'
                : ($aiReport ? ($finalIncluded ? 'Your instant AI report is ready. Our team is preparing your final plan.' : 'Your instant AI report is ready. Book a free call to go over it.')
                : (($audits && !ai_reports_enabled()) ? ($finalIncluded ? 'Our team is writing your final plan (within 2 business days). Want a draft now? Open your report.' : 'Your results are in. Make your AI report and book a free call.')
                : ($solo ? 'Ready as soon as you send in your audit.' : 'Ready as soon as your team sends in their audits.'))),
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
  <p class="lead"><?= h(plan_name($license)) ?> · <?= $monitoring ? h(monitoring_tier($license)['price_label']) . ', renews monthly' : ($active ? 'open until ' . h(format_date($license['access_until'])) : 'subscription ended') ?></p>
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
    <p class="muted">We build the AI agents in your plan. Your first month is credited toward the build.</p>
    <a class="btn" href="<?= h((string) cfg('booking_url')) ?>" target="_blank" rel="noopener">Book a call →</a>
  </section>

  <section class="card">
    <h2>Your subscription <span class="badge"><?= h(monitoring_tier($license)['price_label']) ?></span></h2>
    <?php if ($monitoring): ?>
      <p class="muted">On. Each month, ask your team to send in their audits<?= !empty($crm['stats']) ? ' and press <b>Check again</b> on your CRM numbers' : '' ?>.
        See what improved on the <a href="<?= h(portal_url('numbers')) ?>">Numbers</a> page. Change or cancel under <b>Billing &amp; invoices</b>.</p>
    <?php else: ?>
      <p class="muted">Restart any time with the same key. Your history is still here, so Before → Now picks up where you left off.</p>
      <form method="post" action="monitor.php"><?= csrf_field() ?><button class="btn primary" type="submit">Restart my subscription</button></form>
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
    <?php elseif (!ai_reports_enabled()): ?>
      <h2><?= $solo ? 'Your results are in' : "Your team's results are in" ?></h2>
      <?php if ($finalPlan): ?>
        <p class="muted">Your final plan is above. Want another angle? Make an AI draft yourself below.</p>
      <?php elseif ($finalIncluded): ?>
        <p>Our team is writing your final plan. It will be here within 2 business days, and we'll email you when it's ready.</p>
      <?php else: ?>
        <p>Make your AI report below in about a minute, then book a free call and we'll go over it with you.</p>
      <?php endif; ?>
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
          <button class="btn primary" type="submit"<?= reports_left($license) <= 0 ? ' disabled' : '' ?>><?= $aiReport ? 'Update my instant AI report' : 'Get my instant AI report' ?></button>
        </form>
        <span class="muted small"><?= reports_left($license) ?> left<?= license_monitoring($license) ? ' this month' : '' ?></span>
      <?php endif; ?>
      <a class="btn" href="<?= h((string) cfg('booking_url')) ?>" target="_blank" rel="noopener">Book a call to build these agents →</a>
    </div>
    <p class="muted small">If we build your agents, your first month is credited toward the build.</p>
  </section>

  <?php if ($audits && !ai_reports_enabled()): ?>
    <section class="card" id="draft">
      <h2>Get an AI report draft now</h2>
      <p class="muted">Use your own ChatGPT or Claude. Your results are already filled in.</p>
      <ol class="steps">
        <li>Click <b>Copy report prompt</b> below.</li>
        <li>Open <a href="https://chatgpt.com" target="_blank" rel="noopener">ChatGPT</a> or <a href="https://claude.ai" target="_blank" rel="noopener">Claude</a> (free accounts work).</li>
        <li>Paste it in and send. Your report appears in about a minute.</li>
      </ol>
      <?php render_prompt_box(build_business_prompt($license, $profile, aggregate_audits($audits), $checklist, $tools)); ?>
    </section>
  <?php endif; ?>

  <?php if (($aiReport || (!ai_reports_enabled() && ($finalPlan || ($audits && !$finalIncluded)))) && !feedback_given($lid, 'owner')): ?>
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

  <?php if (ai_reports_enabled()): ?>
  <details>
    <summary>Copy the report prompt for ChatGPT or Claude</summary>
    <?php render_prompt_box(build_business_prompt($license, $profile, aggregate_audits($audits), $checklist, $tools)); ?>
  </details>
  <?php endif; ?>

<?php
/* ---------------------------- Stations --------------------------- */
elseif ($page === 'journey' || $page === 'stations'):
    $sid = (string) ($_GET['s'] ?? '');
    $ids = array_keys(stations());
    $statusWord = ['ok' => 'Green', 'weak' => 'Needs work', 'broken' => 'Broken', 'unknown' => 'Not answered'];
    if (!in_array($sid, $ids, true)):
        $firstOpen = $ids[0];
        foreach ($stStatus['rows'] as $id => $r) {
            if (!$r['complete']) { $firstOpen = $id; break; }
        }
?>
  <h1>Your client journey</h1>
  <p class="lead">From the first lead to a referral, every business has the same 7 stations. At each one we check the steps,
    and three things: is the <b>process</b> defined, who <b>owns</b> it, and is it <b>automated</b>?</p>
  <?php if ($active): ?>
    <p><a class="btn primary" href="<?= h(portal_url('journey', ['s' => $firstOpen])) ?>"><?= $stStatus['done'] === 0 ? 'Start with station 1' : ($stStatus['done'] === $stStatus['total'] ? 'Review your answers' : 'Continue') ?></a>
      <span class="muted small"><?= (int) $stStatus['done'] ?> of <?= (int) $stStatus['total'] ?> done</span></p>
  <?php endif; ?>
  <div class="stations">
    <?php $n = 0; foreach ($stStatus['rows'] as $id => $r): $n++; $a = $r['answers']; ?>
      <a class="station-tile <?= h($r['status']) ?>" href="<?= h(portal_url('journey', ['s' => $id])) ?>">
        <span class="st-num"><?= $n ?></span>
        <b><?= h($r['title']) ?></b>
        <span class="st-status"><?= h($statusWord[$r['status']]) ?><?= $r['score'] !== null ? ' · ' . (int) $r['score'] . '%' : '' ?></span>
        <span class="st-three">
          <span title="Process">P: <?= h(STATION_PROCESS[$a['process'] ?? ''] ?? '—') ?></span>
          <span title="Owner">O: <?= h(STATION_OWNER[$a['owner'] ?? ''] ?? '—') ?></span>
          <span title="Automation">A: <?= h(STATION_AUTOMATION[$a['automation'] ?? ''] ?? '—') ?></span>
        </span>
        <?php $notes = count($r['flags']) + count($r['evidence']) + count($r['tests']) + count($r['crm']); if ($notes): ?><span class="st-note"><?= $notes ?> note<?= $notes === 1 ? '' : 's' ?><?= $r['crm'] ? ' · CRM' : '' ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <section class="card" style="margin-top:16px">
    <h2>Step by step</h2>
    <div class="chain">
      <?php $i = 0; foreach ($chainStatus['rows'] as $cr): ?><?php if ($i++ > 0): ?><span class="arrow" aria-hidden="true">→</span><?php endif; ?><span class="link <?= h($cr['status']) ?>"><?= h($cr['label']) ?></span><?php endforeach; ?>
    </div>
    <p class="muted small legend"><span class="dot ok"></span> Automatic · <span class="dot manual"></span> By hand or sometimes · <span class="dot broken"></span> Broken · <span class="dot unknown"></span> Not answered</p>
  </section>
  <section class="card crm-card">
    <?php if (!empty($crm['stats'])): ?>
      <h2>Your CRM is connected</h2>
      <p class="muted">Real numbers from your GoHighLevel are on the stations above, marked <b>From your CRM</b>. Last checked <?= h(date('M j, g:i a', crm_ts($crm['stats']['checked_at']) ?: time())) ?>.</p>
      <p><a class="btn" href="<?= h(portal_url('crm')) ?>">See your CRM numbers</a></p>
    <?php else: ?>
      <h2>Use GoHighLevel? Add your real numbers (optional)</h2>
      <p class="muted">Connect it read-only and we check the last 90 days: how fast new leads get a reply, how many follow-ups they get,
        your show rate and your close rate. We keep the numbers only. Nothing in your CRM is changed.</p>
      <p><a class="btn" href="<?= h(portal_url('crm')) ?>">Connect GoHighLevel</a></p>
    <?php endif; ?>
  </section>
<?php
    else:
        $r   = $stStatus['rows'][$sid];
        $a   = $r['answers'];
        $pos = array_search($sid, $ids, true);
        $radio = function (string $name, array $options, string $cur) use ($dis) {
            $out = '<div class="choice">';
            foreach ($options as $k => $lbl) {
                $out .= '<label class="pill-radio"><input type="radio" name="' . h($name) . '" value="' . h($k) . '"' . ($cur === $k ? ' checked' : '') . $dis . '><span>' . h($lbl) . '</span></label>';
            }
            return $out . '</div>';
        };
?>
  <p class="muted small" style="margin:0">Station <?= $pos + 1 ?> of <?= count($ids) ?> · <a href="<?= h(portal_url('journey')) ?>">All stations</a></p>
  <div class="progress"><div style="width:<?= (int) round(100 * $pos / count($ids)) ?>%"></div></div>
  <h1><?= h($r['title']) ?></h1>
  <p class="lead"><?= h($r['check']) ?></p>

  <form method="post" action="portal.php">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_station"><input type="hidden" name="station" value="<?= h($sid) ?>">
    <section class="card">
      <h2>A few quick facts</h2>
      <?php foreach ($r['facts'] as [$key, $q, $type, $options]): $v = $a['facts'][$key] ?? ($type === 'multi' ? [] : ''); ?>
        <p class="q"><?= h($q) ?><?= $type === 'multi' ? ' <span class="muted small">(pick all that apply)</span>' : '' ?></p>
        <?php if ($type === 'number'): ?>
          <input class="pct" type="text" inputmode="numeric" name="f_<?= h($key) ?>" value="<?= h((string) $v) ?>" placeholder="e.g. 70" aria-label="<?= h($q) ?>"<?= $dis ?>> <span class="muted">%</span>
        <?php elseif ($type === 'multi'): ?>
          <div class="choice">
            <?php foreach ($options as $k => $lbl): ?><label class="pill-radio"><input type="checkbox" name="f_<?= h($key) ?>[]" value="<?= h($k) ?>"<?= in_array($k, (array) $v, true) ? ' checked' : '' ?><?= $dis ?>><span><?= h($lbl) ?></span></label><?php endforeach; ?>
          </div>
        <?php else: ?>
          <?= $radio('f_' . $key, $options, (string) $v) ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </section>

    <?php if ($r['links']): ?>
      <section class="card">
        <h2>Step by step</h2>
        <p class="muted small">How does each step happen today?</p>
        <?php foreach ($r['links'] as $linkId => $lr): ?>
          <div class="link-row">
            <p class="q"><?= h($lr['label']) ?> <span class="muted small" style="font-weight:400"><?= h($lr['q']) ?></span></p>
            <?= $radio('link_' . $linkId, CHAIN_ANSWERS, (string) $lr['answer']) ?>
            <?php if ($lr['evidence']): ?><p class="evidence"><b>What the audit saw:</b> <?= h($lr['evidence']['text']) ?></p><?php endif; ?>
            <?php if (!empty($lr['crm'])): ?><p class="evidence crm <?= h($lr['crm']['level']) ?>"><b>From your CRM:</b> <?= h($lr['crm']['text']) ?></p><?php endif; ?>
            <?php if ($lr['test']): ?><p class="tested <?= $lr['test']['result'] === 'pass' ? 'pass' : 'fail' ?>"><b>Tested by our team (<?= h($lr['test']['label']) ?>):</b> <?= h(TEST_RESULTS[$lr['test']['result']]) ?><?= $lr['test']['detail'] !== '' ? ' · ' . h($lr['test']['detail']) : '' ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>Three questions</h2>
      <p class="q">Process: is it defined, or improvised?</p>
      <?= $radio('process', STATION_PROCESS, (string) ($a['process'] ?? '')) ?>
      <p class="q">Owner: who is responsible?</p>
      <?= $radio('owner', STATION_OWNER, (string) ($a['owner'] ?? '')) ?>
      <label style="margin-top:8px">Name or role <span class="hint">Optional, e.g. "Maria, office manager"</span><input type="text" name="owner_name" maxlength="80" value="<?= h((string) ($a['owner_name'] ?? '')) ?>"<?= $dis ?>></label>
      <p class="q">Automation: is it automated, manual, or missing?</p>
      <?= $radio('automation', STATION_AUTOMATION, (string) ($a['automation'] ?? '')) ?>
    </section>

    <?php if ($r['flags'] || $r['evidence'] || $r['tests'] || $r['crm'] || in_array($r['status'], ['weak', 'broken'], true)): ?>
      <section class="card">
        <h2>What we noticed</h2>
        <?php $shownCrm = array_filter(array_map(fn($lr) => $lr['crm']['text'] ?? null, $r['links'])); ?>
        <?php foreach ($r['crm'] as $cn): if (in_array($cn['text'], $shownCrm, true)) { continue; } ?><p class="evidence crm <?= h($cn['level']) ?>"><b>From your CRM:</b> <?= h($cn['text']) ?></p><?php endforeach; ?>
        <?php foreach ($r['flags'] as $f): ?><p class="evidence"><?= h($f) ?></p><?php endforeach; ?>
        <?php foreach ($r['evidence'] as $e): ?><p class="evidence"><b>What the audit saw:</b> <?= h($e) ?></p><?php endforeach; ?>
        <?php $shownTests = array_filter(array_map(fn($lr) => $lr['test']['label'] ?? null, $r['links'])); ?>
        <?php foreach (array_filter($r['tests'], fn($t) => !in_array($t['label'], $shownTests, true)) as $t): ?><p class="tested <?= $t['result'] === 'pass' ? 'pass' : 'fail' ?>"><b>Tested by our team (<?= h($t['label']) ?>):</b> <?= h(TEST_RESULTS[$t['result']]) ?><?= $t['detail'] !== '' ? ' · ' . h($t['detail']) : '' ?></p><?php endforeach; ?>
        <?php if (in_array($r['status'], ['weak', 'broken'], true)): ?>
          <p class="muted small"><b>Fix:</b> <?= h($r['fix']) ?> <span class="badge"><?= h($r['agent']) ?></span></p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <div class="wizard-nav">
      <?php if ($pos > 0): ?><a href="<?= h(portal_url('journey', ['s' => $ids[$pos - 1]])) ?>">← Back</a><?php else: ?><span></span><?php endif; ?>
      <?php if ($active): ?>
        <span class="actions">
          <button class="btn" type="submit" name="go" value="stay">Save</button>
          <button class="btn primary" type="submit" name="go" value="next"><?= $pos + 1 < count($ids) ? 'Save and next →' : 'Save and finish' ?></button>
        </span>
      <?php endif; ?>
    </div>
  </form>
<?php
    endif;

/* ------------------------------ CRM ------------------------------ */
elseif ($page === 'crm'):
    $cs = $crm['stats'] ?? null;
?>
  <p class="muted small" style="margin:0"><a href="<?= h(portal_url('journey')) ?>">← Client journey</a></p>
  <h1>Your CRM numbers</h1>
  <?php if (!$cs): ?>
    <p class="lead">Connect your GoHighLevel and we check the last 90 days. The numbers show up on your Client journey, next to your answers.</p>
    <section class="card">
      <h2>What we check</h2>
      <ul class="clean">
        <li><b>Speed to lead:</b> how fast new leads get their first reply, and how many never get one.</li>
        <li><b>Follow-up:</b> how many times new leads hear from you in their first 30 days, and on which channels.</li>
        <li><b>Booking:</b> show rate, no-shows, and whether no-shows book again.</li>
        <li><b>Pipeline:</b> close rate, and open deals that haven't moved in 30 days.</li>
        <li><b>Lead sources:</b> where new contacts come from.</li>
      </ul>
      <p class="crm-trust">Read-only: nothing in your CRM is changed. We keep the numbers only, never names, emails, phone numbers or message text.
        Your token is stored encrypted, and you can disconnect at any time.</p>
    </section>
    <section class="card">
      <h2>Connect in 3 steps</h2>
      <ol class="steps-plain">
        <li>In GoHighLevel, open your sub-account and go to <b>Settings → Private Integrations</b>. Click <b>Create new integration</b> and name it "Workflow Shadow Audit".</li>
        <li>Turn on these <b>read-only</b> scopes only: <?= h(implode(', ', CRM_SCOPES)) ?>. Copy the token.</li>
        <li>Find your <b>Location ID</b> in <b>Settings → Business Profile</b>, then paste both below.</li>
      </ol>
      <?php if ($active): ?>
        <form method="post" action="portal.php" class="crm-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="crm_connect">
          <label>Location ID<input type="text" name="location_id" maxlength="40" autocomplete="off" required></label>
          <label>Private Integration token<input type="password" name="token" maxlength="400" autocomplete="off" required></label>
          <button class="btn primary" type="submit">Connect and check my numbers</button>
          <p class="muted small">This takes up to a minute.</p>
        </form>
      <?php endif; ?>
    </section>
  <?php else:
      $found = crm_findings($cs);
      $stationsDef = stations();
  ?>
    <p class="lead">From your GoHighLevel, last <?= (int) $cs['days'] ?> days. Checked <?= h(date('M j, g:i a', crm_ts($cs['checked_at']) ?: time())) ?>.</p>
    <div class="kpis">
      <?php if (isset($cs['contacts'])): ?><div class="kpi"><b><?= (int) $cs['contacts']['new'] ?></b><span>new contacts</span></div><?php endif; ?>
      <?php if (isset($cs['speed']['never_pct'])): ?><div class="kpi"><b><?= (int) $cs['speed']['never_pct'] ?>%</b><span>never got a reply</span></div><?php endif; ?>
      <?php if (isset($cs['speed']['median_minutes'])): ?><div class="kpi"><b><?= h(crm_minutes_text((int) $cs['speed']['median_minutes'])) ?></b><span>typical first reply</span></div><?php endif; ?>
      <?php if (isset($cs['followup']['avg_touches'])): ?><div class="kpi"><b><?= h((string) $cs['followup']['avg_touches']) ?></b><span>follow-ups per lead</span></div><?php endif; ?>
      <?php if (isset($cs['calendar']['show_rate'])): ?><div class="kpi"><b><?= (int) $cs['calendar']['show_rate'] ?>%</b><span>show rate</span></div><?php endif; ?>
      <?php if (isset($cs['deals']['close_rate'])): ?><div class="kpi"><b><?= (int) $cs['deals']['close_rate'] ?>%</b><span>close rate</span></div><?php endif; ?>
    </div>
    <section class="card">
      <h2>Before → Now</h2>
      <?php render_crm_compare($crm); ?>
    </section>
    <section class="card">
      <h2>What the numbers say</h2>
      <?php foreach ($found['stations'] as $sid2 => $notes): foreach ($notes as $n): ?>
        <p class="evidence crm <?= h($n['level']) ?>"><b><?= h($stationsDef[$sid2]['title'] ?? $sid2) ?>:</b> <?= h($n['text']) ?></p>
      <?php endforeach; endforeach; ?>
      <?php if (!$found['stations']): ?><p class="muted">There wasn't enough data in the last <?= (int) $cs['days'] ?> days to say much yet.</p><?php endif; ?>
      <?php foreach ($cs['skipped'] ?? [] as $part => $why): ?><p class="muted small">Couldn't read <?= h($part) ?>: <?= h($why) ?></p><?php endforeach; ?>
    </section>
    <?php if ($active): ?>
      <div class="actions">
        <form method="post" action="portal.php"><?= csrf_field() ?><input type="hidden" name="action" value="crm_refresh"><button class="btn primary" type="submit">Check again</button></form>
        <form method="post" action="portal.php" onsubmit="return confirm('Disconnect your CRM and delete these numbers?')"><?= csrf_field() ?><input type="hidden" name="action" value="crm_disconnect"><button class="btn" type="submit">Disconnect</button></form>
      </div>
    <?php endif; ?>
    <p class="crm-trust">Read-only. We keep these numbers only. Disconnecting deletes your token and the numbers.</p>
  <?php endif; ?>
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
  <?php if (!empty($crm['stats'])): ?>
    <section class="card">
      <h2>Your CRM: Before → Now</h2>
      <?php render_crm_compare($crm); ?>
      <p class="small"><a href="<?= h(portal_url('crm')) ?>">See your CRM numbers</a></p>
    </section>
  <?php endif; ?>
  <section class="card">
    <h2>Month by month</h2>
    <?php $months = progress_by_month($audits); if ($months) { render_progress($months); } else { echo '<p class="muted">Shows up after your first audit.</p>'; } ?>
  </section>
<?php
endif;

render_footer();

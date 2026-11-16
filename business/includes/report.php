<?php
/*
 * Shared rendering for the Business Portal and the Admin view.
 */
declare(strict_types=1);

function fmt_hours(float $minutes): string
{
    return number_format($minutes / 60, 1);
}

function render_profile_form(array $profile, bool $editable = true): void
{
    $dis = $editable ? '' : ' disabled';
    $fields = profile_fields();
    $groups = [
        'Company'               => ['company', 'website', 'industry', 'team_size', 'software_budget', 'hourly_cost'],
        'Social media & channels' => ['facebook', 'instagram', 'linkedin', 'tiktok', 'x_twitter', 'youtube', 'google_business', 'other_channels'],
        'Operations'            => ['departments', 'current_tools', 'lead_sources', 'bottlenecks', 'goals'],
    ];
    echo '<form method="post" action="portal.php">' . csrf_field() . '<input type="hidden" name="action" value="save_profile">';
    foreach ($groups as $title => $keys) {
        echo '<h3 class="muted small" style="text-transform:uppercase;letter-spacing:.06em;margin:18px 0 8px">' . h($title) . '</h3><div class="row">';
        foreach ($keys as $key) {
            [$label, $type, $ph] = $fields[$key];
            $val = $profile[$key] ?? '';
            if ($type === 'select') {
                $opts = '<option value="">Choose your industry…</option>';
                foreach (industries() as $k => $lbl) {
                    $opts .= '<option value="' . h($k) . '"' . ($k === $val ? ' selected' : '') . '>' . h($lbl) . '</option>';
                }
                echo '<label>' . h($label) . '<select name="' . h($key) . '"' . $dis . '>' . $opts . '</select></label>';
            } elseif ($type === 'textarea') {
                echo '<label style="grid-column:1/-1">' . h($label)
                    . '<textarea name="' . h($key) . '" maxlength="4000" placeholder="' . h($ph) . '"' . $dis . '>' . h($val) . '</textarea></label>';
            } else {
                echo '<label>' . h($label) . '<input type="text" name="' . h($key)
                    . '" maxlength="300" value="' . h($val) . '" placeholder="' . h($ph) . '"' . $dis . '></label>';
            }
        }
        echo '</div>';
    }
    echo $editable ? '<button class="btn primary" type="submit">Save business profile</button></form>' : '</form>';
}

function render_profile_readonly(array $profile): void
{
    $fields = profile_fields();
    $rows = '';
    foreach ($profile as $key => $val) {
        if ($val === '') {
            continue;
        }
        $display = $key === 'industry' ? h(industry_label($val)) : h($val);
        if ($fields[$key][1] === 'url' && preg_match('#^https?://#i', $val)) {
            $display = '<a href="' . h($val) . '" target="_blank" rel="noopener noreferrer">' . h($val) . '</a>';
        }
        $rows .= '<tr><th>' . h($fields[$key][0]) . '</th><td style="white-space:pre-wrap">' . $display . '</td></tr>';
    }
    echo $rows ? '<div class="table-wrap"><table>' . $rows . '</table></div>' : '<p class="muted">Profile not filled in yet.</p>';
}

function render_telemetry(array $aggregate, array $audits, bool $canDelete): void
{
    $minutes = (float) $aggregate['totalActiveMinutes'];
    echo '<div class="stats">'
        . '<div class="stat"><b>' . (int) $aggregate['auditCount'] . '</b><span>Audits received</span></div>'
        . '<div class="stat"><b>' . fmt_hours($minutes) . '</b><span>Hours tracked</span></div>'
        . '<div class="stat"><b>' . count($aggregate['softwareUsage']) . '</b><span>Tools used</span></div>'
        . '<div class="stat"><b>' . (int) $aggregate['rapidContextSwitches'] . '</b><span>Quick tab switches</span></div>'
        . '</div>';

    if (!$audits) {
        echo '<p class="muted">No audits yet. In the extension, each person clicks <b>Send to my Business Portal</b>. You can also upload an exported audit file below.</p>';
        return;
    }

    echo '<h3>Tools your team uses most</h3><div class="table-wrap"><table><tr><th>Tool</th><th>Hours</th><th>Visits</th><th>Used by</th></tr>';
    foreach (array_slice($aggregate['softwareUsage'], 0, 25) as $d) {
        echo '<tr><td>' . h($d['domain']) . '</td><td>' . fmt_hours((float) $d['activeMinutes']) . '</td><td>'
            . (int) $d['visitCount'] . '</td><td>' . h(implode(', ', $d['roles'])) . '</td></tr>';
    }
    echo '</table></div>';

    if ($aggregate['repeatedWorkflowLoops']) {
        echo '<h3>Tasks your team repeats most</h3><div class="table-wrap"><table><tr><th>Steps</th><th>Times</th></tr>';
        foreach (array_slice($aggregate['repeatedWorkflowLoops'], 0, 10) as $l) {
            echo '<tr><td>' . h(implode(' → ', $l['sequence'])) . '</td><td>' . (int) $l['repeatCount'] . '</td></tr>';
        }
        echo '</table></div>';
    }

    echo '<h3>Audits received</h3><div class="table-wrap"><table><tr><th>Date</th><th>Role</th><th>Hours</th><th>Tools</th><th></th></tr>';
    foreach ($audits as $a) {
        $meta = $a['data']['auditMetadata'] ?? [];
        echo '<tr><td>' . h(substr((string) $a['created_at'], 0, 10)) . '</td><td>' . h($a['role_tag']) . '</td><td>'
            . fmt_hours((float) $a['total_minutes']) . '</td><td>' . (int) ($meta['uniqueToolsCount'] ?? 0) . '</td><td>';
        if ($canDelete) {
            echo '<form class="inline" method="post" action="portal.php" onsubmit="return confirm(\'Delete this audit?\')">'
                . csrf_field() . '<input type="hidden" name="action" value="delete_audit"><input type="hidden" name="audit_id" value="'
                . (int) $a['id'] . '"><button class="btn danger" type="submit" style="padding:4px 10px">Delete</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
}

function render_prompt_box(string $prompt): void
{
    echo '<textarea class="prompt" id="gapPrompt" readonly>' . h($prompt) . '</textarea>'
        . '<div class="actions" style="margin-top:10px"><button class="btn primary" type="button" data-copy="gapPrompt">Copy report prompt</button>'
        . '<span class="muted small">Paste it into ChatGPT or Claude to get your report.</span></div>';
}

function render_progress(array $months): void
{
    if (count($months) < 1) {
        return;
    }
    echo '<h3>Month by month</h3><div class="table-wrap"><table><tr><th>Month</th><th>Audits</th><th>Hours tracked</th>'
        . '<th>Repeated tasks per hour</th><th>Quick tab switches per hour</th></tr>';
    foreach ($months as $m) {
        echo '<tr><td>' . h(date('M Y', strtotime($m['month'] . '-01'))) . '</td><td>' . (int) $m['audits'] . '</td><td>'
            . h((string) $m['hours']) . '</td><td>' . h((string) $m['repeats_per_hour']) . '</td><td>'
            . h((string) $m['switches_per_hour']) . '</td></tr>';
    }
    echo '</table></div>';
    if (count($months) >= 2) {
        $first = $months[0];
        $last  = $months[count($months) - 1];
        $change = function (float $from, float $to): string {
            if ($from <= 0) {
                return '—';
            }
            $pct = round((($to - $from) / $from) * 100);
            return ($pct <= 0 ? '↓ ' . abs($pct) : '↑ ' . $pct) . '%';
        };
        echo '<p class="muted small">Since ' . h(date('M Y', strtotime($first['month'] . '-01'))) . ': repeated tasks per hour '
            . h($change((float) $first['repeats_per_hour'], (float) $last['repeats_per_hour'])) . ', quick tab switches per hour '
            . h($change((float) $first['switches_per_hour'], (float) $last['switches_per_hour'])) . '. Lower is better.</p>';
    } else {
        echo '<p class="muted small">Send in audits next month to compare.</p>';
    }
}

function render_checklist_form(string $industry, array $answers, bool $editable): void
{
    $items = checklist_items($industry);
    $byArea = [];
    foreach ($items as $item) {
        $byArea[$item['area']][] = $item;
    }
    $dis = $editable ? '' : ' disabled';
    echo '<form method="post" action="portal.php" class="checklist">' . csrf_field() . '<input type="hidden" name="action" value="save_checklist">';
    foreach (CHECK_AREAS as $area => $areaLabel) {
        if (empty($byArea[$area])) {
            continue;
        }
        echo '<h3 class="muted small" style="text-transform:uppercase;letter-spacing:.06em;margin:18px 0 8px">' . h($areaLabel) . '</h3>';
        foreach ($byArea[$area] as $item) {
            $cur = $answers[$item['id']] ?? '';
            echo '<fieldset class="check-item"><legend>' . h($item['q']) . '</legend>'
                . '<div class="muted small">' . h($item['why']) . '</div><div class="check-answers">';
            foreach (CHECK_ANSWERS as $val => $lbl) {
                echo '<label class="pill-radio"><input type="radio" name="' . h($item['id']) . '" value="' . h($val) . '"'
                    . ($cur === $val ? ' checked' : '') . $dis . '><span>' . h($lbl) . '</span></label>';
            }
            echo '</div></fieldset>';
        }
    }
    echo $editable ? '<button class="btn primary" type="submit">Save Back-Office Check</button></form>' : '</form>';
}

function render_checklist_summary(array $summary, bool $showGaps = true): void
{
    if ($summary['answered'] === 0) {
        echo '<p class="muted">No answers yet.</p>';
        return;
    }
    echo '<div class="stats"><div class="stat"><b>' . (int) $summary['score'] . '%</b><span>Back office score ('
        . (int) $summary['answered'] . ' of ' . (int) $summary['total'] . ' answered)</span></div>';
    foreach ($summary['areas'] as $area) {
        echo '<div class="stat"><b>' . ($area['score'] === null ? '—' : (int) $area['score'] . '%') . '</b><span>' . h($area['label']) . '</span></div>';
    }
    echo '</div>';
    if (!$showGaps || !$summary['gaps']) {
        return;
    }
    echo '<h3>What to fix first</h3><div class="table-wrap"><table><tr><th>Area</th><th>Question</th><th>You said</th><th>How we can fix it</th></tr>';
    foreach (array_slice($summary['gaps'], 0, 15) as $g) {
        echo '<tr><td>' . h(CHECK_AREAS[$g['area']]) . '</td><td>' . h($g['q']) . '</td><td>' . h(CHECK_ANSWERS[$g['answer']])
            . '</td><td>' . h($g['auto']) . '</td></tr>';
    }
    echo '</table></div>';
}

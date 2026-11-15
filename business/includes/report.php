<?php
/*
 * Shared rendering for the Business Portal and the Admin view.
 */
declare(strict_types=1);

function fmt_hours(float $minutes): string
{
    return number_format($minutes / 60, 1);
}

function render_profile_form(array $profile): void
{
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
            if ($type === 'textarea') {
                echo '<label style="grid-column:1/-1">' . h($label)
                    . '<textarea name="' . h($key) . '" maxlength="4000" placeholder="' . h($ph) . '">' . h($val) . '</textarea></label>';
            } else {
                echo '<label>' . h($label) . '<input type="text" name="' . h($key)
                    . '" maxlength="300" value="' . h($val) . '" placeholder="' . h($ph) . '"></label>';
            }
        }
        echo '</div>';
    }
    echo '<button class="btn primary" type="submit">Save business profile</button></form>';
}

function render_profile_readonly(array $profile): void
{
    $fields = profile_fields();
    $rows = '';
    foreach ($profile as $key => $val) {
        if ($val === '') {
            continue;
        }
        $display = h($val);
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
        . '<div class="stat"><b>' . (int) $aggregate['auditCount'] . '</b><span>Audits synced</span></div>'
        . '<div class="stat"><b>' . fmt_hours($minutes) . '</b><span>Active hours observed</span></div>'
        . '<div class="stat"><b>' . count($aggregate['softwareUsage']) . '</b><span>Unique tools / domains</span></div>'
        . '<div class="stat"><b>' . (int) $aggregate['rapidContextSwitches'] . '</b><span>Rapid context switches</span></div>'
        . '</div>';

    if (!$audits) {
        echo '<p class="muted">No audits yet. In the extension, click <b>Sync to Business Portal</b>, or upload an exported JSON file below.</p>';
        return;
    }

    echo '<h3>Top tools across the team</h3><div class="table-wrap"><table><tr><th>Domain</th><th>Active hrs</th><th>Visits</th><th>Roles</th></tr>';
    foreach (array_slice($aggregate['softwareUsage'], 0, 25) as $d) {
        echo '<tr><td>' . h($d['domain']) . '</td><td>' . fmt_hours((float) $d['activeMinutes']) . '</td><td>'
            . (int) $d['visitCount'] . '</td><td>' . h(implode(', ', $d['roles'])) . '</td></tr>';
    }
    echo '</table></div>';

    if ($aggregate['repeatedWorkflowLoops']) {
        echo '<h3>Most repeated workflow loops</h3><div class="table-wrap"><table><tr><th>Loop</th><th>Repeats</th></tr>';
        foreach (array_slice($aggregate['repeatedWorkflowLoops'], 0, 10) as $l) {
            echo '<tr><td>' . h(implode(' → ', $l['sequence'])) . '</td><td>' . (int) $l['repeatCount'] . '</td></tr>';
        }
        echo '</table></div>';
    }

    echo '<h3>Synced audits</h3><div class="table-wrap"><table><tr><th>Date</th><th>Role</th><th>Active hrs</th><th>Tools</th><th></th></tr>';
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
        . '<div class="actions" style="margin-top:10px"><button class="btn primary" type="button" data-copy="gapPrompt">Copy AI Gap Analysis Prompt</button>'
        . '<span class="muted small">Paste into Claude or ChatGPT to generate the client report and agent plan.</span></div>';
}

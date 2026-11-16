<?php
/*
 * The 7 stations of the client journey, with 3 questions at every station:
 *   Process:    is it defined, or improvised?
 *   Owner:      a named person, the owner, or nobody?
 *   Automation: automated, manual, or missing?
 * Each station also asks a few quick facts (sources, response time, touches, show rate, ...).
 * Evidence from the team's browser audits and our team's handoff tests are attached to the matching station.
 */
declare(strict_types=1);

const STATION_PROCESS    = ['defined' => 'Defined', 'partly' => 'Partly', 'improvised' => 'Improvised'];
const STATION_OWNER      = ['named' => 'A named person', 'owner' => 'The owner', 'nobody' => 'Nobody'];
const STATION_AUTOMATION = ['automated' => 'Automated', 'manual' => 'Manual', 'missing' => 'Missing'];
const STATION_POINTS     = [
    'defined' => 1.0, 'partly' => 0.5, 'improvised' => 0.0,
    'named' => 1.0, 'owner' => 0.5, 'nobody' => 0.0,
    'automated' => 1.0, 'manual' => 0.5, 'missing' => 0.0,
];

/**
 * Facts per station: [key, question, type (select|multi|number), options].
 * fix = the process fix (Sirena), agent = what to build (ALJA).
 */
function stations(): array
{
    return [
        'lead_capture' => [
            'title' => 'Lead capture',
            'check' => 'Where leads come from, and whether every one lands in one system (your CRM) or some get lost in DMs, voicemail and email.',
            'facts' => [
                ['sources', 'Where do your leads come from?', 'multi', [
                    'web_form' => 'Website form', 'phone' => 'Phone calls', 'voicemail' => 'Voicemail', 'text' => 'Text messages',
                    'dms' => 'Facebook / Instagram DMs', 'email' => 'Email', 'google' => 'Google Business Profile',
                    'referrals' => 'Referrals', 'ads' => 'Ads or landing pages', 'walkins' => 'Walk-ins', 'other' => 'Other']],
                ['in_crm', 'Do all of them land in one system (a CRM)?', 'select', [
                    'all' => 'All of them', 'most' => 'Most', 'some' => 'Some', 'none' => 'None, or no CRM']],
            ],
            'fix'   => 'Send every source (forms, calls, voicemail, DMs, email) into one CRM, tagged with where it came from.',
            'agent' => 'Lead Capture Agent',
        ],
        'speed' => [
            'title' => 'Speed to lead',
            'check' => 'How fast a new lead gets a response, after hours included.',
            'facts' => [
                ['first_reply', 'How fast does a new lead usually hear back?', 'select', [
                    'u5' => 'Under 5 minutes', 'u60' => 'Under an hour', 'sameday' => 'Same day', 'nextday' => 'Next day or later', 'unsure' => 'Not sure']],
                ['after_hours', 'What happens after hours and on weekends?', 'select', [
                    'instant' => 'Instant automatic reply', 'nextbiz' => 'They wait until the next business day', 'unsure' => 'Not sure']],
            ],
            'fix'   => 'Reply within 5 minutes, day or night, with a link to book.',
            'agent' => 'Instant Reply Agent',
        ],
        'followup' => [
            'title' => 'Follow-up',
            'check' => "How many touches, over how long, across which channels, and whether it's automated or depends on someone remembering.",
            'facts' => [
                ['touches', "How many times do you follow up with a lead who doesn't answer?", 'select', [
                    't0' => "We don't", 't1' => 'Once', 't23' => '2–3 times', 't46' => '4–6 times', 't7' => '7 or more']],
                ['span', 'Over how long?', 'select', [
                    'days' => 'A few days', 'week' => 'About a week', 'weeks' => '2–4 weeks', 'month' => '30 days or more']],
                ['channels', 'Which channels?', 'multi', [
                    'email' => 'Email', 'text' => 'Text', 'phone' => 'Phone call', 'dm' => 'Social DMs', 'vm' => 'Voicemail drop']],
            ],
            'fix'   => 'A set sequence: at least 6 touches over 2–3 weeks across text, email and phone, stopping when they reply.',
            'agent' => 'Follow-up Agent',
        ],
        'conversion' => [
            'title' => 'Booking and conversion',
            'check' => 'How a lead becomes a paying customer, including show rate and close rate.',
            'facts' => [
                ['path', 'How does a lead become a customer?', 'select', [
                    'call' => 'They book a call or meeting', 'quote' => 'We send a quote or proposal',
                    'application' => 'They apply', 'checkout' => 'They buy online or walk in']],
                ['show_rate', 'Show rate: % of booked calls that show up', 'number', []],
                ['close_rate', 'Close rate: % of calls or quotes that buy', 'number', []],
            ],
            'fix'   => 'Booking link, confirmation and reminders, same-day no-show rebook, a recap after every call, and proposal follow-up.',
            'agent' => 'Scheduling and Meeting Follow-up Agents',
        ],
        'onboarding' => [
            'title' => 'Onboarding and handoff',
            'check' => "What happens after payment, and whether there's a defined owner and checklist.",
            'facts' => [
                ['checklist', 'Is there a written onboarding checklist?', 'select', ['yes' => 'Yes', 'partly' => 'Partly', 'no' => 'No']],
                ['kickoff', 'How soon after paying does a new client hear from you?', 'select', [
                    'same' => 'Same day', 'days' => '1–3 days', 'week' => 'About a week', 'varies' => 'It varies']],
            ],
            'fix'   => 'Payment triggers the welcome, forms, contract and kickoff booking, with one named owner and a checklist.',
            'agent' => 'Onboarding Agent',
        ],
        'delivery' => [
            'title' => 'Delivery',
            'check' => "Whether there's a documented, repeatable process that doesn't live in the owner's head.",
            'facts' => [
                ['documented', 'Is how you deliver the work written down step by step?', 'select', ['yes' => 'Yes', 'partly' => 'Partly', 'no' => 'No']],
                ['owner_away', 'If the owner were out for 2 weeks, would delivery keep going?', 'select', ['yes' => 'Yes', 'partly' => 'Mostly', 'no' => 'No']],
            ],
            'fix'   => 'Write the delivery steps and checklist so anyone on the team, or an agent, can run them.',
            'agent' => 'Workflow Agent',
        ],
        'retention' => [
            'title' => 'Retention and referral',
            'check' => 'Review requests, rebooking, repeat purchases and referral asks.',
            'facts' => [
                ['happens', 'Which of these happen today?', 'multi', [
                    'reviews' => 'Review requests', 'rebook' => 'Rebooking or renewal reminders',
                    'repeat' => 'Offers for repeat purchases', 'referrals' => 'Referral asks', 'none' => 'None of these']],
            ],
            'fix'   => 'Automatic review requests, rebooking or renewal reminders, and referral asks at the right moments.',
            'agent' => 'Review and Referral Agent',
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* Handoff tests our team runs                                          */
/* ------------------------------------------------------------------ */

/*
 * Handoff tests our team runs (shared with the Client journey in chains.php, which defines
 * TEST_RESULTS, load_tests() and which journey links each test covers). Here: which station each test belongs to.
 */
const STATION_TESTS = [
    'test_lead'     => ['label' => 'Test lead', 'station' => 'speed',
        'how' => "Fill in their website's contact or quote form as a new customer. Note how long the first reply takes (try after hours too)."],
    'test_followup' => ['label' => 'Follow-up test', 'station' => 'followup',
        'how' => "After the first reply, don't answer. Count how many follow-ups arrive, over how long, and on which channels."],
    'test_booking'  => ['label' => 'Test booking', 'station' => 'conversion',
        'how' => 'Book a call through their booking link. Check that a confirmation and reminders arrive.'],
    'test_noshow'   => ['label' => 'No-show test', 'station' => 'conversion',
        'how' => "Don't join the test call. Note whether anyone tries to rebook you, and how fast."],
    'test_recap'    => ['label' => 'Recap test', 'station' => 'conversion',
        'how' => 'After a real or test call, note whether a recap with next steps arrives within 24 hours.'],
];

function load_stations(int $licenseId): array
{
    $s = profile_data($licenseId)['stations'] ?? [];
    return is_array($s) ? $s : [];
}

/** Clean one station's answers from a form post. */
function clean_station_input(string $id, array $in): array
{
    $def = stations()[$id];
    $out = [
        'process'    => isset(STATION_PROCESS[$in['process'] ?? '']) ? $in['process'] : '',
        'owner'      => isset(STATION_OWNER[$in['owner'] ?? '']) ? $in['owner'] : '',
        'owner_name' => trim(mb_substr((string) ($in['owner_name'] ?? ''), 0, 80)),
        'automation' => isset(STATION_AUTOMATION[$in['automation'] ?? '']) ? $in['automation'] : '',
        'facts'      => [],
    ];
    foreach ($def['facts'] as [$key, , $type, $options]) {
        $v = $in['f_' . $key] ?? null;
        if ($type === 'multi') {
            $out['facts'][$key] = array_values(array_intersect(array_keys($options), (array) $v));
        } elseif ($type === 'number') {
            $out['facts'][$key] = ($v === null || trim((string) $v) === '') ? '' : (string) max(0, min(100, (int) $v));
        } else {
            $out['facts'][$key] = isset($options[$v]) ? (string) $v : '';
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Evidence from the team's browser audits                              */
/* ------------------------------------------------------------------ */

function station_evidence(array $aggregate): array
{
    $cat  = fn(string $d) => tool_info($d)['category'];
    $name = fn(string $d) => tool_info($d)['name'];

    $meetings = 0; $meetingTool = ''; $hasCrm = false; $hasBooking = false; $socialChecks = 0; $socialName = '';
    foreach ($aggregate['softwareUsage'] ?? [] as $u) {
        $d = (string) $u['domain'];
        $c = $cat($d);
        if ($c === 'meeting') { $meetings += (int) $u['visitCount']; $meetingTool = $meetingTool ?: $name($d); }
        if ($c === 'crm') { $hasCrm = true; }
        if ($c === 'calendar' && !str_contains($d, 'calendar.google.com')) { $hasBooking = true; }
        if ($c === 'social' && (int) ($u['shortVisits'] ?? 0) > $socialChecks) { $socialChecks = (int) $u['shortVisits']; $socialName = $name($d); }
    }
    $toEmail = 0; $toCrm = 0; $calEmail = 0; $emailDocs = 0;
    foreach ($aggregate['domainJumps'] ?? [] as $key => $count) {
        [$from, $to] = array_pad(explode(' -> ', (string) $key), 2, '');
        $cf = $cat($from); $ct = $cat($to);
        if ($cf === 'meeting' && $ct === 'email') { $toEmail += (int) $count; }
        if ($cf === 'meeting' && $ct === 'crm') { $toCrm += (int) $count; }
        if (($cf === 'calendar' && $ct === 'email') || ($cf === 'email' && $ct === 'calendar')) { $calEmail += (int) $count; }
        if (($cf === 'email' && $ct === 'docs') || ($cf === 'docs' && $ct === 'email')) { $emailDocs += (int) $count; }
    }

    $ev = [];
    $add = function (string $station, string $text) use (&$ev) { $ev[$station][] = $text; };
    if (!empty($aggregate['softwareUsage']) && !$hasCrm) {
        $add('lead_capture', 'No CRM was seen in the audit, so leads probably live in inboxes, DMs and spreadsheets.');
    }
    if ($socialChecks >= 8) {
        $add('lead_capture', "$socialName was checked $socialChecks times in short bursts. Leads that arrive in DMs may never reach your CRM.");
    }
    if (!$hasBooking && $calEmail >= 4) {
        $add('conversion', "No booking tool was seen, and people went back and forth between calendar and email $calEmail times.");
    }
    if ($meetings > 0 && $toEmail > 0) {
        $add('followup', "After $meetingTool calls, people went straight to email $toEmail of $meetings times. Recaps and follow-ups are probably written by hand.");
    }
    if ($meetings > 0 && $hasCrm && $toCrm < $meetings / 2) {
        $add('followup', "Notes went into the CRM after only $toCrm of $meetings calls.");
    }
    if ($emailDocs >= 10) {
        $add('delivery', "Email and documents went back and forth $emailDocs times. Work is probably being moved by hand.");
    }
    return $ev;
}

/* ------------------------------------------------------------------ */
/* Scoring                                                              */
/* ------------------------------------------------------------------ */

/** Warnings from the station's own facts. */
function station_flags(string $id, array $facts): array
{
    $f = fn(string $k) => $facts[$k] ?? '';
    $flags = [];
    switch ($id) {
        case 'lead_capture':
            if (in_array($f('in_crm'), ['some', 'none'], true)) { $flags[] = 'Some leads never reach your CRM, so they can be lost.'; }
            if (array_intersect((array) $f('sources'), ['dms', 'voicemail']) && $f('in_crm') !== 'all') { $flags[] = 'DMs and voicemail are the easiest places to lose leads.'; }
            break;
        case 'speed':
            if (in_array($f('first_reply'), ['sameday', 'nextday'], true)) { $flags[] = 'Leads wait hours for a reply. The first business to answer usually wins.'; }
            if ($f('after_hours') === 'nextbiz') { $flags[] = 'After-hours leads wait until the next business day.'; }
            break;
        case 'followup':
            if (in_array($f('touches'), ['t0', 't1', 't23'], true)) { $flags[] = 'Most sales take 5 or more touches. Leads are dropped too early.'; }
            if (in_array($f('span'), ['days', 'week'], true)) { $flags[] = 'Follow-up stops within a week.'; }
            if (count((array) $f('channels')) === 1) { $flags[] = 'Only one channel is used for follow-up.'; }
            break;
        case 'conversion':
            if ($f('show_rate') !== '' && (int) $f('show_rate') < 70) { $flags[] = 'Show rate is ' . (int) $f('show_rate') . '%. Reminders and rebooking usually lift it above 80%.'; }
            if ($f('close_rate') !== '' && (int) $f('close_rate') < 20) { $flags[] = 'Close rate is ' . (int) $f('close_rate') . '%. Faster recaps and proposal follow-up usually help.'; }
            break;
        case 'onboarding':
            if ($f('checklist') === 'no') { $flags[] = 'There is no onboarding checklist, so steps get skipped.'; }
            if (in_array($f('kickoff'), ['week', 'varies'], true)) { $flags[] = "New clients wait to hear from you right after paying."; }
            break;
        case 'delivery':
            if ($f('owner_away') === 'no') { $flags[] = "Delivery stops when the owner is away. It lives in one person's head."; }
            if ($f('documented') === 'no') { $flags[] = 'Delivery steps are not written down.'; }
            break;
        case 'retention':
            $h = (array) $f('happens');
            if (!$h || in_array('none', $h, true)) { $flags[] = 'No reviews, rebooking or referral asks happen today.'; }
            else {
                if (!in_array('reviews', $h, true)) { $flags[] = 'Happy clients are not asked for reviews.'; }
                if (!in_array('referrals', $h, true)) { $flags[] = 'Happy clients are not asked for referrals.'; }
            }
            break;
    }
    return $flags;
}

/**
 * Score every station. status: ok (green), weak (amber), broken (red), unknown (grey).
 */
function stations_status(array $answers, array $evidence, array $tests, array $chainRows = [], array $crm = []): array
{
    $linksBy = [];
    foreach ($chainRows as $lid => $lr) {
        $linksBy[$lr['station'] ?? ''][$lid] = $lr;
    }
    $testsBy = [];
    foreach (STATION_TESTS as $tid => $t) {
        if (!empty($tests[$tid]['result'])) {
            $testsBy[$t['station']][] = $tests[$tid] + ['label' => $t['label']];
        }
    }
    $rows = [];
    $counts = ['ok' => 0, 'weak' => 0, 'broken' => 0, 'unknown' => 0];
    foreach (stations() as $id => $def) {
        $a = $answers[$id] ?? ['process' => '', 'owner' => '', 'owner_name' => '', 'automation' => '', 'facts' => []];
        $pts = [];
        foreach (['process', 'owner', 'automation'] as $k) {
            if (isset(STATION_POINTS[$a[$k] ?? ''])) {
                $pts[] = STATION_POINTS[$a[$k]];
            }
        }
        $score = $pts ? (int) round(100 * array_sum($pts) / count($pts)) : null;
        $flags = station_flags($id, (array) ($a['facts'] ?? []));
        $stTests = $testsBy[$id] ?? [];
        $failed = array_filter($stTests, fn($t) => $t['result'] === 'fail');

        if ($score === null) {
            $status = 'unknown';
        } elseif ($score >= 80) {
            $status = 'ok';
        } elseif ($score >= 40) {
            $status = 'weak';
        } else {
            $status = 'broken';
        }
        // The journey steps inside this station count too.
        $links = $linksBy[$id] ?? [];
        $linkStatuses = array_column($links, 'status');
        $answeredLinks = array_filter($linkStatuses, fn($x) => $x !== 'unknown');
        if ($status === 'unknown' && $answeredLinks) {
            $status = in_array('broken', $answeredLinks, true) ? 'broken' : (in_array('manual', $answeredLinks, true) || in_array('weak', $answeredLinks, true) ? 'weak' : 'ok');
        } elseif ($status === 'ok' && array_intersect($answeredLinks, ['broken', 'weak', 'manual'])) {
            $status = 'weak';
        }
        if ($status === 'ok' && ($flags || !empty($evidence[$id]))) {
            $status = 'weak';
        }
        $crmNotes = $crm[$id] ?? [];
        foreach ($crmNotes as $n) {
            if ($n['level'] !== 'ok') {
                $status = $status === 'unknown' ? $n['level'] : journey_worse($status, $n['level']);
            }
        }
        if ($failed) {
            $status = 'broken';
        }
        $counts[$status]++;
        $rows[$id] = $def + [
            'id' => $id, 'answers' => $a, 'score' => $score, 'status' => $status, 'flags' => $flags,
            'evidence' => $evidence[$id] ?? [], 'tests' => $stTests, 'links' => $links, 'crm' => $crmNotes,
            'complete' => ($a['process'] ?? '') !== '' && ($a['owner'] ?? '') !== '' && ($a['automation'] ?? '') !== '',
        ];
    }
    $done = count(array_filter($rows, fn($r) => $r['complete']));
    return ['rows' => $rows, 'counts' => $counts, 'done' => $done, 'total' => count($rows)];
}

/** Text for the AI report prompt. */
function stations_prompt_text(array $status): string
{
    $lines = [];
    foreach ($status['rows'] as $r) {
        $a = $r['answers'];
        $facts = [];
        foreach ($r['facts'] as [$key, $q, $type, $options]) {
            $v = $a['facts'][$key] ?? '';
            if ($type === 'multi') {
                $v = implode(', ', array_map(fn($x) => $options[$x] ?? $x, (array) $v));
            } elseif ($type === 'number') {
                $v = $v === '' ? '' : $v . '%';
            } else {
                $v = $options[$v] ?? '';
            }
            if ($v !== '') {
                $facts[] = "$q $v";
            }
        }
        $line = '### ' . $r['title'] . ($r['score'] !== null ? ' (score ' . $r['score'] . '%)' : ' (not answered)') . "\n"
            . 'Process: ' . (STATION_PROCESS[$a['process'] ?? ''] ?? '?')
            . ' | Owner: ' . (STATION_OWNER[$a['owner'] ?? ''] ?? '?') . (($a['owner_name'] ?? '') !== '' ? ' (' . $a['owner_name'] . ')' : '')
            . ' | Automation: ' . (STATION_AUTOMATION[$a['automation'] ?? ''] ?? '?');
        if ($facts) { $line .= "\nFacts: " . implode(' | ', $facts); }
        if ($r['flags']) { $line .= "\nFlags: " . implode(' ', $r['flags']); }
        if ($r['evidence']) { $line .= "\nAudit evidence: " . implode(' ', $r['evidence']); }
        if ($r['crm']) { $line .= "\nTheir CRM: " . implode(' ', array_column($r['crm'], 'text')); }
        foreach ($r['tests'] as $t) {
            $line .= "\nOur test (" . $t['label'] . '): ' . (TEST_RESULTS[$t['result']] ?? '') . ($t['detail'] !== '' ? ' — ' . $t['detail'] : '');
        }
        $line .= "\nStandard fix: " . $r['fix'] . ' | Agent: ' . $r['agent'];
        $lines[] = $line;
    }
    return implode("\n\n", $lines);
}

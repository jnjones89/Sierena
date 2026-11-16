<?php
/*
 * Client journey ("does each step trigger the next one?").
 *
 * The owner marks each link: Automatic / By hand / Sometimes / Doesn't happen.
 * The team's browser audits add evidence (e.g. "after meetings, people went straight to Gmail"),
 * and our team can run handoff tests (test lead, test booking, no-show) from admin.php.
 */
declare(strict_types=1);

const CHAIN_ANSWERS = [
    'auto'      => 'Automatic',
    'manual'    => 'By hand',
    'sometimes' => 'Sometimes',
    'never'     => "Doesn't happen",
];

/** Each link: [id, short label, question, agent, fix, station (see stations.php)]. */
function chain_base(): array
{
    return [
        ['lead_reply', 'Lead gets a reply', 'Does every new lead get a reply within 5 minutes?',
            'Instant Reply Agent', 'Reply by text and email within a minute, with a link to book a call.', 'speed'],
        ['booking', 'Call gets booked', 'Do people book calls with a link, without back-and-forth emails?',
            'Scheduling Agent', 'Send a booking link so people pick a time themselves.', 'conversion'],
        ['reminders', 'Confirmation and reminders', 'Does every booked call get a confirmation and reminders?',
            'Reminder Agent', 'Confirm right away, then remind a day before and an hour before.', 'conversion'],
        ['no_show', 'No-shows get rebooked', 'When someone misses a call, do they get a rebook message the same day?',
            'No-show Agent', 'Text and email a rebook link within an hour of a missed call.', 'conversion'],
        ['notes', 'Call notes saved', 'Are the notes from every call saved in your CRM?',
            'Meeting Follow-up Agent', 'Notes are drafted from the call and saved to the client record.', 'conversion'],
        ['recap', 'Recap after the call', 'Does every call get a recap email with next steps within 24 hours?',
            'Meeting Follow-up Agent', 'A recap with next steps is drafted when the call ends. You check it and send.', 'conversion'],
        ['next_steps', 'Next steps have an owner', 'Does every next step become a task with an owner and a due date?',
            'Meeting Follow-up Agent', 'Next steps from the recap become tasks automatically.', 'conversion'],
        ['proposal', 'Proposal or quote', 'Do proposals or quotes go out within 2 business days of the call?',
            'Proposal Agent', 'Draft the proposal from the call notes and your pricing, ready for you to review.', 'conversion'],
        ['proposal_chase', 'Proposal follow-up', 'Do unanswered proposals get followed up?',
            'Follow-up Sequence', 'Follow up on day 2, 5 and 10 until they decide.', 'followup'],
        ['onboarding', 'Signed → onboarding', 'When a client signs, does onboarding start on its own?',
            'Onboarding Agent', 'Signing sends the welcome email, forms and a kickoff booking link.', 'onboarding'],
        ['invoice', 'Invoice and payment', 'Do invoices and payment reminders go out on schedule?',
            'Invoice Agent', 'Invoice when the work is done, with friendly reminders until paid.', 'delivery'],
        ['review', 'Review request', 'Are happy clients asked for a review?',
            'Review Agent', 'Ask at the right moment and send unhappy clients to you first.', 'retention'],
        ['referral', 'Referral ask', 'Do happy clients get asked for referrals?',
            'Referral Sequence', 'Ask after a win, with an easy way to introduce someone.', 'retention'],
    ];
}

/** Extra links per industry: [after-link-id, link]. A link with an existing id replaces it. */
function chain_industry(string $industry): array
{
    $extra = [
        'funding' => [
            ['next_steps', ['fund_docs', 'Documents collected', 'Do missing documents get requested automatically until the file is complete?',
                'Document Chaser Agent', 'Clients get texts and emails listing exactly what is still missing, with a secure upload link.', 'conversion']],
            ['fund_docs', ['fund_submit', 'Sent to lenders', 'Do complete files go to lenders within 1 business day?',
                'Submission Tracker', 'Each submission is logged with the lender, date and status.', 'conversion']],
            ['fund_submit', ['proposal', 'Offers explained', 'Do clients get each funding offer explained in writing within 24 hours?',
                'Offer Summary Agent', 'Each offer becomes a plain-language summary of the full cost, ready to send.', 'conversion']],
            ['invoice', ['fund_renewal', 'Renewal check-in', 'Do funded clients hear from you when they may qualify again?',
                'Renewal Agent', 'A check-in goes out when they are likely to qualify for more or a better rate.', 'retention']],
        ],
        'lending' => [
            ['next_steps', ['lend_docs', 'Documents collected', 'Do missing documents get requested automatically until the file is complete?',
                'Document Chaser Agent', 'Borrowers get texts and emails listing exactly what is still missing.', 'conversion']],
            ['lend_docs', ['lend_status', 'Status updates', 'Do borrowers get an update at each step without calling you?',
                'Status Update Agent', 'Each pipeline step sends the borrower a short update.', 'conversion']],
        ],
        'insurance' => [
            ['next_steps', ['proposal', 'Quote sent', 'Do quotes go out within 24 hours of the call?',
                'Quote Agent', 'The quote summary is drafted from the call notes, ready to send.', 'conversion']],
            ['invoice', ['ins_renewal', 'Renewal review', 'Do clients get a renewal review 60–90 days before their policy renews?',
                'Renewal Agent', 'Upcoming renewals are flagged and outreach starts on its own.', 'retention']],
        ],
        'credit' => [
            ['onboarding', ['cr_updates', 'Dispute updates', 'Do clients get a progress update after every dispute round?',
                'Dispute Tracker Agent', 'Each round sends the client an update and reminds you of the next date.', 'delivery']],
        ],
        'healthcare' => [
            ['review', ['hc_rebook', 'Next visit booked', 'Do patients get a reminder when their next treatment is due?',
                'Rebooking Agent', 'Patients get a reminder at the right time for each treatment.', 'retention']],
        ],
    ];
    return $extra[$industry] ?? [];
}

/** Ordered links for an industry, keyed by id. */
function chain_links(string $industry): array
{
    $links = [];
    foreach (chain_base() as $l) {
        $links[] = $l;
    }
    foreach (chain_industry($industry) as [$after, $link]) {
        // Replace an existing link with the same id, otherwise insert after the anchor.
        $replaced = false;
        foreach ($links as $i => $l) {
            if ($l[0] === $link[0]) {
                $links[$i] = $link;
                $replaced = true;
                break;
            }
        }
        if ($replaced) {
            continue;
        }
        $pos = count($links);
        foreach ($links as $i => $l) {
            if ($l[0] === $after) {
                $pos = $i + 1;
                break;
            }
        }
        array_splice($links, $pos, 0, [$link]);
    }
    $out = [];
    foreach ($links as [$id, $label, $q, $agent, $fix, $station]) {
        $out[$id] = ['id' => $id, 'label' => $label, 'q' => $q, 'agent' => $agent, 'fix' => $fix, 'station' => $station];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Handoff tests our team runs                                          */
/* ------------------------------------------------------------------ */

const CHAIN_TESTS = [
    'test_lead'    => ['label' => 'Test lead', 'links' => ['lead_reply'],
        'how' => "Fill in their website's contact or quote form as a new customer. Note how long the first reply takes."],
    'test_booking' => ['label' => 'Test booking', 'links' => ['booking', 'reminders'],
        'how' => 'Book a call through their booking link. Check that a confirmation and reminders arrive.'],
    'test_noshow'  => ['label' => 'No-show test', 'links' => ['no_show'],
        'how' => "Don't join the test call. Note whether anyone tries to rebook you, and how fast."],
    'test_recap'   => ['label' => 'Recap test', 'links' => ['recap', 'next_steps'],
        'how' => 'After a real or test call, note whether a recap with next steps arrives within 24 hours.'],
];

const TEST_RESULTS = ['' => 'Not run yet', 'pass' => 'Passed', 'fail' => 'Failed'];

/* ------------------------------------------------------------------ */
/* Evidence from the team's browser audits                              */
/* ------------------------------------------------------------------ */

function chain_evidence(array $aggregate): array
{
    $cat = fn(string $domain) => tool_info($domain)['category'];
    $name = fn(string $domain) => tool_info($domain)['name'];

    $meetings = 0;
    $meetingTool = '';
    $hasCrm = false;
    $hasBooking = false;
    foreach ($aggregate['softwareUsage'] ?? [] as $u) {
        $c = $cat((string) $u['domain']);
        if ($c === 'meeting') {
            $meetings += (int) $u['visitCount'];
            $meetingTool = $meetingTool ?: $name((string) $u['domain']);
        }
        if ($c === 'crm') {
            $hasCrm = true;
        }
        if ($c === 'calendar' && !str_contains((string) $u['domain'], 'calendar.google.com')) {
            $hasBooking = true;
        }
    }

    $toEmail = 0;
    $toCrm = 0;
    $calEmail = 0;
    $emailDrive = 0;
    foreach ($aggregate['domainJumps'] ?? [] as $key => $count) {
        [$from, $to] = array_pad(explode(' -> ', (string) $key), 2, '');
        $cf = $cat($from);
        $ct = $cat($to);
        if ($cf === 'meeting' && $ct === 'email') {
            $toEmail += (int) $count;
        }
        if ($cf === 'meeting' && $ct === 'crm') {
            $toCrm += (int) $count;
        }
        if (($cf === 'calendar' && $ct === 'email') || ($cf === 'email' && $ct === 'calendar')) {
            $calEmail += (int) $count;
        }
        if (($cf === 'email' && $ct === 'docs') || ($cf === 'docs' && $ct === 'email')) {
            $emailDrive += (int) $count;
        }
    }

    $ev = [];
    if ($meetings > 0) {
        if ($toEmail > 0) {
            $ev['recap'] = ['manual' => true, 'text' => "After $meetingTool calls, people went straight to email $toEmail of $meetings times. Recaps are probably written by hand."];
        }
        $ev['notes'] = $hasCrm
            ? ['manual' => $toCrm < $meetings / 2, 'text' => "Notes went into the CRM after $toCrm of $meetings calls."]
            : ['manual' => true, 'text' => 'No CRM was seen in the audit, so call notes probably live in inboxes or documents.'];
    }
    if (!$hasBooking && $calEmail >= 4) {
        $ev['booking'] = ['manual' => true, 'text' => "No booking tool was seen, and people went back and forth between calendar and email $calEmail times."];
    }
    if ($emailDrive >= 10) {
        foreach (['fund_docs', 'lend_docs'] as $id) {
            $ev[$id] = ['manual' => true, 'text' => "Email and documents went back and forth $emailDrive times. Documents are probably chased by hand."];
        }
    }
    return $ev;
}

/* ------------------------------------------------------------------ */
/* Putting it together                                                  */
/* ------------------------------------------------------------------ */

function load_chain(int $licenseId): array
{
    $c = profile_data($licenseId)['chain'] ?? [];
    return is_array($c) ? $c : [];
}

function load_tests(int $licenseId): array
{
    $t = profile_data($licenseId)['tests'] ?? [];
    return is_array($t) ? $t : [];
}

/**
 * Status per link: ok (green), manual / weak (amber), broken (red), unknown (grey).
 */
function chain_status(string $industry, array $answers, array $evidence, array $tests): array
{
    $byLink = [];
    foreach (CHAIN_TESTS as $tid => $t) {
        foreach ($t['links'] as $lid) {
            if (!empty($tests[$tid]['result'])) {
                $byLink[$lid] = $tests[$tid] + ['label' => $t['label']];
            }
        }
    }
    $rows = [];
    $counts = ['ok' => 0, 'manual' => 0, 'weak' => 0, 'broken' => 0, 'unknown' => 0];
    foreach (chain_links($industry) as $id => $link) {
        $a = $answers[$id] ?? '';
        $status = match ($a) {
            'auto' => 'ok',
            'manual' => 'manual',
            'sometimes' => 'weak',
            'never' => 'broken',
            default => 'unknown',
        };
        $ev = $evidence[$id] ?? null;
        if ($status === 'ok' && $ev && $ev['manual']) {
            $status = 'manual';
        }
        $test = $byLink[$id] ?? null;
        // Our team's real test outranks both the owner's answer and the browser evidence.
        if ($test && $test['result'] === 'fail') {
            $status = 'broken';
        } elseif ($test && $test['result'] === 'pass') {
            $status = 'ok';
            $ev = null;
        }
        $counts[$status]++;
        $rows[$id] = $link + ['answer' => $a, 'status' => $status, 'evidence' => $ev, 'test' => $test];
    }
    return ['rows' => $rows, 'counts' => $counts];
}

/** Text for the AI report prompt. */
function chain_prompt_text(array $status): string
{
    $lines = [];
    foreach ($status['rows'] as $r) {
        $line = '- ' . $r['label'] . ': ' . $r['q'] . ' -> ' . (CHAIN_ANSWERS[$r['answer']] ?? 'Not answered');
        if ($r['evidence']) {
            $line .= ' | Audit: ' . $r['evidence']['text'];
        }
        if ($r['test']) {
            $line .= ' | Our test: ' . (TEST_RESULTS[$r['test']['result']] ?? '') . ($r['test']['detail'] !== '' ? ' (' . $r['test']['detail'] . ')' : '');
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

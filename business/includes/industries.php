<?php
/*
 * Back-Office Check: industries and checklist items.
 *
 * Every business gets the BASE items; each industry adds its own.
 * Keep industry keys and labels in sync with workflow-shadow-audit/industries.js.
 *
 * Compliance items are general guidance, not legal advice. The portal and the
 * report tell customers to confirm requirements with their attorney or compliance advisor.
 */
declare(strict_types=1);

const CHECK_AREAS = [
    'process'    => 'Processes & SOPs',
    'followup'   => 'Follow-up',
    'contracts'  => 'Contracts & documents',
    'compliance' => 'Compliance',
    'data'       => 'Data & security',
];

const CHECK_ANSWERS = [
    'yes'    => 'Yes',
    'partly' => 'Sometimes',
    'no'     => 'No',
    'unsure' => 'Not sure',
];

function industries(): array
{
    return [
        'insurance'     => 'Insurance agency',
        'lending'       => 'Lending & loan brokers',
        'funding'       => 'Business funding & bank approvals',
        'credit'        => 'Credit repair',
        'real_estate'   => 'Real estate',
        'healthcare'    => 'Healthcare & med spas',
        'legal'         => 'Law firms',
        'home_services' => 'Home services & contractors',
        'ecommerce'     => 'E-commerce',
        'agency'        => 'Agencies & consultants',
        'other'         => 'Other',
    ];
}

/** Three short highlights per industry (same text as workflow-shadow-audit/industries.js). */
function industry_highlights(): array
{
    return [
        'insurance'     => ['License and E&O tracking', 'Call and text consent and Do Not Call', 'Renewal and cross-sell follow-up'],
        'lending'       => ['Licensing and written fee disclosures', 'Credit-pull permission and data security', 'Document chasing and status updates'],
        'funding'       => ['Fee agreements and state disclosures', 'Secure, verified bank statements', 'Lender submissions, approvals and renewals'],
        'credit'        => ['Credit repair contract, disclosures and 3-day cancel right', 'No fees before the work is done', 'Dispute tracking and client updates'],
        'real_estate'   => ['Written buyer agreements', 'Transaction checklists and deadlines', 'Instant lead routing and sphere follow-up'],
        'healthcare'    => ['HIPAA vendor agreements (BAAs)', 'Consent forms before every treatment', 'Reminders, no-shows and rebooking'],
        'legal'         => ['Engagement letters and conflict checks', 'Trust account reconciliation', 'Intake and deadline reminders'],
        'home_services' => ['Licenses, contracts and change orders', 'Lien waivers and notices', 'Estimate follow-up and maintenance reminders'],
        'ecommerce'     => ['Refund, shipping and privacy policies', 'Sales tax', 'Cart, support and returns automation'],
        'agency'        => ['Signed scopes and change requests', 'Kickoff checklists', 'Client reporting and renewals'],
        'other'         => ['Processes and SOPs', 'Contracts and invoicing', 'Follow-up and data security'],
    ];
}

function industry_label(string $key): string
{
    return industries()[$key] ?? 'Other';
}

/** Each item: [id, area, question, why we ask, how we can automate it]. Plain words, one idea each. */
function checklist_base(): array
{
    return [
        ['sop_library', 'process', 'Are your main tasks written down as step-by-step instructions?',
            'If steps only live in people\'s heads, work stops when they are out.',
            'We turn what we saw in your audit into written steps your team and AI agents can follow.'],
        ['onboarding', 'process', 'Does every new client get the same welcome steps?',
            'Skipped steps lead to mistakes, refunds and bad reviews.',
            'Welcome forms, contracts and next steps go out on their own, in order.'],
        ['crm', 'process', 'Do you keep every lead and client in one system (a CRM)?',
            'Leads get lost when they sit in inboxes and spreadsheets.',
            'Every new inquiry goes into your CRM automatically.'],
        ['scorecard', 'process', 'Do you check your key numbers (leads, sales, cash) every week?',
            "It's hard to fix what you don't look at.",
            'A weekly scorecard lands in your inbox every Monday.'],
        ['speed_to_lead', 'followup', 'Do new leads hear back from you within 5 minutes?',
            'The first business to reply usually wins.',
            'New leads get an instant text and email, and a link to book a call.'],
        ['nurture', 'followup', "When a lead doesn't answer, do you keep following up for 2 weeks or more?",
            'Most sales need several follow-ups. Most teams stop after one or two.',
            'Follow-ups run on their own and stop when the person replies.'],
        ['reviews', 'followup', 'Do happy clients get asked for a review?',
            'Reviews bring in new clients.',
            'Review requests go out at the right time. Unhappy clients come to you first.'],
        ['contract_templates', 'contracts', 'Do you use the same up-to-date contract for every client, signed online?',
            'Old or one-off contracts leave gaps.',
            'The contract goes out and is saved automatically when a client says yes.'],
        ['invoicing', 'contracts', 'Do invoices and payment reminders go out on time, every time?',
            'Late reminders mean late payments.',
            'Invoices and friendly reminders go out on their own.'],
        ['consent', 'compliance', 'Do you get permission before texting people, and stop right away when they say stop?',
            'Texting without permission (TCPA rules) can lead to big fines.',
            'Permission and opt-outs are saved in your CRM. Texts only go to people who said yes.'],
        ['policies', 'compliance', 'Does your website have an up-to-date privacy policy and terms?',
            'Often required by law, ad platforms and payment companies.',
            'We check your site and remind you to review them every year.'],
        ['access', 'data', 'Does everyone have their own logins, and do you remove access the day someone leaves?',
            'Shared passwords are a common way small businesses get hacked.',
            'A checklist removes access automatically when someone leaves.'],
        ['backups', 'data', 'Is your client data backed up, and have you tested getting it back?',
            "A backup you've never tested might not work.",
            'Automatic backups with a monthly test.'],
    ];
}

function checklist_industry(string $industry): array
{
    $items = [
        'insurance' => [
            ['ins_licenses', 'compliance', 'Do you track every agent\'s licenses and carrier appointments, with renewal dates?',
                'Selling with an expired license can cost you commissions and fines.',
                'Renewal reminders go out 60, 30 and 7 days before the deadline.'],
            ['ins_eo', 'compliance', 'Do you have current errors and omissions (E&O) insurance?',
                'One mistake on a policy can cost more than a year of profit.',
                'Your renewal date is tracked with reminders.'],
            ['ins_dnc', 'compliance', 'Do you check call lists against Do Not Call lists, and save permission before calling or texting?',
                'Calling or texting the wrong people is a common reason agencies get sued.',
                'Lists are checked and permission is saved on every web form automatically.'],
            ['ins_needs', 'contracts', "Do you write down each client's needs for every policy you sell?",
                'It shows the policy fit the client if there is ever a complaint.',
                'AI drafts the notes from the call. You check them and save.'],
            ['ins_renewals', 'followup', 'Do you reach out to clients 60–90 days before their policy renews?',
                'Most clients leave quietly at renewal time.',
                'Upcoming renewals are flagged and outreach starts on its own.'],
            ['ins_cross_sell', 'followup', 'Do you offer other coverage to clients who only have one policy?',
                'Clients with more than one policy stay longer.',
                'Single-policy clients get a short, friendly offer automatically.'],
            ['ins_complaints', 'compliance', 'Do you keep a log of complaints and answer them on time?',
                'Regulators may ask to see it.',
                'Complaints are logged with a due date and reminders.'],
        ],
        'lending' => [
            ['lend_license', 'compliance', 'Do you have every license your state requires to arrange or make loans?',
                'Working without the right license can cost you your fees and lead to fines. Mortgage loans also need NMLS registration.',
                'License renewals and training dates are tracked with reminders.'],
            ['lend_fees', 'contracts', 'Do clients get your fees in writing before you start, with no upfront fees where your state bans them?',
                'Many states limit upfront fees for loan brokers.',
                'A fee sheet is sent and signed before any work starts.'],
            ['lend_credit_pull', 'compliance', "Do you get written permission before pulling someone's credit?",
                'Pulling credit without permission breaks the Fair Credit Reporting Act.',
                'Permission is part of the online application and saved on the file.'],
            ['lend_adverse', 'compliance', 'When someone is declined, do they get the required written notice?',
                'Required by fair lending rules (ECOA). You or your lender partner must send it.',
                'Marking a file "declined" sends the right notice and saves a copy.'],
            ['lend_safeguards', 'data', 'Do you have a written plan for keeping customer financial data safe?',
                'Businesses that handle financial data are expected to have one (GLBA Safeguards Rule).',
                'We help you write it and set up the reminders and access checks.'],
            ['lend_docs', 'followup', 'Do you chase missing documents automatically until the file is complete?',
                'Stuck files are the top reason deals fall apart.',
                'Clients get texts and emails listing exactly what is still missing.'],
            ['lend_status', 'followup', "Do borrowers get updates at each step without having to call you?",
                'Status calls take hours every day.',
                'Each step in your pipeline sends the borrower an update.'],
            ['lend_ads', 'compliance', "Do your ads avoid promises like 'guaranteed approval' and include the required rate details?",
                'Misleading loan ads get regulators\' attention.',
                'Ads and posts are checked against a simple list before they go live.'],
        ],
        'funding' => [
            ['fund_agreement', 'contracts', 'Does every client sign an agreement that explains your fee and when you earn it?',
                'Clear terms prevent arguments about success fees.',
                'Your agreement is sent and signed online before you start on the file.'],
            ['fund_disclosure', 'contracts', 'Do clients get the full cost of each funding offer in writing before they sign?',
                'A growing number of states (including California, New York, Utah and Virginia) require written cost disclosures for business financing. Depending on the state, this falls on you, your lender partner or both.',
                'Each offer is turned into a simple cost summary and sent for signature.'],
            ['fund_partners', 'contracts', 'Do you have signed agreements with every lender and referral partner?',
                'They set your commission and who owns the client relationship.',
                'Partner agreements and commission terms are tracked in one place, with renewal dates.'],
            ['fund_upfront', 'compliance', 'Do you avoid charging upfront fees before a client gets funded?',
                "Upfront 'processing' fees are a top source of complaints and are restricted in some places.",
                'Fees are only invoiced when the deal funds.'],
            ['fund_registration', 'compliance', 'Are you registered as a broker in every state that requires it?',
                'Some states (Virginia, for example) require business financing brokers to register.',
                'Registration renewals are tracked with reminders.'],
            ['fund_claims', 'compliance', "Does your marketing avoid promises like 'guaranteed funding' or 'no credit check, approved in 24 hours'?",
                'Regulators, including the FTC, have acted against funding companies for misleading claims.',
                'Ads and posts are checked against a simple list before they go live.'],
            ['fund_credit_pull', 'compliance', "Do you get written permission before pulling a client's personal or business credit?",
                'Pulling credit without permission breaks the Fair Credit Reporting Act.',
                'Permission is part of the online application and saved on the file.'],
            ['fund_docs_secure', 'data', 'Do clients send bank statements and tax returns through a secure upload link, not regular email?',
                'These files hold account numbers. Financial businesses are expected to protect them (GLBA Safeguards Rule).',
                'Clients get a secure upload link, and files are stored in one safe place.'],
            ['fund_verify', 'process', 'Do you check bank statements and ID before sending a file to a lender?',
                'Altered bank statements are a common fraud, and lenders drop brokers who send them.',
                'Statements are checked automatically and anything unusual is flagged for you.'],
            ['fund_tracking', 'process', 'Do you track every lender submission, decision and offer in one place?',
                'Files get lost between lenders, and clients ask for updates all day.',
                'Each submission and offer is logged automatically on the client file.'],
            ['fund_docs', 'followup', 'Do missing documents get chased automatically until the file is complete?',
                'Incomplete files are the top reason deals stall.',
                'Clients get texts and emails listing exactly what is still missing.'],
            ['fund_status', 'followup', 'Do clients get an update at each step (submitted, approved, funded) without having to call you?',
                'Clients who are kept in the loop stay with you and refer others.',
                'Each step sends the client a short update.'],
            ['fund_not_yet', 'followup', "When a client isn't approved yet, do you give them a plan and check back later?",
                "Today's 'not yet' is often next quarter's deal (better credit, more time in business, higher bank balances).",
                'A follow-up plan starts on its own, with check-ins every 30 days.'],
            ['fund_renewals', 'followup', 'Do you reach out to funded clients when they qualify for more (a renewal, a better rate or a bank line)?',
                'Repeat funding is your easiest revenue.',
                'Funded clients get a check-in when they are likely to qualify again.'],
        ],
        'credit' => [
            ['cr_contract', 'contracts', 'Do credit repair clients sign a written contract with a 3-day right to cancel?',
                'Required by the Credit Repair Organizations Act. Contracts without it can be cancelled and fees refunded.',
                'The contract and cancel notice are sent, signed and saved automatically.'],
            ['cr_no_advance', 'compliance', 'Do you wait to charge until the work is done?',
                'Federal law bans charging for credit repair before the work is done.',
                'Billing only runs after each round of work is finished.'],
            ['cr_rights', 'contracts', "Do clients get the 'Consumer Credit File Rights' notice before they sign?",
                'It must be given before the contract.',
                'The notice is part of the signing packet, so it is never skipped.'],
            ['cr_state', 'compliance', "Are you registered or bonded in your state, if your state requires it?",
                'Many states require registration or a bond for credit services.',
                'Renewal dates are tracked with reminders.'],
            ['cr_claims', 'compliance', 'Does your marketing avoid promising to remove accurate items or raise scores?',
                'These promises are not allowed and cause most complaints.',
                'Ads and posts are checked against a simple list before they go live.'],
            ['cr_disputes', 'followup', 'Do you track dispute rounds by date and update clients every 30–45 days?',
                "Clients who don't hear from you cancel.",
                'You get reminders for each round, and clients get progress updates.'],
            ['cr_next_step', 'followup', 'When clients finish, do you offer them the next step (funding, credit cards, monitoring)?',
                'Your best new client is the one you just helped.',
                'A short "what\'s next" message goes out when the program ends.'],
        ],
        'real_estate' => [
            ['re_buyer_agreement', 'contracts', 'Do buyers sign a written agreement before you show them homes?',
                'Written buyer agreements are now standard and often required.',
                'The agreement is sent and signed when a showing is booked.'],
            ['re_transaction', 'process', 'Does every deal follow the same checklist from contract to closing?',
                'Missed deadlines can kill a deal.',
                'Deadlines are tracked and everyone gets reminders.'],
            ['re_fair_housing', 'compliance', 'Do you check listings and ads for fair housing wording?',
                'Fair housing mistakes carry serious penalties.',
                'Listings are checked against a simple list before they go live.'],
            ['re_lead_routing', 'followup', 'Do website and ad leads reach an agent within minutes?',
                'Online leads go cold fast.',
                'Leads get an instant reply and are sent to the right agent.'],
            ['re_sphere', 'followup', 'Do past clients hear from you regularly (home anniversaries, market updates)?',
                'Repeat and referral business is the cheapest business.',
                'Personal check-ins go out on a schedule.'],
        ],
        'healthcare' => [
            ['hc_baa', 'compliance', 'Do you have a signed agreement (BAA) with every company that handles patient data?',
                'HIPAA requires it. Using a tool without one can be a violation.',
                'We list every tool that touches patient data and flag missing agreements.'],
            ['hc_consent', 'contracts', 'Do patients sign consent and photo forms before every treatment?',
                'Missing forms are a legal and marketing risk.',
                'Forms are sent and signed before the visit and saved to the chart.'],
            ['hc_messaging', 'data', 'Do you send patient texts and emails through tools that are safe for health information?',
                'Regular email and texting tools often are not.',
                'Reminders and follow-ups move to a safe messaging tool.'],
            ['hc_oversight', 'compliance', 'Do you keep records of your medical director\'s oversight (med spas)?',
                'Required for many aesthetic treatments.',
                'Chart reviews and sign-offs are tracked with reminders.'],
            ['hc_no_show', 'followup', 'Do appointment reminders and no-show follow-ups go out on their own?',
                'No-shows are lost money you can get back.',
                'Reminders go out and no-shows are rebooked automatically.'],
            ['hc_rebook', 'followup', "Do patients hear from you when they're due for their next treatment?",
                'Repeat treatments are the core of the business.',
                'Patients get a reminder at the right time for each treatment.'],
        ],
        'legal' => [
            ['law_engagement', 'contracts', 'Does every client sign an engagement letter with the scope and fees?',
                'Unclear scope is the top cause of fee disputes.',
                'Letters are created from intake and sent for signature.'],
            ['law_conflicts', 'compliance', 'Do you run a conflict check before every new matter?',
                'Required by professional rules.',
                'Intake runs the conflict check before a matter can open.'],
            ['law_trust', 'compliance', 'Do you reconcile your client trust account every month?',
                'Trust account mistakes are a leading cause of discipline.',
                'Monthly reminders and a checklist run automatically.'],
            ['law_intake', 'process', 'Does intake ask the same questions every time?',
                'Consistent intake means better cases and fewer surprises.',
                'An intake assistant asks the questions and books the consult.'],
            ['law_deadlines', 'followup', 'Are court and filing deadlines on a calendar with reminders?',
                'Missed deadlines are a malpractice risk.',
                'Deadlines are added automatically with several reminders.'],
        ],
        'home_services' => [
            ['hs_license', 'compliance', 'Are your licenses, bond and insurance current and on file?',
                'Without them, contracts and insurance claims can fail.',
                'Renewal dates are tracked with reminders.'],
            ['hs_contracts', 'contracts', 'Does every job have a signed estimate, and are changes signed too?',
                'Unsigned changes are the top cause of payment fights.',
                'Estimates, contracts and change orders are signed online from the job site.'],
            ['hs_lien', 'contracts', 'Do you send the lien notices and collect the lien waivers your state requires?',
                'Missing notices can cost you your right to get paid.',
                'The right notices are created and tracked for each job.'],
            ['hs_dispatch', 'process', "Do you schedule jobs in one system and text customers when you're on the way?",
                'Double bookings and no-shows waste time.',
                'Jobs are scheduled and confirmed, with on-the-way texts.'],
            ['hs_estimates', 'followup', "Do you follow up on estimates that haven't been accepted?",
                'Most lost estimates were never followed up.',
                'Follow-ups run until the customer decides.'],
            ['hs_maintenance', 'followup', 'Do customers get reminders for seasonal service or check-ups?',
                'Maintenance plans bring steady repeat work.',
                'Seasonal service is booked automatically.'],
        ],
        'ecommerce' => [
            ['ec_policies', 'compliance', 'Are your refund, shipping and privacy policies posted and followed?',
                'Payment companies and marketplaces require them.',
                'Customer replies follow your policies automatically.'],
            ['ec_tax', 'compliance', 'Do you collect and file sales tax everywhere you need to?',
                'Online sales can create tax duties in other states.',
                'Tax tools and filing reminders are set up for you.'],
            ['ec_cart', 'followup', 'Do abandoned cart and after-purchase emails go out on their own?',
                'Cart emails win back a good share of lost sales.',
                'Cart, thank-you and come-back emails run on their own.'],
            ['ec_support', 'process', 'Are common customer questions answered with saved replies or a help page?',
                'The same few questions make up most support requests.',
                'An assistant answers common questions and passes the rest to you.'],
            ['ec_returns', 'process', 'Do returns and chargebacks follow a set process?',
                'Chargebacks you do not answer are lost automatically.',
                'Returns and chargebacks are tracked with deadlines.'],
        ],
        'agency' => [
            ['ag_sow', 'contracts', 'Does every project have a signed scope and payment terms?',
                'Extra unpaid work is the biggest profit leak for agencies.',
                'Proposals turn into signed agreements and invoices automatically.'],
            ['ag_kickoff', 'process', 'Does every new client get the same kickoff checklist?',
                'A strong start sets expectations.',
                'The kickoff checklist runs on its own and collects logins.'],
            ['ag_reporting', 'followup', 'Do clients get regular results reports without manual work?',
                'Clients who see results renew.',
                'Reports are built and sent on schedule.'],
            ['ag_change', 'process', 'Do you track and bill requests that are outside the agreed scope?',
                'Unbilled extra work erases your margin.',
                'Requests are logged and turned into change orders.'],
            ['ag_renewals', 'followup', 'Do you plan renewals before contracts end?',
                'Renewals left to the last week are often lost.',
                'Renewal conversations start 60 days out.'],
        ],
    ];
    return $items[$industry] ?? [];
}

/** Base items plus the industry's items, keyed by id. */
function checklist_items(string $industry): array
{
    $out = [];
    foreach (array_merge(checklist_industry($industry), checklist_base()) as [$id, $area, $q, $why, $auto]) {
        $out[$id] = ['id' => $id, 'area' => $area, 'q' => $q, 'why' => $why, 'auto' => $auto];
    }
    return $out;
}

/**
 * Score answers: yes = 1, partly = 0.5, no / not sure = 0. Unanswered items don't count.
 * @return array{answered:int,total:int,score:?int,areas:array<string,array{label:string,score:?int,answered:int,total:int}>,gaps:array}
 */
function checklist_summary(string $industry, array $answers): array
{
    $items  = checklist_items($industry);
    $points = ['yes' => 1.0, 'partly' => 0.5, 'no' => 0.0, 'unsure' => 0.0];
    $areas  = [];
    foreach (CHECK_AREAS as $key => $label) {
        $areas[$key] = ['label' => $label, 'sum' => 0.0, 'answered' => 0, 'total' => 0];
    }
    $gaps = [];
    $sum = 0.0;
    $answered = 0;
    foreach ($items as $id => $item) {
        $areas[$item['area']]['total']++;
        $a = $answers[$id] ?? '';
        if (!isset($points[$a])) {
            continue;
        }
        $answered++;
        $sum += $points[$a];
        $areas[$item['area']]['answered']++;
        $areas[$item['area']]['sum'] += $points[$a];
        if ($a !== 'yes') {
            $gaps[] = $item + ['answer' => $a];
        }
    }
    foreach ($areas as $key => &$area) {
        $area['score'] = $area['answered'] ? (int) round(100 * $area['sum'] / $area['answered']) : null;
        unset($area['sum']);
        if ($area['total'] === 0) {
            unset($areas[$key]);
        }
    }
    unset($area);
    // Risk gaps (compliance, contracts, data) first; then "no"/"not sure" before "partly".
    $rank = ['compliance' => 0, 'contracts' => 1, 'data' => 2, 'followup' => 3, 'process' => 4];
    $key  = fn($g) => [$rank[$g['area']] > 2, $g['answer'] === 'partly', $rank[$g['area']]];
    usort($gaps, fn($x, $y) => $key($x) <=> $key($y));

    return [
        'answered' => $answered,
        'total'    => count($items),
        'score'    => $answered ? (int) round(100 * $sum / $answered) : null,
        'areas'    => $areas,
        'gaps'     => $gaps,
    ];
}

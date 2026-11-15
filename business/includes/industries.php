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
    'partly' => 'Partly',
    'no'     => 'No',
    'unsure' => 'Not sure',
];

function industries(): array
{
    return [
        'insurance'     => 'Insurance agency',
        'lending'       => 'Lending & loan brokers',
        'credit'        => 'Credit repair & business funding',
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

/** Each item: [id, area, question, why it matters, how it can be automated]. */
function checklist_base(): array
{
    return [
        ['sop_library', 'process', 'Your key processes (sales, onboarding, delivery, billing) are written down as step-by-step SOPs.',
            'People and AI agents can only follow what is written down.',
            'We turn your audit results into SOPs, then into instructions for your AI agents.'],
        ['onboarding', 'process', 'Every new client goes through the same onboarding checklist.',
            'Skipped steps cause rework, refunds and bad reviews.',
            'An onboarding agent sends forms, contracts and welcome steps in order.'],
        ['crm', 'process', 'Every lead and client lives in one CRM, not in spreadsheets and inboxes.',
            'Leads get lost when they live in five places.',
            'A lead capture agent puts every inquiry into the CRM automatically.'],
        ['scorecard', 'process', 'You see your key numbers (leads, sales, cash) in one weekly scorecard.',
            "You can't fix what you don't see every week.",
            'A reporting agent builds and sends your scorecard every Monday.'],
        ['speed_to_lead', 'followup', 'New leads get a reply within 5 minutes, day or night.',
            'The first business to reply usually wins the customer.',
            'An instant-reply agent answers by text and email and books the call.'],
        ['nurture', 'followup', "Leads who don't answer get a set follow-up sequence for at least 2 weeks.",
            'Most sales happen after the 5th touch; most teams stop after 1 or 2.',
            'A follow-up sequence runs by itself and stops when they reply.'],
        ['reviews', 'followup', 'Happy clients are asked for a review automatically.',
            'Reviews drive referrals and search ranking.',
            'A review agent asks at the right moment and routes unhappy clients to you first.'],
        ['contract_templates', 'contracts', 'You use standard, up-to-date contract templates that clients sign electronically.',
            'Old or one-off contracts leave gaps in scope, payment and liability.',
            'Contracts go out and get filed automatically when a client says yes.'],
        ['invoicing', 'contracts', 'Invoices and payment reminders go out on a schedule.',
            'Late reminders mean late cash.',
            'An invoice agent sends invoices and polite reminders and logs payments.'],
        ['consent', 'compliance', 'You record consent before texting or auto-dialing people, and honor opt-outs right away (TCPA).',
            'Texting or auto-dialing without consent can mean large per-message penalties.',
            'Consent and opt-outs are tracked in the CRM, and texts are blocked without consent.'],
        ['policies', 'compliance', 'Your website has a current privacy policy and terms.',
            'Required by law in many places, and by ad platforms and payment processors.',
            'We flag missing pages and keep a review date on your calendar.'],
        ['access', 'data', 'Each person has their own logins and a password manager, and access is removed the day someone leaves.',
            'Shared passwords are the most common way small businesses get breached.',
            'An offboarding checklist runs automatically when someone leaves.'],
        ['backups', 'data', 'Client files and data are backed up and you have tested restoring them.',
            "A backup you've never restored is a guess.",
            'Automated backups with a monthly restore test.'],
    ];
}

function checklist_industry(string $industry): array
{
    $items = [
        'insurance' => [
            ['ins_licenses', 'compliance', "Every producer's state licenses and carrier appointments are tracked with renewal dates.",
                'Selling with a lapsed license or appointment can void commissions and trigger fines.',
                'A license-tracking agent sends renewal reminders 60, 30 and 7 days out.'],
            ['ins_eo', 'compliance', 'You carry current errors & omissions (E&O) insurance.',
                'One coverage mistake can cost more than a year of profit.',
                'Renewal date tracked with automatic reminders.'],
            ['ins_dnc', 'compliance', 'Call lists are checked against Do Not Call lists, and consent is saved before quoting by phone or text.',
                'Telemarketing violations are a common source of lawsuits for agencies.',
                'Lists are scrubbed and consent is captured on every web form automatically.'],
            ['ins_needs', 'contracts', 'A needs or suitability note is documented for every policy sold (especially life and annuities).',
                'It is your proof the product fit the client if a complaint or audit comes.',
                'AI drafts the needs summary from the call notes; the agent reviews and saves it.'],
            ['ins_renewals', 'followup', 'Renewals are reviewed 60–90 days before they expire, with automatic outreach.',
                'Most agency churn happens quietly at renewal.',
                'A renewal agent flags upcoming renewals, starts outreach and books the review.'],
            ['ins_cross_sell', 'followup', 'Single-policy clients are offered the other coverage they need (bundling).',
                'Bundled clients stay longer and are worth more.',
                'A cross-sell agent finds single-policy clients and starts a short sequence.'],
            ['ins_complaints', 'compliance', 'Complaints are logged and answered within a set time.',
                'Regulators may ask to see your complaint log.',
                'Complaints are logged automatically with a due date and reminders.'],
        ],
        'lending' => [
            ['lend_license', 'compliance', 'You hold every license your state requires to broker or make loans (and NMLS registration for mortgage loans).',
                'Brokering without the right license can make fees unrecoverable and lead to penalties.',
                'License renewals and continuing-education dates are tracked with reminders.'],
            ['lend_fees', 'contracts', 'Your fees are disclosed in writing before work starts, and you do not take upfront fees where your state prohibits them.',
                'Many states restrict advance fees for loan brokering.',
                'A fee disclosure is sent and signed automatically before any work begins.'],
            ['lend_credit_pull', 'compliance', "You get written permission before pulling anyone's credit.",
                'Pulling credit without a permissible purpose violates the Fair Credit Reporting Act.',
                'Credit authorization is part of the e-signed application, stored on the file.'],
            ['lend_adverse', 'compliance', 'Declined applicants receive the required adverse action notice (from you or your lender partner).',
                'Required under the Equal Credit Opportunity Act for credit decisions.',
                'A declined status in the CRM triggers the right notice and logs it.'],
            ['lend_safeguards', 'data', 'You have a written information security plan for customer financial data (GLBA Safeguards Rule).',
                'Financial businesses that handle customer data are expected to have one.',
                'We help document it and automate access reviews and reminders.'],
            ['lend_docs', 'followup', 'Missing documents are chased automatically until the file is complete.',
                'Stalled files are the #1 reason deals fall through.',
                'A document-chaser agent texts and emails the exact items still missing.'],
            ['lend_status', 'followup', 'Borrowers get status updates at each step without having to call you.',
                'Status calls eat hours every day and frustrate clients.',
                'Each pipeline stage sends the borrower an automatic update.'],
            ['lend_ads', 'compliance', "Your ads and posts avoid promises like 'guaranteed approval' and include required rate and term disclosures.",
                'Misleading credit advertising draws regulator attention.',
                'Ad and post drafts are checked against a compliance list before publishing.'],
        ],
        'credit' => [
            ['cr_contract', 'contracts', 'Credit repair clients sign a written contract with the disclosures the Credit Repair Organizations Act requires, including a 3-business-day right to cancel.',
                'Contracts missing required terms can be voided, with fees refunded.',
                'The contract and cancel notice are sent, signed and stored automatically.'],
            ['cr_no_advance', 'compliance', "You don't charge for credit repair work before it has been performed.",
                'Federal law bans advance fees for credit repair.',
                'Billing is triggered only after each round of work is completed and logged.'],
            ['cr_rights', 'contracts', "Clients receive the 'Consumer Credit File Rights Under State and Federal Law' disclosure before signing.",
                'It is a required disclosure before the contract.',
                'The disclosure is built into the e-sign packet so it is never skipped.'],
            ['cr_state', 'compliance', "You meet your state's registration or bonding requirements for credit services.",
                'Many states require registration or a surety bond.',
                'Renewal dates are tracked with reminders.'],
            ['cr_claims', 'compliance', 'Your marketing never promises to remove accurate negative items or guarantees score increases.',
                'These claims are prohibited and are a top source of complaints.',
                'Ad and post drafts are checked against a compliance list before publishing.'],
            ['cr_disputes', 'followup', 'Dispute rounds are tracked with dates, and clients get progress updates every 30–45 days.',
                "Clients who don't hear from you cancel.",
                'A dispute-tracker agent reminds you of round dates and sends client updates.'],
            ['fund_disclosure', 'contracts', 'Business funding clients get written fee and cost disclosures (some states, such as California and New York, require them for commercial financing).',
                'Disclosure laws for commercial financing are spreading state by state.',
                'Disclosures are generated from the deal details and sent for signature.'],
            ['cr_next_step', 'followup', 'Clients who finish the program are offered the next step (funding, credit cards, monitoring).',
                'Your best new client is the one you just helped.',
                'A graduation sequence starts automatically when the program ends.'],
        ],
        'real_estate' => [
            ['re_buyer_agreement', 'contracts', 'Buyers sign a written buyer agreement before you tour homes with them.',
                'Written buyer agreements are now standard practice and often required.',
                'The agreement is sent and signed automatically when a showing is booked.'],
            ['re_transaction', 'process', 'Every deal follows a transaction checklist from contract to close.',
                'Missed deadlines kill deals and create liability.',
                'A transaction agent tracks deadlines and reminds everyone involved.'],
            ['re_fair_housing', 'compliance', 'Listings and ads are reviewed for fair housing language.',
                'Fair housing violations carry serious penalties.',
                'Listing and ad drafts are checked against a fair housing list before publishing.'],
            ['re_lead_routing', 'followup', 'Portal and ad leads are routed to an agent instantly.',
                'Online leads go cold within minutes.',
                'Leads are routed and answered instantly, then handed to an agent.'],
            ['re_sphere', 'followup', 'Past clients and your sphere get regular touchpoints (home anniversaries, market updates).',
                'Repeat and referral business is the cheapest business.',
                'A sphere agent sends personal touchpoints on a schedule.'],
        ],
        'healthcare' => [
            ['hc_baa', 'compliance', 'You have signed Business Associate Agreements (BAAs) with every vendor that handles patient data (HIPAA).',
                'Using a vendor without a BAA for patient data can be a HIPAA violation.',
                'We list every tool that touches patient data and flag missing BAAs.'],
            ['hc_consent', 'contracts', 'Treatment consent and photo-release forms are signed before every procedure.',
                'Missing consent is a liability and a marketing risk.',
                'Forms are sent and signed before the appointment and stored on the chart.'],
            ['hc_messaging', 'data', 'Patient texts and emails go through HIPAA-appropriate tools, with no patient details in plain email.',
                'Everyday email and texting tools are often not set up for patient data.',
                'Reminders and follow-ups move to a compliant messaging tool.'],
            ['hc_oversight', 'compliance', 'Medical director or supervising provider oversight is documented (med spas).',
                'Required for many aesthetic treatments.',
                'Chart-review reminders and sign-offs are tracked automatically.'],
            ['hc_no_show', 'followup', 'Appointment reminders and no-show follow-ups go out automatically.',
                'No-shows are lost revenue you can recover.',
                'A scheduling agent sends reminders and rebooks no-shows.'],
            ['hc_rebook', 'followup', 'Patients are reminded when they are due for their next treatment.',
                'Repeat treatments are the core of med spa revenue.',
                'A rebooking agent reaches out at the right interval for each treatment.'],
        ],
        'legal' => [
            ['law_engagement', 'contracts', 'Every client signs an engagement letter that defines scope and fees.',
                'Unclear scope is the top cause of fee disputes and bar complaints.',
                'Engagement letters are generated from intake and sent for e-signature.'],
            ['law_conflicts', 'compliance', 'A conflict check runs before every new matter.',
                'Required by professional conduct rules.',
                'Intake triggers a conflict check before the matter can open.'],
            ['law_trust', 'compliance', 'Client funds are kept in a trust account that is reconciled every month.',
                'Trust account errors are a leading cause of discipline.',
                'Monthly reconciliation reminders and checklists run automatically.'],
            ['law_intake', 'process', 'Intake follows the same questions and qualification steps every time.',
                'Consistent intake means better cases and fewer surprises.',
                'An intake agent asks the questions, qualifies and books the consult.'],
            ['law_deadlines', 'followup', 'Court and filing deadlines are calendared with reminders.',
                'Missed deadlines are malpractice risk.',
                'Deadlines are calendared automatically with layered reminders.'],
        ],
        'home_services' => [
            ['hs_license', 'compliance', 'Licenses, bonds and liability insurance are current and on file.',
                'Working without them can void contracts and insurance claims.',
                'Renewal dates are tracked with reminders.'],
            ['hs_contracts', 'contracts', 'Every job has a written estimate and signed contract, and change orders are signed.',
                'Unsigned changes are the top cause of payment disputes.',
                'Estimates, contracts and change orders go out for e-signature from the field.'],
            ['hs_lien', 'contracts', 'Lien waivers and required notices are collected where your state requires them.',
                'Missing notices can cost you your right to get paid.',
                'Required notices are generated and tracked per job.'],
            ['hs_dispatch', 'process', 'Scheduling and dispatch run in one system, with on-the-way texts to customers.',
                'Double-booking and no-shows waste truck time.',
                'A dispatch agent schedules, confirms and sends arrival notices.'],
            ['hs_estimates', 'followup', 'Unsold estimates get automatic follow-up.',
                'Most unsold estimates were never followed up.',
                'An estimate follow-up sequence runs until the customer decides.'],
            ['hs_maintenance', 'followup', 'Customers get maintenance reminders (seasonal service, warranty checkups).',
                'Maintenance plans create steady, repeat revenue.',
                'A maintenance agent books seasonal service automatically.'],
        ],
        'ecommerce' => [
            ['ec_policies', 'compliance', 'Refund, shipping and privacy policies are posted and followed.',
                'Required by payment processors and marketplaces.',
                'Policy pages are checked and customer replies follow them automatically.'],
            ['ec_tax', 'compliance', "Sales tax is collected and filed where you're required to.",
                'Online sales can create tax obligations in other states.',
                'Tax tools and filing reminders are set up and tracked.'],
            ['ec_cart', 'followup', 'Abandoned carts and post-purchase emails run automatically.',
                'Abandoned cart emails recover a meaningful share of lost sales.',
                'Cart, post-purchase and win-back sequences run by themselves.'],
            ['ec_support', 'process', 'Common customer questions are answered from a help center or saved replies.',
                'The same 10 questions usually make up most support tickets.',
                'A support agent answers common questions and hands off the rest.'],
            ['ec_returns', 'process', 'Returns and chargebacks follow a documented process.',
                'Chargebacks you do not answer are lost automatically.',
                'Returns and chargeback responses are tracked with deadlines.'],
        ],
        'agency' => [
            ['ag_sow', 'contracts', 'Every project has a signed proposal or statement of work with scope and payment terms.',
                'Scope creep is the biggest profit leak for agencies.',
                'Proposals turn into signed agreements and invoices automatically.'],
            ['ag_kickoff', 'process', 'Client kickoff follows a standard checklist and timeline.',
                'A strong start sets expectations for the whole engagement.',
                'An onboarding agent runs the kickoff checklist and collects access.'],
            ['ag_reporting', 'followup', 'Clients get regular results reports without manual work.',
                'Clients who see results renew.',
                'A reporting agent builds and sends client reports on schedule.'],
            ['ag_change', 'process', 'Out-of-scope requests are logged and billed as change requests.',
                'Unbilled extra work quietly erases margin.',
                'Requests are logged and turned into change orders automatically.'],
            ['ag_renewals', 'followup', 'Retainer renewals and upsells are planned before contracts end.',
                'Renewals left to the last week are often lost.',
                'A renewal agent starts the conversation 60 days out.'],
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

/*
 * Industries for the "What a Team Audit adds" card.
 * Keys and labels must match business/includes/industries.php.
 */

'use strict';

const INDUSTRIES = {
  insurance: {
    label: 'Insurance agency',
    checks: ['License and E&O tracking', 'Call and text consent and Do Not Call', 'Renewal and cross-sell follow-up']
  },
  lending: {
    label: 'Lending & loan brokers',
    checks: ['Licensing and written fee disclosures', 'Credit-pull permission and data security', 'Document chasing and status updates']
  },
  funding: {
    label: 'Business funding & bank approvals',
    checks: ['Fee agreements and state disclosures', 'Secure, verified bank statements', 'Lender submissions, approvals and renewals']
  },
  credit: {
    label: 'Credit repair',
    checks: ['Credit repair contract, disclosures and 3-day cancel right', 'No fees before the work is done', 'Dispute tracking and client updates']
  },
  real_estate: {
    label: 'Real estate',
    checks: ['Written buyer agreements', 'Transaction checklists and deadlines', 'Instant lead routing and sphere follow-up']
  },
  healthcare: {
    label: 'Healthcare & med spas',
    checks: ['HIPAA vendor agreements (BAAs)', 'Consent forms before every treatment', 'Reminders, no-shows and rebooking']
  },
  legal: {
    label: 'Law firms',
    checks: ['Engagement letters and conflict checks', 'Trust account reconciliation', 'Intake and deadline reminders']
  },
  home_services: {
    label: 'Home services & contractors',
    checks: ['Licenses, contracts and change orders', 'Lien waivers and notices', 'Estimate follow-up and maintenance reminders']
  },
  ecommerce: {
    label: 'E-commerce',
    checks: ['Refund, shipping and privacy policies', 'Sales tax', 'Cart, support and returns automation']
  },
  agency: {
    label: 'Agencies & consultants',
    checks: ['Signed scopes and change requests', 'Kickoff checklists', 'Client reporting and renewals']
  },
  other: {
    label: 'Other',
    checks: ['Processes and SOPs', 'Contracts and invoicing', 'Follow-up and data security']
  }
};

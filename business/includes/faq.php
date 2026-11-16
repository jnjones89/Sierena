<?php
/* Plain-language FAQ. Used by the portal Help tab and the public help.php page. */
$faq = [
    'What does the extension see?' =>
        'Only the names of the websites you use in Chrome (like mail.google.com) and how long you spend on each. Never your emails, messages, files, passwords, what you type, or what is on the page.',
    'Does it change anything on my computer?' =>
        'No. It is read-only. It does not edit, move or delete anything.',
    'How do I remove it?' =>
        'Right-click the extension icon in Chrome and choose "Remove from Chrome". Its data on your computer is deleted with it.',
    'What does my company see?' =>
        'On a Team Audit, the owner sees the tools the team uses, time spent and repeated tasks, by role. Never what you type, read or send.',
    'Does it track me all the time?' =>
        'Only while tracking is on. It pauses by itself when you step away, lock your screen or switch to another app, and you can turn it off with the switch at any time.',
    'How long should I run it?' =>
        'The free audit needs 5 hours of work. For a Solo or Team Audit, a few days to a week gives the clearest picture.',
    'What about apps outside Chrome?' =>
        'The extension only sees websites. Add desktop apps (like QuickBooks Desktop or your phone system) on the "Your tools" page so they count in your report.',
    'Is my data shared or sold?' =>
        'No. It is only used for your audit and your report.',
    'How does billing work?' =>
        'Solo and Team Audits are monthly. Cancel any time under "Billing & invoices" in your Business Portal. Your key keeps working until the end of the month you paid for.',
    'Can I get a refund?' => billing_terms_text(),
    'How do I delete my data?' =>
        'In the Business Portal, open Help, then "Your data". You can download everything or delete it.',
];
?>
<div class="faq">
  <?php foreach ($faq as $q => $a): ?>
    <details><summary><?= h($q) ?></summary><p><?= h($a) ?></p></details>
  <?php endforeach; ?>
</div>

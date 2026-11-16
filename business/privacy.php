<?php
/* Privacy policy (public). Linked from the footer, the extension and the Chrome Web Store listing. */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

$brand   = (string) cfg('brand_name');
$contact = (string) cfg('owner_email');
$updated = 'November 16, 2026';

render_header('Privacy policy');
?>
  <h1>Privacy policy</h1>
  <p class="lead">Workflow Shadow Audit by <?= h($brand) ?>. Last updated <?= h($updated) ?>.</p>

  <section class="card">
    <h2>The short version</h2>
    <ul class="clean">
      <li>The extension only sees <b>website names</b> (like <i>mail.google.com</i>) and <b>time spent</b>. Never what you type, read or click.</li>
      <li>It is <b>read-only</b>. It never changes anything on your computer or in your accounts.</li>
      <li>Your free audit stays on your computer until you choose to send it.</li>
      <li>We never sell your data, and we don't use it for ads.</li>
      <li>You can remove the extension at any time: right-click its icon and choose <b>Remove from Chrome</b>. Its data on your computer goes with it.</li>
    </ul>
  </section>

  <section class="card">
    <h2>What the extension records on your computer</h2>
    <ul class="clean">
      <li>The domain of the active tab (for example <i>docs.google.com</i> is kept as <i>docs.google.com</i>; full web addresses, page paths, file IDs and search terms are removed before anything is saved).</li>
      <li>How long the tab was active, how many times you visited, and short visits under 2 minutes.</li>
      <li>Which website you switched from and to (for example Zoom → Gmail), and repeated sequences of websites.</li>
      <li>Whether the computer is idle, so idle time is not counted.</li>
      <li>The role and industry you pick, and your settings.</li>
    </ul>
    <p>The extension does <b>not</b> read page content, emails, messages, documents, files on your computer, passwords, form entries, keystrokes or screenshots. It has no permission to do so: it only uses Chrome's <i>tabs</i>, <i>idle</i> and <i>storage</i> permissions.</p>
  </section>

  <section class="card">
    <h2>What is sent to us, and when</h2>
    <ul class="clean">
      <li><b>Free audit:</b> nothing is sent while you work. When you enter your work email to see your results, we receive your email, role, industry and a short summary of the results (your main bottleneck, the task you repeat most and the suggested AI agent).</li>
      <li><b>Paid audits (team key):</b> when you enter a team key, we receive the key, a random ID for this browser and your role. When you press <b>Send to my Business Portal</b>, we receive your audit summary: website names, time, visit counts, switches between websites and repeated sequences, linked to your company's key. Your company sees this by role, not as a browsing history.</li>
      <li><b>Survey:</b> if you answer the short survey, we receive your answers.</li>
      <li><b>Webhook (optional):</b> if you add your own webhook address in the extension, your audit summary is sent to that address, not to us.</li>
    </ul>
  </section>

  <section class="card">
    <h2>Business Portal (paid customers)</h2>
    <ul class="clean">
      <li>What you enter: your business profile, social media links, answers to the questions and the Client journey, and the software you pay for.</li>
      <li><b>CRM check (optional):</b> if you connect GoHighLevel, we use a read-only token you create, stored encrypted. We read the last 90 days and keep <b>numbers only</b> (for example reply speed, show rate and close rate). We never save names, emails, phone numbers or message text from your CRM, and we never change anything in it. Disconnecting deletes the token and the numbers.</li>
      <li><b>Payments</b> are handled by Stripe. We never see or store your card number.</li>
      <li><b>Instant AI report:</b> when you ask for it, your audit summary and answers are sent to Anthropic (Claude) to write the report. Anthropic does not use this data to train its models.</li>
    </ul>
  </section>

  <section class="card">
    <h2>How we use it</h2>
    <p>Only to give you your audit, your reports and your plan, to contact you about them (we keep your contact details and a summary in our own CRM, GoHighLevel, for follow-up emails), to support you, and to improve the audit. We share data only with the services that run it (hosting, Stripe, Anthropic, GoHighLevel) and, if you ask us to build your AI agents, with our build partner ALJA LLC. We do not sell or rent your data.</p>
    <p>Our use of information from the extension follows the <a href="https://developer.chrome.com/docs/webstore/program-policies/limited-use" target="_blank" rel="noopener">Chrome Web Store User Data Policy</a>, including the Limited Use requirements.</p>
  </section>

  <section class="card">
    <h2>Keeping, downloading and deleting your data</h2>
    <ul class="clean">
      <li>In the extension, <b>Download my data</b> saves everything it recorded. Removing the extension deletes it from your computer.</li>
      <li>In the Business Portal, under <b>Help</b>, you can download all your company's data or delete it. We keep only the purchase record, which we need for receipts and refunds.</li>
      <li>For a free audit, email us and we will delete your email and results.</li>
      <li>We keep paid audit data while your subscription is active, and delete it on request.</li>
    </ul>
  </section>

  <section class="card">
    <h2>Security</h2>
    <p>All data is sent over HTTPS. CRM tokens are encrypted. Access to customer data is limited to our team.</p>
  </section>

  <section class="card">
    <h2>Contact</h2>
    <p>Questions or requests: <b><?= h($contact) ?></b>. If we change this policy, we will update the date above.</p>
  </section>
<?php
render_footer();

# sirenaflow.com

Source for the SirenaFlow marketing site. Static HTML, hosted on Hostinger (`public_html/`).

## Files
- `index.html` — main site (pages switch via CSS `:target`: home, how, audit, book)
- `privacy-policy.html`, `terms.html` — legal pages
- `assets/` — logo
- `images/` — team photos, founder cover
- `videos/` — explainer video + poster

## Not in this repo
- `videos/founder-message.mp4` (190MB, over GitHub's 100MB limit) stays on Hostinger only.
- GHL chat widget and booking calendar load from LeadConnector scripts inside `index.html`.

## Deploying
Upload changed files to `public_html/` in Hostinger File Manager, keeping the same folder layout.

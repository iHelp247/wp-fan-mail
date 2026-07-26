<!-- iHelp247 WP Fan Mail - (c) ULXI - UnLimited eXchange, Inc. - published under the iHelp247 brand - https://ihelp247.com - GPL-2.0-or-later -->
# iHelp247 WP Fan Mail

*An iHelp247 by [ULXI, UnLimited eXchange, Inc.](https://ulxi.com) project. iHelp247 publishes tools, paying it forward to support the open-source community. Support: [ihelp247.com](https://ihelp247.com).*

**Easy by default, powerful when you need it, blocking nobody.** That's the design principle: a fresh install ships a working Contact form and a live-preview editor that gets a non-technical user to a great form in two minutes — while a per-form CSS box, stable classes, carrier and rendering filters keep every door open for developers.

**Fan mail** for your WordPress website: name a form in plain words and it answers to that name everywhere — the form you call *"Say hello"* becomes `[fan-mail form="say-hello"]` in a page and `{fan-mail-say-hello}` in raw HTML, including a [WP Intermission](https://github.com/iHelp247/wp-intermission) maintenance page. **The database is the inbox; email is the courtesy copy**: every message is written to your site's own database *before* any sending happens, so a broken mail setup can never lose a message. Delivery via `wp_mail` or SendGrid in v1 (SMTP, Microsoft 365, and Google connectors on the roadmap). No analytics, no upsells, no paywall.

Published by **iHelp247 — by ULXI** · GPL-2.0-or-later · Support: [ihelp247.com](https://ihelp247.com)

## What it does

- **Named forms, human slugs**: create forms under **Fan Mail → Forms** — each gets a slug from its name (never `fan-mail-001`). Bare `[fan-mail]` / `{fan-mail}` always means your first form. Unknown tokens render as nothing (fail open — never a broken page).
- **Database-first storage**, CRM-shaped: a `contacts` table (one row per email address — name, phone, consent, message count, first/last seen) and a `messages` table (subject, message, form, page URL, status, delivery result). **Fan Mail → Inbox** shows it all: unread badge on the admin menu, read/unread/replied status, search, per-form and per-status filters, bulk actions, one-click **CSV export**.
- **Delivery carriers**: `wp_mail` (zero setup) or **SendGrid** (API key + verified sender — the easy fix for spam-folder problems). Reply-To is always the visitor, From is always a site-owned address (visitor-as-From would fail DMARC). Per-message delivery results are recorded in the Inbox; a **Send-a-test** button lives on the settings page. Add carriers with the `ihelp247_wp_fan_mail_carriers` filter.
- **Anti-spam without beacons**: honeypot field + HMAC-signed render timestamp (too fast = bot, too old = stale) + per-IP rate limiting via transients. **No nonces on the public form by design** — page caches (Plesk nginx, Cloudflare, cache plugins) can serve a form for days; stale nonces would cause mysterious "session expired" failures, the signed timestamp doesn't.
- **Works without JavaScript**: fetch()-based submit with inline success/error, and a full POST + redirect fallback when JS is off.
- **Consent & privacy**: optional per-form consent checkbox with editable wording, opt-in IP storage (off by default), configurable retention (auto-delete after N days, daily sweep), and core **Export / Erase Personal Data** integration keyed by email.
- **Live preview editor**: intro text, field toggles (name/phone/subject), button label, thank-you message, recipients, accent color, light/dark/auto theme — previewed instantly against a light *and* a dark page (the dark one matches a WP Intermission backdrop).
- **Corner credit**: forms carry a small "fan mail by iHelp247" link, removable with one checkbox on the settings page — free either way.

## Install

Download the latest release zip and install via **Plugins → Add New → Upload Plugin**, or clone into `wp-content/plugins/wp-fan-mail/`. Activate — a "Contact" form is ready immediately. Put `[fan-mail]` in a page, send yourself a message, and find it under **Fan Mail → Inbox**.

## Using it with WP Intermission

The sibling plugin filters its final page HTML through `ihelp247_wp_intermission_html`; Fan Mail hooks that filter and replaces `{fan-mail-…}` tokens with working forms. So a coming-soon page that takes messages is: WP Intermission in Full-custom-HTML (or any) mode, plus `{fan-mail-contact}` wherever the form should sit. Submissions ride admin-ajax, which maintenance mode never blocks — both plugins designed it that way. Set the form's theme to **Dark** to sit naturally on the stock Intermission backdrop.

## Configuration

Every tunable is a constant at the top of `wp-fan-mail.php`, in one labeled block: capability, anti-spam windows (minimum seconds, maximum cached-page age, rate limits), option names, and the Support-row links. Settings live under **Fan Mail → Settings**: carrier, SendGrid key (or `define( 'IH247_WPFM_SENDGRID_KEY', '…' )` in `wp-config.php` to keep it out of the database), From name/address, IP storage, retention, uninstall data policy.

Developer surface: filters `ihelp247_wp_fan_mail_carriers`, `ihelp247_wp_fan_mail_form_html`, `ihelp247_wp_fan_mail_recipients`, `ihelp247_wp_fan_mail_docs_url`; action `ihelp247_wp_fan_mail_after_submit( $message_id, $form, $visitor, $mail_result )`; stable `.ihfm-` classes on every rendered element; per-form custom-CSS box in the editor.

## Deliverability (read this once — it saves a support ticket)

A contact form has two halves: storing the message (this plugin's database — always works) and emailing you a copy (the internet — often doesn't). If notifications land in spam or nowhere: shared-host `wp_mail` sends from an address the host can't authenticate, and receiving servers distrust it. The fixes, in order of effort: set the **From address** to your own domain; add **SPF and DKIM** DNS records for whatever sends your mail; or switch the carrier to **SendGrid** (free tier, verified single sender in minutes, domain authentication when you're ready) — which is why it ships in v1. The user guide's deliverability section walks through all of it in plain language. And whatever the email does, the message itself is safe in the Inbox.

## Supporting the project

The settings page ends with a low-key Support row: if someone has tried the plugin and it's earning its keep, they can leave a review or keep the energy drinks and coffee flowing — one Square checkout, any amount, one-time or monthly (chosen at checkout), straight to feeding the developers' break room. A Support link points at [ihelp247.com/support](https://ihelp247.com/support). All links open in a new tab so nobody loses their work. Next to it sits the checkbox that removes the corner credit from the forms — deliberately together and deliberately free: find, try, see value, let us know to keep going. The `assets/` folder ships the iHelp247 wordmark as dark and light SVGs.

## Updates & distribution

Distributed via GitHub Releases (this repo). **Updates are built in**: the plugin answers WordPress's native `update_plugins_github.com` hook (wired to the `Update URI:` header), so every install checks this repo's latest Release (12-hour cache) and updates through the standard Plugins screen — no third-party updater needed. Each Release must have the versioned plugin zip attached as an asset (GitHub's auto source zipball is deliberately ignored — its folder name would break the plugin slug). The `Update URI:` header also blocks wordpress.org from ever offering an unrelated same-slug plugin as an "update."

**Two builds ship per release.** The GitHub build (`wp-fan-mail-<version>.zip`, the Release asset) keeps the self-updater. The wordpress.org submission build (`wp-fan-mail-<version>-wporg.zip`) is identical except the GitHub-update block and the `Update URI:` header are removed, because the directory takes over update delivery and disallows third-party update sources. Never submit the GitHub build to the directory, and never attach the wporg build to a GitHub Release.

## Release & governance checklist

1. Bump the version in three places: plugin header, `IH247_WPFM_VERSION`, `Stable tag` in readme.txt. Add changelog entries to both readmes.
2. `php -l` both PHP files; run the burn-in (submit with JS on and off, honeypot and time-trap rejections, both carriers + test send, Inbox actions, CSV export, consent round-trip, token on an Intermission page, retention sweep).
3. Build `wp-fan-mail-<version>.zip`, commit, tag `v<version>`, create the GitHub Release **with the zip attached as an asset** — that asset is what every site updates from. Build `wp-fan-mail-<version>-wporg.zip` alongside it (updater block and `Update URI:` stripped) for the directory.
4. Security watch: enable GitHub private vulnerability reporting on the repo (see SECURITY.md), and monitor the WordPress ecosystem feeds (Patchstack / WPScan weekly reports) for issues in comparable form plugins — same attack surface, early warning.
5. After each major WordPress/PHP release: test, then bump `Tested up to`.

## Privacy & compliance

A contact plugin inherently collects visitor-submitted personal data — so where WP Intermission's headline is "collects nothing," Fan Mail's is **minimal, transparent, user-owned collection**: submissions live in this site's database and go to this site's owner; nothing is transmitted to iHelp247, ever. Opt-in consent checkbox per form, opt-in IP storage (off by default; rate limiting uses only a transient hash either way), configurable retention with a daily sweep, and WordPress core privacy export/erase integration. The plugin's only outbound requests are the configured mail carrier (server-side, only when selected) and the 12-hour-cached GitHub update check. Uninstall keeps your data unless you explicitly opt into deletion.

## Caching note (nonces, Plesk, and mysterious failures)

Cached pages are the classic contact-form killer: a cache serves yesterday's page, its security nonce has expired, and every submission silently fails. Fan Mail deliberately uses **no nonces on the public form** — its anti-spam signature is an HMAC of the render time, valid for days — so cached pages keep working. (Hard-won fleet note: Plesk's per-domain nginx caching can be preset with cookie-bypass and stale-on-error rules; check `last_nginx.conf` and `X-Cache-Status`, not just the toggles.)

## Screenshots

GitHub: add screenshots to `.github/screenshots/` and reference them here. For the WordPress.org directory, screenshots follow the `screenshot-1.png, screenshot-2.png, …` convention in the plugin's `assets` SVN folder, matching the numbered list in `readme.txt`.

## Documentation & support

A plain-language **user guide with a self-diagnosis section** (including the full deliverability walk-through) ships in the plugin at `docs/index.html` (works offline) and is linked from the settings page. To host it publicly, enable GitHub Pages on this repo (Settings → Pages → deploy from `main`, `/docs` folder). Override the linked URL with the `ihelp247_wp_fan_mail_docs_url` filter.

## One repo, three audiences

- **README.md** (this file) — GitHub: developers, contributors, release/governance process.
- **readme.txt** — the WordPress parser: directory-format description, FAQ, screenshots list, changelog. Written to the [wordpress.org plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
- **docs/index.html** — end users: the plain-language guide + self-diagnosis, shipped in the plugin and hostable via GitHub Pages or any support site (settings-page link is filterable via `ihelp247_wp_fan_mail_docs_url`).

## Roadmap

- **Carriers**: SMTP (host/port/credentials), Microsoft 365 (Graph send), Google (Gmail API) — the carrier registry is already filterable, so these arrive as additional options, not rewrites.
- **Inbox growth**: notes on contacts, contact statuses, reply-from-Inbox — the CRM-familiar features the schema was shaped for.
- **Optional CAPTCHA integrations** (opt-in only; the default stays beacon-free).

## Changelog

### 0.4.0 — 2026-07-26
- **Conditional fields.** One rule per field: *Show when [field] is / is not [value]*. Rows reference each other by stable 8-char keys (reorder/relabel-proof, validated on save against self/dangling references). A tiny front-end engine toggles rows live (hidden rows disable their inputs, so they neither submit nor block validation); the server evaluates the same rule from submitted values — hidden fields are never required, but a value a no-JS visitor typed is still kept.
- **Survey types + notes.** `checkboxes` (pick all that apply), `likert` (options override the stock 5 agree–disagree labels), `nps` (0–10 button row with end labels). Any field can enable a **notes** box — an optional "Anything to add?" textarea whose answer is stored as *label — notes*.
- **Address field with privacy-safe ZIP lookup.** Street + city/state/ZIP sub-inputs (proper autocomplete attributes), ZIP format validation client + server, and ZIP → city/state autofill via `admin-ajax` → this server → zippopotam.us (30-day per-ZIP transient cache, 30/10-min per-IP rate limit, no key, no visitor-side third-party calls).
- **File upload field.** jpg/jpeg/png/gif/webp/heic/pdf through `wp_handle_upload` with `wp_check_filetype_and_ext` (content-sniffed, not extension-trusted), 10-char random filename prefix, dedicated `uploads/fan-mail/` subtree, max-size setting (Settings → Uploads, default 10 MB) enforced client + server. Files are linked from the Inbox detail and the email, and **deleted with their message** everywhere messages die: single/bulk delete, retention sweep, privacy eraser, and (opt-in) uninstall.

### 0.3.0 — 2026-07-26
- **Fields fully unified — nothing hard-baked.** The built-in Fields section is gone. Name, Email, Phone, Subject, and Message became *field types* in the one builder list, so every field on a form — classics included — is added, labeled, reordered, required, and colored the same way. The five smart types keep their wiring via a shared input map (renderer and submit handler use the same one, so they can't drift): first **Email** → contact record + Reply-To + validate-on-entry; first **Phone** → E.164 auto-heal; first **Subject** → notification title; first **Message** → body; duplicates and generic types ride in the extra data. 0.1–0.2.x forms **auto-migrate on read** (legacy toggles → field rows, colors preserved); the activation Contact form is just prepopulated rows (`ih247_wpfm_classic_fields()`); new forms start empty with the min-1 rule intact. New rows default to Short text — smart types are a deliberate pick.

### 0.2.2 — 2026-07-26
- **Live validation + E.164 auto-heal.** Email validates on entry (inline hint, not a submit surprise). Phone fields heal what visitors type into `+15551234567`-style E.164 using a configurable default country code (Settings → Phone numbers): handles `+`, `00`-prefix international, country-code-without-plus, national numbers with trunk zeros; implausible lengths (outside 8–15 digits) are flagged inline. Mirrored server-side in `ih247_wpfm_normalize_phone()` — heals when confident, keeps the raw input when not.
- **Every field is a choice.** Email and Message joined the toggle list (each with required + color, like the rest). The activation-time Contact form still prepopulates name/email/subject/message; *new* forms start with a blank field list and saving enforces **minimum one field** (clear error notice, nothing saved). Submission validation follows each field's own show/require settings, with a floor: an entirely empty submission is never accepted. Forms without an Email field store messages with no contact record (anonymous by the owner's design — name/phone ride along with the message).
- **Self-sizing preview.** The editor preview grows with the form's content — add ten builder fields and the whole form stays visible without scrolling the page — until it reaches the viewport height, then the preview scrolls internally. Still sticky.

### 0.2.1 — 2026-07-26
- **Editor flow pass.** Each field is one aligned line — show · required · color — so the required toggle sits right next to its field (Phone and Subject gained `require_phone` / `require_subject`, enforced server-side too; Email and Message read "always on, required"). Every field — built-ins *and* builder fields — takes an individual background color (checkbox + picker; unticked follows the theme/Look, so dark mode stays intact). The live preview is **sticky** and follows you down the page while editing, matching Intermission's editor feel.

### 0.2.0 — 2026-07-26
- **Extra-fields builder.** Per-form custom fields: short text, paragraph, dropdown, multiple choice, checkbox, number, date, website URL, star rating — the usable core of the common form patterns (quote, booking, survey, feedback) without file uploads or payments. Answers live in a new `extra` column (DB schema v2, auto-migrated), appear in the Inbox detail view, ride along in notification emails, and export in the CSV.
- **Look controls.** Card background: follow theme, custom color, or transparent (pure passthrough for pages that bring their own background); field background color; text color. All inline CSS variables, so custom colors override the theme without specificity fights.
- **Delivery diagnostics.** Test-send reports the carrier and From address actually used; `wp_mail` failures capture the real PHPMailer reason via `wp_mail_failed` (in the test result *and* the Inbox row); `wp_mail` success carries an honest caveat (server accepted ≠ delivered); and the settings page warns once when testing with unsaved edits on screen — the "picked SendGrid, hit test, green light, nothing arrived" trap.
- **Carrier-specific settings UI.** Gateway fields show only while their carrier is selected (`data-carrier` rows) — SendGrid today, SMTP/M365/Google tomorrow, same pattern.

### 0.1.0 — 2026-07-26
- **First test build — the road to 1.0.** Feature-complete v1 scaffold entering burn-in; version numbers below 1.0.0 are test/development builds, and 1.0.0 is the minimum public release. Named forms with human-readable slugs (shortcode + `{fan-mail-…}` token, WP Intermission integration), database-first storage with contacts + messages tables, admin Inbox (unread badge, statuses, search, filters, bulk actions, CSV export), `wp_mail` and SendGrid carriers with per-message delivery results and a test-send button, cache-safe anti-spam (honeypot, signed render timestamp, per-IP rate limiting — no public nonces), per-form consent checkbox, opt-in IP storage, configurable retention with daily sweep, core privacy export/erase integration, live-preview Forms editor (fields, accent, light/dark/auto theme, custom CSS), GitHub-powered updates through the standard Plugins screen, and a removable corner credit.

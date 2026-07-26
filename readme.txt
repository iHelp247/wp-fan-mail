=== iHelp247 WP Fan Mail ===
Contributors: ihelp247, ulxi
Donate link: https://square.link/u/NSPL6UTY
Tags: contact form, form, sendgrid, email, contact
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A contact form your visitors will actually use. Every message lands in your own database Inbox first, then goes out by email — wp_mail or SendGrid. Shortcode or {fan-mail} token, honeypot anti-spam, consent field, CSV export. No analytics, no upsells.

== Description ==

iHelp247 WP Fan Mail is published by iHelp247 — by ULXI (UnLimited eXchange, Inc.). iHelp247 publishes tools, paying it forward to support the open-source community. Support: https://ihelp247.com/

Fan mail for your WordPress website: name a form in plain words — "Contact", "Support", "Say hello" — and it answers to that name everywhere: [fan-mail form="say-hello"] in a page, {fan-mail-say-hello} in raw HTML (including a WP Intermission maintenance page). Easy by default, powerful when you need it, blocking nobody: a fresh install ships a working Contact form, the Forms editor has a live preview, and filters plus a per-form CSS box keep every door open for developers.

The database is the inbox; email is the courtesy copy. Every submission is written to your site's own database BEFORE any sending happens, so a broken mail setup can never lose a message. The Fan Mail → Inbox screen lists everything with read/unread/replied status, per-message delivery results, search, filters, bulk actions, and one-click CSV export (contacts included — CRM-ready).

Delivery, two ways in this release: wp_mail (zero setup — whatever your site already sends with) or SendGrid (an API key and a verified sender, the easy fix when shared-host mail lands in spam). SMTP, Microsoft 365, and Google connectors are on the roadmap; developers can add carriers today via the ihelp247_wp_fan_mail_carriers filter. Notifications set Reply-To to the visitor, so answering a message is just pressing Reply.

Anti-spam without third-party beacons: a honeypot field, a signed render-timestamp (too fast = bot), and per-IP rate limiting. No nonces on the public form by design — page caches can serve a form for days without "session expired" failures.

Privacy posture: minimal, transparent, user-owned collection. Messages live in this site's database and go to this site's owner — nothing phones home to iHelp247, ever. IP storage is opt-in (off by default), retention is configurable (auto-delete after N days), an optional consent checkbox ships with every form, and WordPress's built-in Export / Erase Personal Data tools cover Fan Mail automatically.

Forms carry a small "fan mail by iHelp247" link under the button; a checkbox on the settings page removes it. Free either way — the link just helps others find the plugin.

== Frequently Asked Questions ==

= My notification emails don't arrive — is the message lost? =
No. The database write happens before the send, so the message is in Fan Mail → Inbox regardless. For the email copy, use the Send-a-test button on the settings page and see the deliverability section of the user guide (short version: shared-host wp_mail often lands in spam; SendGrid with a verified sender fixes it).

= Does it work on a WP Intermission maintenance page? =
Yes — that's the point of the token. Drop {fan-mail-contact} into Intermission's custom HTML and visitors can write to you while the site is backstage. Submissions use admin-ajax, which maintenance mode never blocks.

= Can I make the form match my site? =
Pick an accent color and a light/dark/auto theme per form in the editor (with live preview). Power users get a per-form custom-CSS box, stable .ihfm- classes on every element, and the ihelp247_wp_fan_mail_form_html filter.

= Does this plugin collect any data for iHelp247? =
No. Submissions go into your database and your mailbox only. The plugin's only outbound calls are your chosen mail carrier and a 12-hour-cached update check.

= How do I remove the iHelp247 link under the form? =
Tick the checkbox at the bottom of the settings page. It's free and stays free — no upsell attached.

== Screenshots ==

1. The Inbox — messages with status, delivery result, filters, and CSV export.
2. Reading a message — contact history, reply by email, delivery detail.
3. The Forms editor with live preview on a light and a dark page.
4. Settings — delivery carrier, From address, test send, privacy and retention.
5. A form dropped into a WP Intermission maintenance page via {fan-mail-contact}.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, and activate.
2. A "Contact" form is ready immediately — put [fan-mail] in any page, or {fan-mail-contact} in raw HTML.
3. Send yourself a test through the form; it appears under Fan Mail → Inbox and in your admin email.
4. Optional: Fan Mail → Settings to switch delivery to SendGrid, set the From address, and press "Send a test email to me".

== Changelog ==

= 0.4.0 =
* Conditional fields: any field can get a rule — "Show when [another field] is / is not [value]" — evaluated live in the browser and mirrored server-side (hidden fields are never required; no-JS visitors are handled gracefully). Rules survive reordering and relabeling.
* Survey types: multi-select Checkboxes ("pick all that apply"), Likert scale (5-point, labels editable), and NPS 0–10, plus a per-field "notes" toggle that adds an optional elaboration box under any field.
* Address field: street/city/state/ZIP group with format validation and ZIP → city/state autofill proxied through YOUR server (cached, rate-limited, free zippopotam.us — the visitor's browser never contacts a third party).
* File upload field: images and PDF (jpg, jpeg, png, gif, webp, heic, pdf), verified by real content, randomized filenames in uploads/fan-mail/, size cap setting (default 10 MB), linked from the Inbox and notification email, and deleted together with their message — including retention sweeps, privacy erasures, and opt-in uninstall cleanup.

= 0.3.0 =
* Nothing is hard-baked anymore: the separate built-in Fields section is gone, and Name, Email, Phone, Subject, and Message are now field types in the same builder list as everything else — one unified list where every field is added, labeled, ordered, required, and colored by the user. The smart types keep their behavior: the first Email on a form enables replies and validates on entry, Phone auto-heals to +format, Subject titles the notification, Message is the body, Name/Email/Phone feed the contact record. Forms saved under earlier versions migrate automatically; the activation-time Contact form is simply prepopulated rows.

= 0.2.2 =
* Live field validation: email checks itself the moment the visitor leaves the field, and phone numbers auto-heal to international E.164 +format as they type — "(555) 123-4567" becomes +15551234567 using a configurable default country code (Settings → Phone numbers); implausible numbers are flagged inline. The same healing runs server-side.
* Every field is now the user's choice: Email and Message became toggles like everything else. The activation-time Contact form stays prepopulated with the classics; new forms start with a blank field list, and saving enforces a minimum of one field.
* Editor preview now sizes itself to the form — it grows as fields are added (no page scrolling to see the bottom) until it reaches the window height, then scrolls internally.

= 0.2.1 =
* Form editor flow: every field is now one aligned line — show, required, color — so "required" sits next to the field it belongs to (Phone and Subject gained their own required toggles; Email and Message show theirs as always-on). Each field, including builder fields, gets an individual color choice (unticked = follow the theme/Look). The live preview is now sticky and rides along as you scroll the editor, like WP Intermission's.

= 0.2.0 =
* Extra-fields builder: add dropdown, multiple choice, checkbox, number, date, website URL, star rating, paragraph, and short-text fields to any form — the building blocks of quote, booking, survey, and feedback forms. Answers are stored with the message (new database column), shown in the Inbox detail view, included in notification emails and the CSV export.
* Look controls: per-form card background (follow theme / custom color / transparent passthrough), field background color, and text color — all live-previewed.
* Delivery diagnostics: test-send now reports the carrier and From address it actually used, wp_mail failures carry the real PHPMailer reason (captured via wp_mail_failed) in both the test result and the Inbox row, wp_mail "success" gets an honest asterisk (server accepted ≠ delivered), and the settings page warns when you test with unsaved changes on screen.
* Cleaner settings UI: carrier-specific fields (like the SendGrid API key) only show while that carrier is selected — future gateways follow the same pattern.

= 0.1.0 =
* First test build — the road to 1.0 (burn-in on the iHelp247 fleet; 1.0.0 is the minimum public release). Named forms with human-readable slugs (shortcode + {fan-mail-…} token, WP Intermission integration), database-first storage with contacts + messages tables, admin Inbox with statuses, search, filters, bulk actions and CSV export, wp_mail and SendGrid carriers with test send, honeypot + signed-timestamp + rate-limit anti-spam (cache-safe, no nonces on the public form), per-form consent checkbox, opt-in IP storage, configurable retention with daily sweep, core privacy export/erase integration, live-preview Forms editor with accent/theme/custom CSS, GitHub-powered updates through the standard Plugins screen, and a removable corner credit.

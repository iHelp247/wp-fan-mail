<!-- iHelp247 WP Fan Mail - (c) ULXI - UnLimited eXchange, Inc. - published under the iHelp247 brand - https://ihelp247.com - GPL-2.0-or-later -->
# Security Policy

## Supported versions

Only the latest release receives security fixes. Update through the Plugins screen (GitHub-release updates are built in) or install the newest release zip.

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Use GitHub's private vulnerability reporting on this repository (Security → Report a vulnerability), or email the maintainer via the contact on [ihelp247.com](https://ihelp247.com), or via the parent company [ULXI](https://ulxi.com). You should receive an acknowledgment within 72 hours. Coordinated disclosure: we ask for a reasonable window to ship a fix before public details.

## Design posture (what attackers find here)

- This plugin accepts public input by nature (it's a contact form), so the submission path is the attack surface — and it is treated that way: every field passes WordPress sanitizers (`sanitize_email`, `sanitize_text_field`, `sanitize_textarea_field`, `esc_url_raw`) with hard length caps, all database access goes through `$wpdb->prepare()`, and stored content is escaped on output in the admin Inbox (stored-XSS safe).
- Anti-abuse without third-party beacons: honeypot field, HMAC-signed render timestamp (too fast = bot, too old = stale), and per-IP rate limiting via transients. No nonces on the public form by design — page caches serve forms for days, and stale nonces would cause silent failures; the signed-timestamp scheme is cache-safe and forgery-resistant (`hash_equals` comparison).
- Notification email From is always a site-owned address, never the visitor's (which would fail DMARC and enable spoofing); the visitor rides in Reply-To. Subject/header injection is blocked by `sanitize_text_field` (strips newlines) plus length caps.
- Redirect targets are constrained to this site (`wp_safe_redirect` + home-URL prefix check).
- Admin AJAX (preview, test mail) and all Inbox/Forms actions are nonce-protected and require `manage_options`. CSV export is nonce-protected and capability-gated.
- Privacy: nothing phones home to iHelp247. Outbound requests are the selected mail carrier (e.g. api.sendgrid.com, server-side, only when configured) and a 12-hour-cached GitHub API check for plugin updates (metadata only). IP storage is opt-in; WordPress core privacy export/erase tools are wired in.
- Fail-open rendering: a missing or deleted form renders as nothing, never an error, and a mail failure never loses a message (database write happens first).

## Maintenance cadence

Dependencies: none (no Composer/npm packages — nothing to supply-chain). The plugin is reviewed against each major WordPress and PHP release; "Tested up to" in readme.txt reflects the last verified version.

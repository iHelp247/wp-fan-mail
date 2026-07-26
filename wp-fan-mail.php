<?php
/**
 * Plugin Name:       iHelp247 WP Fan Mail
 * Plugin URI:        https://github.com/iHelp247/wp-fan-mail
 * Description:       A contact form your visitors will actually use — easy by default, powerful when you need it. Drop a form anywhere with a shortcode or a {fan-mail} token (WP Intermission friendly), every message lands safely in your own database Inbox first, then goes out by email — wp_mail or SendGrid. Honeypot + time-trap anti-spam, consent field, CSV export, no analytics, no upsells.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            iHelp247 — by ULXI
 * Author URI:        https://ihelp247.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-fan-mail
 * Update URI:        https://github.com/iHelp247/wp-fan-mail
 *
 * iHelp247 WP Fan Mail - (c) ULXI - UnLimited eXchange, Inc. - published
 * under the iHelp247 brand - https://ihelp247.com - GPL-2.0-or-later
 *
 * iHelp247 is the open-source publishing brand of ULXI — UnLimited eXchange,
 * Inc., the owner of the iHelp247 brand and this software.
 * The "Update URI" header prevents wordpress.org from ever pushing an
 * unrelated same-slug plugin as an "update" to this GitHub-distributed one.
 *
 * Naming note: "WPFM" in internal prefixes = WP Fan Mail.
 *
 * Privacy posture: a contact plugin inherently collects visitor-submitted
 * personal data — so the posture is "minimal, transparent, user-owned":
 * messages are stored in THIS site's database and mailed to THIS site's
 * owner. Nothing phones home to us, ever. IP storage is opt-in, retention
 * is configurable, and GDPR export/erase hooks are built in.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Configuration — every tunable in one place. Change here, nowhere else.
 * ---------------------------------------------------------------------- */

define( 'IH247_WPFM_VERSION', '0.4.0' );
define( 'IH247_WPFM_DB_VERSION', '2' ); // bump when the table schema changes (2: messages.extra for builder fields)

/** Option names. */
define( 'IH247_WPFM_OPT_FORMS', 'ihelp247_wp_fan_mail_forms' );             // array: all forms, keyed by slug
define( 'IH247_WPFM_OPT_CARRIER', 'ihelp247_wp_fan_mail_carrier' );         // 'wp_mail' | 'sendgrid' (more via filter)
define( 'IH247_WPFM_OPT_SENDGRID_KEY', 'ihelp247_wp_fan_mail_sendgrid_key' ); // string: SendGrid API key (or define IH247_WPFM_SENDGRID_KEY in wp-config.php)
define( 'IH247_WPFM_OPT_FROM_NAME', 'ihelp247_wp_fan_mail_from_name' );     // string: From name on notifications
define( 'IH247_WPFM_OPT_FROM_EMAIL', 'ihelp247_wp_fan_mail_from_email' );   // string: From address (SendGrid: must be a verified sender)
define( 'IH247_WPFM_OPT_STORE_IP', 'ihelp247_wp_fan_mail_store_ip' );       // bool: store submitter IP with each message (off by default — GDPR-friendly)
define( 'IH247_WPFM_OPT_DEFAULT_CC', 'ihelp247_wp_fan_mail_default_cc' );   // string: default country calling code for phone auto-healing to E.164 (digits, e.g. '1', '44')
define( 'IH247_WPFM_OPT_MAX_UPLOAD', 'ihelp247_wp_fan_mail_max_upload' );   // int: max upload size in MB for File fields (server + PHP limits still apply)
define( 'IH247_WPFM_OPT_RETENTION', 'ihelp247_wp_fan_mail_retention' );     // int: auto-delete messages older than N days (0 = keep forever)
define( 'IH247_WPFM_OPT_HIDE_CREDIT', 'ihelp247_wp_fan_mail_hide_credit' ); // bool: hide the small "fan mail by iHelp247" link under forms
define( 'IH247_WPFM_OPT_DELETE_DATA', 'ihelp247_wp_fan_mail_delete_data' ); // bool: drop tables + messages on uninstall (off = your data outlives the plugin)
define( 'IH247_WPFM_OPT_DB_VERSION', 'ihelp247_wp_fan_mail_db_version' );   // string: installed schema version

/** Who can read the Inbox and manage forms/settings. */
define( 'IH247_WPFM_CAPABILITY', 'manage_options' );

/** Anti-spam: signed render timestamp (cache-safe — no nonces, page caches
 *  can serve a form for days without "session expired" failures). */
define( 'IH247_WPFM_MIN_SECONDS', 3 );                  // humans need at least this long to write
define( 'IH247_WPFM_MAX_AGE', 7 * DAY_IN_SECONDS );     // cached pages stay valid this long
define( 'IH247_WPFM_RATE_MAX', 5 );                     // max submissions per IP…
define( 'IH247_WPFM_RATE_WINDOW', 10 * MINUTE_IN_SECONDS ); // …per this window

/** Hosted user guide & self-help (also ships in the plugin's docs/ folder). */
define( 'IH247_WPFM_DOCS_URL', 'https://ihelp247.com/wp-fan-mail/' );

/** Support links (Support & branding row on the settings page). One Square
 *  checkout covers everything — any amount, with a one-time / monthly toggle
 *  at checkout. All links open in a new tab. */
define( 'IH247_WPFM_DONATE_URL', 'https://square.link/u/NSPL6UTY' );              // Square checkout — any amount, one-time or monthly
define( 'IH247_WPFM_SUPPORT_URL', 'https://ihelp247.com/support' );               // help & contact
define( 'IH247_WPFM_PROJECTS_URL', 'https://ihelp247.com' );                      // more iHelp247 plugins & projects
define( 'IH247_WPFM_REVIEW_URL', 'https://github.com/iHelp247/wp-fan-mail' );     // where to leave a review / star

/* -------------------------------------------------------------------------
 * Database — messages live in the site's own database FIRST, then get
 * mailed. Email can bounce, land in spam, or vanish; the Inbox record
 * cannot. Two tables, CRM-shaped for growth: people and their messages.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_table_contacts() {
	global $wpdb;
	return $wpdb->prefix . 'ih247_fanmail_contacts';
}

function ih247_wpfm_table_messages() {
	global $wpdb;
	return $wpdb->prefix . 'ih247_fanmail_messages';
}

function ih247_wpfm_create_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset  = $wpdb->get_charset_collate();
	$contacts = ih247_wpfm_table_contacts();
	$messages = ih247_wpfm_table_messages();

	dbDelta(
		"CREATE TABLE $contacts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  phone varchar(64) NOT NULL DEFAULT '',
  consent tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT '',
  note text NULL,
  message_count int(11) NOT NULL DEFAULT 0,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email)
) $charset;"
	);

	dbDelta(
		"CREATE TABLE $messages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
  form_slug varchar(190) NOT NULL DEFAULT '',
  subject varchar(200) NOT NULL DEFAULT '',
  message longtext NULL,
  extra longtext NULL,
  page_url varchar(255) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'new',
  carrier varchar(20) NOT NULL DEFAULT '',
  mail_status varchar(20) NOT NULL DEFAULT '',
  mail_error text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY contact_id (contact_id),
  KEY form_slug (form_slug),
  KEY status (status),
  KEY created_at (created_at)
) $charset;"
	);

	update_option( IH247_WPFM_OPT_DB_VERSION, IH247_WPFM_DB_VERSION );
}

function ih247_wpfm_activate() {
	ih247_wpfm_create_tables();

	// A working form out of the box — the "two minutes to a great result"
	// promise. This is the ONLY prepopulated form; everything about it is
	// still ordinary editable field rows.
	$forms = get_option( IH247_WPFM_OPT_FORMS, array() );
	if ( ! is_array( $forms ) || empty( $forms ) ) {
		$default           = ih247_wpfm_form_defaults();
		$default['name']   = __( 'Contact', 'wp-fan-mail' );
		$default['fields'] = ih247_wpfm_classic_fields();
		update_option( IH247_WPFM_OPT_FORMS, array( 'contact' => $default ) );
	}

	if ( ! wp_next_scheduled( 'ih247_wpfm_daily_maintenance' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ih247_wpfm_daily_maintenance' );
	}
}
register_activation_hook( __FILE__, 'ih247_wpfm_activate' );

function ih247_wpfm_deactivate() {
	wp_clear_scheduled_hook( 'ih247_wpfm_daily_maintenance' );
}
register_deactivation_hook( __FILE__, 'ih247_wpfm_deactivate' );

/** Schema catch-up after a plugin update (activation hooks don't re-fire). */
function ih247_wpfm_maybe_upgrade() {
	if ( get_option( IH247_WPFM_OPT_DB_VERSION ) !== IH247_WPFM_DB_VERSION ) {
		ih247_wpfm_create_tables();
	}
}
add_action( 'admin_init', 'ih247_wpfm_maybe_upgrade', 5 );

/* -------------------------------------------------------------------------
 * Forms model — every form has a human-readable name ("Contact us") and a
 * slug derived from it ("contact-us"). The slug is what appears in the
 * shortcode [fan-mail form="contact-us"] and the token {fan-mail-contact-us},
 * so both read like the thing they are — never fan-mail-001.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_form_defaults() {
	return array(
		'name'         => __( 'Contact', 'wp-fan-mail' ),
		'intro'        => '',
		// NOTHING is hard-baked: every field on a form — name, email, phone,
		// subject, message included — is a row in the 'fields' list below,
		// chosen, ordered, labeled, and colored by the user. The activation
		// Contact form prepopulates the classic rows; new forms start empty
		// and saving enforces at least one field.
		'consent_on'   => 0,
		'consent_text' => __( 'I agree that this site may keep my message and email address so it can reply to me.', 'wp-fan-mail' ),
		'button'       => __( 'Send message', 'wp-fan-mail' ),
		'success'      => __( 'Thanks — your message is on its way. We usually reply within a day or two.', 'wp-fan-mail' ),
		'recipients'   => '', // empty = the site admin email
		'accent'       => '#2271b1',
		'theme'        => 'auto',    // 'auto' | 'light' | 'dark'
		'bg_mode'      => 'theme',   // card background: 'theme' | 'custom' | 'clear' (transparent — the page behind shows through)
		'bg_color'     => '#ffffff',
		'field_mode'   => 'theme',   // input background: 'theme' | 'custom'
		'field_color'  => '#ffffff',
		'ink_mode'     => 'auto',    // text color: 'auto' (follow theme) | 'custom'
		'ink_color'    => '#1d2327',
		'fields'       => array(),   // ALL fields: label, type, options, required, color
		'css'          => '',        // lift-the-hood: extra CSS scoped to this form's wrapper
	);
}

/** The classic contact rows — used to prepopulate the activation-time
 *  Contact form (and only that; new forms start empty). */
function ih247_wpfm_classic_fields() {
	$mk = static function ( $label, $type, $required ) {
		return array( 'label' => $label, 'type' => $type, 'options' => '', 'required' => $required ? 1 : 0, 'color' => '' );
	};
	return array(
		$mk( __( 'Your name', 'wp-fan-mail' ), 'name', true ),
		$mk( __( 'Your email', 'wp-fan-mail' ), 'email', true ),
		$mk( __( 'Subject', 'wp-fan-mail' ), 'subject', false ),
		$mk( __( 'Message', 'wp-fan-mail' ), 'message', true ),
	);
}

/** Field types — every field is one of these, the classic contact fields
 *  included. The five "smart" types carry behavior: the first name/email/
 *  phone on a form feed the contact record, email wires up Reply-To and
 *  validates on entry, phone auto-heals to E.164, subject titles the
 *  notification, message is the body. The rest are the usable core of the
 *  common form patterns (quote request, booking, survey, feedback).
 *  File uploads and payments are deliberately out of 0.x scope. */
function ih247_wpfm_field_types() {
	return array(
		'name'       => __( 'Name — feeds the contact record', 'wp-fan-mail' ),
		'email'      => __( 'Email — enables replies, checks itself on entry', 'wp-fan-mail' ),
		'phone'      => __( 'Phone — auto-heals to international +format', 'wp-fan-mail' ),
		'subject'    => __( 'Subject line — titles the notification email', 'wp-fan-mail' ),
		'message'    => __( 'Message — the big text box', 'wp-fan-mail' ),
		'text'       => __( 'Short text', 'wp-fan-mail' ),
		'textarea'   => __( 'Paragraph', 'wp-fan-mail' ),
		'select'     => __( 'Dropdown', 'wp-fan-mail' ),
		'radio'      => __( 'Multiple choice — pick one', 'wp-fan-mail' ),
		'checkboxes' => __( 'Checkboxes — pick all that apply', 'wp-fan-mail' ),
		'checkbox'   => __( 'Checkbox — yes/no', 'wp-fan-mail' ),
		'likert'     => __( 'Likert scale — agree…disagree', 'wp-fan-mail' ),
		'nps'        => __( 'NPS — how likely, 0–10', 'wp-fan-mail' ),
		'number'     => __( 'Number', 'wp-fan-mail' ),
		'date'       => __( 'Date', 'wp-fan-mail' ),
		'url'        => __( 'Website URL', 'wp-fan-mail' ),
		'rating'     => __( 'Star rating (1–5)', 'wp-fan-mail' ),
		'address'    => __( 'Address — street/city/state/ZIP, ZIP autofills', 'wp-fan-mail' ),
		'file'       => __( 'File upload — images or PDF', 'wp-fan-mail' ),
	);
}

/** Default Likert scale labels (used when a likert field has no options). */
function ih247_wpfm_likert_defaults() {
	return array(
		__( 'Strongly disagree', 'wp-fan-mail' ),
		__( 'Disagree', 'wp-fan-mail' ),
		__( 'Neutral', 'wp-fan-mail' ),
		__( 'Agree', 'wp-fan-mail' ),
		__( 'Strongly agree', 'wp-fan-mail' ),
	);
}

/** Allowed upload types for File fields — the safe common set. */
function ih247_wpfm_upload_mimes() {
	return apply_filters(
		'ihelp247_wp_fan_mail_upload_mimes',
		array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'heic' => 'image/heic',
			'pdf'  => 'application/pdf',
		)
	);
}

function ih247_wpfm_max_upload_mb() {
	$mb = absint( get_option( IH247_WPFM_OPT_MAX_UPLOAD, 10 ) );
	return max( 1, min( 100, $mb ? $mb : 10 ) );
}

/** Input name per field row: the FIRST instance of each smart type gets its
 *  canonical name (ihfm_email, ihfm_phone, …) — that's what wires it to the
 *  contact record, Reply-To, live validation, and phone healing. Duplicates
 *  and generic types get positional names and ride in the extra data. Used
 *  identically by the renderer and the submit handler, so they can't drift. */
function ih247_wpfm_field_input_map( $form ) {
	$map  = array();
	$used = array();
	foreach ( (array) $form['fields'] as $i => $cf ) {
		$t = isset( $cf['type'] ) ? $cf['type'] : 'text';
		if ( in_array( $t, array( 'name', 'email', 'phone', 'subject', 'message' ), true ) && empty( $used[ $t ] ) ) {
			$used[ $t ] = true;
			$map[ $i ]  = 'ihfm_' . $t;
		} else {
			$map[ $i ] = 'ihfm_cf_' . $i;
		}
	}
	return $map;
}

/** 0.2.x → 0.3 migration: forms saved with the old hard-baked toggles
 *  (show_name/show_email/…) get those converted into ordinary field rows,
 *  ahead of any builder rows they already had. Runs on read; the next save
 *  persists the new shape. */
function ih247_wpfm_migrate_form_v3( $form ) {
	$legacy = array( 'show_name', 'show_email', 'show_phone', 'show_subject', 'show_message' );
	$found  = false;
	foreach ( $legacy as $lk ) {
		if ( array_key_exists( $lk, $form ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		return $form; // already 0.3-shaped (or brand new)
	}
	$mk   = static function ( $label, $type, $required, $color ) {
		return array( 'label' => $label, 'type' => $type, 'options' => '', 'required' => $required ? 1 : 0, 'color' => (string) $color );
	};
	$core = array();
	if ( ! empty( $form['show_name'] ) ) {
		$core[] = $mk( __( 'Your name', 'wp-fan-mail' ), 'name', ! empty( $form['require_name'] ), isset( $form['name_color'] ) ? $form['name_color'] : '' );
	}
	if ( ! isset( $form['show_email'] ) || ! empty( $form['show_email'] ) ) { // 0.1–0.2.1 had no toggle: email was always on
		$core[] = $mk( __( 'Your email', 'wp-fan-mail' ), 'email', ! isset( $form['require_email'] ) || ! empty( $form['require_email'] ), isset( $form['email_color'] ) ? $form['email_color'] : '' );
	}
	if ( ! empty( $form['show_phone'] ) ) {
		$core[] = $mk( __( 'Phone', 'wp-fan-mail' ), 'phone', ! empty( $form['require_phone'] ), isset( $form['phone_color'] ) ? $form['phone_color'] : '' );
	}
	if ( ! empty( $form['show_subject'] ) ) {
		$core[] = $mk( __( 'Subject', 'wp-fan-mail' ), 'subject', ! empty( $form['require_subject'] ), isset( $form['subject_color'] ) ? $form['subject_color'] : '' );
	}
	if ( ! isset( $form['show_message'] ) || ! empty( $form['show_message'] ) ) { // ditto: message was always on
		$core[] = $mk( __( 'Message', 'wp-fan-mail' ), 'message', ! isset( $form['require_message'] ) || ! empty( $form['require_message'] ), isset( $form['message_color'] ) ? $form['message_color'] : '' );
	}
	$form['fields'] = array_merge( $core, isset( $form['fields'] ) && is_array( $form['fields'] ) ? array_values( $form['fields'] ) : array() );
	foreach ( array( 'show_name', 'require_name', 'show_email', 'require_email', 'show_phone', 'require_phone', 'show_subject', 'require_subject', 'show_message', 'require_message', 'name_color', 'email_color', 'phone_color', 'subject_color', 'message_color' ) as $lk ) {
		unset( $form[ $lk ] );
	}
	return $form;
}

function ih247_wpfm_get_forms() {
	$forms = get_option( IH247_WPFM_OPT_FORMS, array() );
	if ( ! is_array( $forms ) ) {
		$forms = array();
	}
	$out = array();
	foreach ( $forms as $slug => $form ) {
		$form           = ih247_wpfm_migrate_form_v3( is_array( $form ) ? $form : array() );
		$form           = wp_parse_args( $form, ih247_wpfm_form_defaults() );
		$form['fields'] = array_map( 'ih247_wpfm_normalize_row', is_array( $form['fields'] ) ? array_values( $form['fields'] ) : array() );
		$out[ $slug ]   = $form;
	}
	return $out;
}

/** Every field row gets the full v0.4 shape, whatever version saved it. */
function ih247_wpfm_normalize_row( $cf ) {
	return wp_parse_args(
		is_array( $cf ) ? $cf : array(),
		array(
			'label'    => '',
			'type'     => 'text',
			'options'  => '',
			'required' => 0,
			'color'    => '',
			'key'      => '',
			'notes'    => 0,
			'cond_key' => '',
			'cond_op'  => 'is',
			'cond_val' => '',
		)
	);
}

function ih247_wpfm_get_form( $slug ) {
	$forms = ih247_wpfm_get_forms();
	if ( '' === $slug || null === $slug ) {
		// Token {fan-mail} / shortcode [fan-mail] with no slug = the first form.
		$slugs = array_keys( $forms );
		$slug  = $slugs ? $slugs[0] : '';
	}
	return isset( $forms[ $slug ] ) ? array( 'slug' => $slug ) + $forms[ $slug ] : null;
}

/** "Contact us" → "contact-us", made unique among existing forms. */
function ih247_wpfm_unique_slug( $name, $existing ) {
	$base = sanitize_title( $name );
	if ( '' === $base ) {
		$base = 'form';
	}
	$slug = $base;
	$i    = 2;
	while ( isset( $existing[ $slug ] ) ) {
		$slug = $base . '-' . $i;
		$i++;
	}
	return $slug;
}

function ih247_wpfm_sanitize_form( $in ) {
	$out = ih247_wpfm_form_defaults();
	$in  = is_array( $in ) ? $in : array();

	foreach ( array( 'name', 'button', 'consent_text' ) as $k ) {
		if ( isset( $in[ $k ] ) && '' !== trim( (string) $in[ $k ] ) ) {
			$out[ $k ] = sanitize_text_field( (string) $in[ $k ] );
		}
	}
	foreach ( array( 'intro', 'success' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$out[ $k ] = wp_kses_post( (string) $in[ $k ] );
		}
	}
	$out['consent_on'] = empty( $in['consent_on'] ) ? 0 : 1;
	if ( isset( $in['recipients'] ) ) {
		$emails = array();
		foreach ( explode( ',', (string) $in['recipients'] ) as $addr ) {
			$addr = sanitize_email( trim( $addr ) );
			if ( $addr && is_email( $addr ) ) {
				$emails[] = $addr;
			}
		}
		$out['recipients'] = implode( ', ', $emails );
	}
	if ( isset( $in['accent'] ) ) {
		$hex = sanitize_hex_color( (string) $in['accent'] );
		if ( $hex ) {
			$out['accent'] = $hex;
		}
	}
	if ( isset( $in['theme'] ) && in_array( $in['theme'], array( 'auto', 'light', 'dark' ), true ) ) {
		$out['theme'] = $in['theme'];
	}
	if ( isset( $in['bg_mode'] ) && in_array( $in['bg_mode'], array( 'theme', 'custom', 'clear' ), true ) ) {
		$out['bg_mode'] = $in['bg_mode'];
	}
	if ( isset( $in['field_mode'] ) && in_array( $in['field_mode'], array( 'theme', 'custom' ), true ) ) {
		$out['field_mode'] = $in['field_mode'];
	}
	if ( isset( $in['ink_mode'] ) && in_array( $in['ink_mode'], array( 'auto', 'custom' ), true ) ) {
		$out['ink_mode'] = $in['ink_mode'];
	}
	foreach ( array( 'bg_color', 'field_color', 'ink_color' ) as $k ) {
		if ( isset( $in[ $k ] ) ) {
			$hex = sanitize_hex_color( (string) $in[ $k ] );
			if ( $hex ) {
				$out[ $k ] = $hex;
			}
		}
	}
	// Fields (capped at 20 — a contact form, not a census).
	$out['fields'] = array();
	if ( isset( $in['fields'] ) && is_array( $in['fields'] ) ) {
		$types = ih247_wpfm_field_types();
		foreach ( $in['fields'] as $cf ) {
			if ( ! is_array( $cf ) ) {
				continue;
			}
			$label = isset( $cf['label'] ) ? mb_substr( sanitize_text_field( (string) $cf['label'] ), 0, 100 ) : '';
			if ( '' === $label ) {
				continue; // unnamed rows are treated as deleted
			}
			$opts = array();
			if ( isset( $cf['options'] ) ) {
				foreach ( explode( "\n", (string) $cf['options'] ) as $line ) {
					$line = sanitize_text_field( $line );
					if ( '' !== $line ) {
						$opts[] = mb_substr( $line, 0, 100 );
					}
				}
			}
			$cf_color = '';
			if ( ! empty( $cf['color_use'] ) && isset( $cf['color'] ) ) {
				$hex      = sanitize_hex_color( (string) $cf['color'] );
				$cf_color = $hex ? $hex : '';
			}
			// Stable per-row key — what conditional rules point at, so
			// reordering or relabeling never breaks a rule.
			$key = isset( $cf['key'] ) ? substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $cf['key'] ) ), 0, 8 ) : '';
			if ( '' === $key ) {
				$key = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
			}
			$out['fields'][] = array(
				'label'    => $label,
				'type'     => isset( $cf['type'], $types[ $cf['type'] ] ) ? $cf['type'] : 'text',
				'options'  => implode( "\n", array_slice( $opts, 0, 40 ) ),
				'required' => empty( $cf['required'] ) ? 0 : 1,
				'color'    => $cf_color,
				'key'      => $key,
				'notes'    => empty( $cf['notes'] ) ? 0 : 1,
				'cond_key' => isset( $cf['cond_key'] ) ? substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $cf['cond_key'] ) ), 0, 8 ) : '',
				'cond_op'  => isset( $cf['cond_op'] ) && 'not' === $cf['cond_op'] ? 'not' : 'is',
				'cond_val' => isset( $cf['cond_val'] ) ? mb_substr( sanitize_text_field( (string) $cf['cond_val'] ), 0, 100 ) : '',
			);
			if ( count( $out['fields'] ) >= 20 ) {
				break;
			}
		}
		// Rules must point at a real, different row — anything else clears.
		$keys = array();
		foreach ( $out['fields'] as $cf ) {
			$keys[ $cf['key'] ] = true;
		}
		foreach ( $out['fields'] as $ci => $cf ) {
			if ( '' !== $cf['cond_key'] && ( ! isset( $keys[ $cf['cond_key'] ] ) || $cf['cond_key'] === $cf['key'] ) ) {
				$out['fields'][ $ci ]['cond_key'] = '';
			}
		}
	}
	if ( isset( $in['css'] ) ) {
		// Advanced box: raw CSS, printed inside a <style> tag. Strip anything
		// that could close the tag; admins-only input either way.
		$out['css'] = str_ireplace( '</style', '', (string) $in['css'] );
	}
	return $out;
}

/** Does this form have at least one field? (Saving enforces it; rendering
 *  fails open to nothing if a zero-field form somehow exists.) */
function ih247_wpfm_form_has_fields( $form ) {
	return ! empty( $form['fields'] );
}

/** Default country calling code for phone auto-healing (digits, no +). */
function ih247_wpfm_default_cc() {
	$cc = preg_replace( '/\D/', '', (string) get_option( IH247_WPFM_OPT_DEFAULT_CC, '1' ) );
	return '' === $cc ? '1' : substr( $cc, 0, 3 );
}

/** Best-effort phone auto-heal to E.164 (mirrors the client-side healer).
 *  Heals when confident; keeps the raw input when not — a human can read
 *  "ext. 204" in the Inbox, a discarded value helps nobody. */
function ih247_wpfm_normalize_phone( $raw, $cc ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	$d = preg_replace( '/\D/', '', $raw );
	if ( 0 === strpos( $raw, '+' ) ) {
		$out = '+' . $d;                                   // already international — just tidy
	} elseif ( 0 === strpos( $d, '00' ) ) {
		$out = '+' . substr( $d, 2 );                      // 00-prefix international dialing
	} elseif ( $cc && 0 === strpos( $d, $cc ) && strlen( $d ) >= 11 ) {
		$out = '+' . $d;                                   // country code typed without the +
	} elseif ( $cc ) {
		$out = '+' . $cc . ltrim( $d, '0' );               // national number (trunk 0 dropped)
	} else {
		$out = '+' . $d;
	}
	$len = strlen( preg_replace( '/\D/', '', $out ) );
	return ( $len >= 8 && $len <= 15 ) ? $out : $raw;      // E.164 plausibility window
}

/** Recipient list for a form (falls back to the site admin email). */
function ih247_wpfm_form_recipients( $form ) {
	$list = array();
	foreach ( explode( ',', (string) $form['recipients'] ) as $addr ) {
		$addr = sanitize_email( trim( $addr ) );
		if ( $addr && is_email( $addr ) ) {
			$list[] = $addr;
		}
	}
	if ( empty( $list ) ) {
		$list[] = get_option( 'admin_email' );
	}
	return apply_filters( 'ihelp247_wp_fan_mail_recipients', $list, $form );
}

/* -------------------------------------------------------------------------
 * Front-end rendering — one self-contained block per form: scoped styles,
 * accessible markup, vanilla-JS submit with a full no-JavaScript fallback.
 * Works dropped into a theme page OR into WP Intermission's maintenance
 * page (the token render happens per request, server-side).
 * ---------------------------------------------------------------------- */

/** Cache-safe anti-spam signature: HMAC of slug + render time. No nonces —
 *  page caches can serve a form for days without "session expired" failures. */
function ih247_wpfm_sign( $slug, $ts ) {
	return hash_hmac( 'sha256', 'ihfm|' . $slug . '|' . $ts, wp_salt( 'auth' ) );
}

function ih247_wpfm_current_url() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$url = home_url( $req );
	return esc_url_raw( remove_query_arg( array( 'fan-mail', 'fan-mail-error', 'fan-mail-form' ), $url ) );
}

/** Human-friendly text for the no-JS error redirect codes. */
function ih247_wpfm_error_text( $code ) {
	$map = array(
		'email'   => __( 'That email address doesn\'t look right — please check it and try again.', 'wp-fan-mail' ),
		'missing' => __( 'Please fill in the required fields and try again.', 'wp-fan-mail' ),
		'consent' => __( 'Please tick the consent box so we\'re allowed to reply to you.', 'wp-fan-mail' ),
		'fresh'   => __( 'This page had been open a very long time — please reload it and send again.', 'wp-fan-mail' ),
		'fast'    => __( 'That went through a little too fast — please try sending again.', 'wp-fan-mail' ),
		'rate'    => __( 'Quite a few messages from here just now — please wait a few minutes and try again.', 'wp-fan-mail' ),
		'zip'     => __( 'That ZIP code doesn\'t look right — 12345 or 12345-6789.', 'wp-fan-mail' ),
		'file'    => __( 'That file couldn\'t be accepted — images or PDF only, within the size limit.', 'wp-fan-mail' ),
	);
	return isset( $map[ $code ] ) ? $map[ $code ] : __( 'Something went wrong — please try again.', 'wp-fan-mail' );
}

/** Scoped stylesheet + submit script, printed once per request. */
function ih247_wpfm_assets_html() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;

	$css = '.ihfm-wrap{--ihfm-bg:#ffffff;--ihfm-fg:#1d2327;--ihfm-muted:#5f6b76;--ihfm-border:#c8d0d8;--ihfm-field:#ffffff;'
		. 'box-sizing:border-box;max-width:560px;margin:1.5em auto;padding:26px 26px 18px;border:1px solid var(--ihfm-border);'
		. 'border-radius:14px;background:var(--ihfm-bg);color:var(--ihfm-fg);font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;font-size:16px;line-height:1.5;text-align:left;}'
		. '.ihfm-wrap *{box-sizing:border-box;}'
		. '.ihfm-theme-dark{--ihfm-bg:rgba(6,14,26,0.72);--ihfm-fg:#f0f4f8;--ihfm-muted:#9fb0c0;--ihfm-border:rgba(255,255,255,0.22);--ihfm-field:rgba(255,255,255,0.06);}'
		. '@media (prefers-color-scheme:dark){.ihfm-theme-auto{--ihfm-bg:rgba(6,14,26,0.72);--ihfm-fg:#f0f4f8;--ihfm-muted:#9fb0c0;--ihfm-border:rgba(255,255,255,0.22);--ihfm-field:rgba(255,255,255,0.06);}}'
		. '.ihfm-intro{margin:0 0 16px;color:var(--ihfm-muted);}'
		. '.ihfm-row{margin:0 0 14px;}'
		. '.ihfm-row label{display:block;margin:0 0 5px;font-size:14px;font-weight:600;}'
		. '.ihfm-row .ihfm-opt{font-weight:400;color:var(--ihfm-muted);font-size:13px;}'
		. '.ihfm-row input[type=text],.ihfm-row input[type=email],.ihfm-row input[type=tel],.ihfm-row input[type=number],.ihfm-row input[type=date],.ihfm-row input[type=url],.ihfm-row textarea{'
		. 'width:100%;padding:10px 12px;border:1px solid var(--ihfm-border);border-radius:9px;background:var(--ihfm-field);color:var(--ihfm-fg);font:inherit;}'
		. '.ihfm-row input:focus,.ihfm-row textarea:focus{outline:2px solid var(--ihfm-accent,#2271b1);outline-offset:1px;border-color:transparent;}'
		. '.ihfm-row textarea{min-height:130px;resize:vertical;}'
		. '.ihfm-row select{width:100%;padding:10px 12px;border:1px solid var(--ihfm-border);border-radius:9px;background:var(--ihfm-field);color:var(--ihfm-fg);font:inherit;}'
		. '.ihfm-choice{display:block;font-weight:400;font-size:15px;margin:3px 0;cursor:pointer;}'
		. '.ihfm-choice input{margin-right:8px;}'
		. '.ihfm-stars{display:inline-flex;flex-direction:row-reverse;gap:2px;}'
		. '.ihfm-stars input{position:absolute;opacity:0;pointer-events:none;}'
		. '.ihfm-stars label{font-size:26px;line-height:1;color:var(--ihfm-border);cursor:pointer;padding:0 3px;}'
		. '.ihfm-stars input:checked ~ label,.ihfm-stars label:hover,.ihfm-stars label:hover ~ label{color:var(--ihfm-accent,#2271b1);}'
		. '.ihfm-bg-clear{background:transparent;border-color:transparent;}'
		. '.ihfm-consent{display:flex;gap:9px;align-items:flex-start;font-size:14px;color:var(--ihfm-muted);}'
		. '.ihfm-consent input{margin-top:3px;}'
		. '.ihfm-submit{display:inline-block;padding:11px 22px;border:0;border-radius:9px;background:var(--ihfm-accent,#2271b1);color:#fff;'
		. 'font:inherit;font-weight:600;cursor:pointer;transition:filter .15s;}'
		. '.ihfm-submit:hover{filter:brightness(1.1);}'
		. '.ihfm-submit[disabled]{opacity:.6;cursor:default;}'
		. '.ihfm-notice{margin:0 0 14px;padding:10px 14px;border-radius:9px;background:rgba(214,54,56,0.12);border:1px solid rgba(214,54,56,0.5);font-size:14px;}'
		. '.ihfm-success{margin:0;padding:14px 16px;border-radius:9px;background:rgba(0,163,42,0.12);border:1px solid rgba(0,163,42,0.5);}'
		. '.ihfm-hp{position:absolute !important;left:-9999px !important;top:-9999px !important;height:1px;width:1px;overflow:hidden;}'
		. '.ihfm-credit{margin:14px 0 0;text-align:right;}'
		. '.ihfm-credit a{font-size:11px;letter-spacing:.4px;color:inherit;opacity:.4;text-decoration:none;}'
		. '.ihfm-bad{border-color:#d63638 !important;}'
		. '.ihfm-hint{display:none;font-size:12.5px;color:#d63638;margin-top:4px;}'
		. '.ihfm-inline{display:flex;flex-wrap:wrap;gap:6px;}'
		. '.ihfm-inline .ihfm-choice{margin:0 10px 0 0;}'
		. '.ihfm-nps{display:flex;flex-wrap:wrap;gap:5px;}'
		. '.ihfm-nps input{position:absolute;opacity:0;pointer-events:none;}'
		. '.ihfm-nps label{min-width:34px;text-align:center;padding:7px 0;border:1px solid var(--ihfm-border);border-radius:8px;cursor:pointer;font-size:14px;background:var(--ihfm-field);}'
		. '.ihfm-nps input:checked+label{background:var(--ihfm-accent,#2271b1);border-color:var(--ihfm-accent,#2271b1);color:#fff;}'
		. '.ihfm-nps-ends{display:flex;justify-content:space-between;font-size:12px;color:var(--ihfm-muted);margin-top:3px;}'
		. '.ihfm-addr-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}'
		. '.ihfm-addr-row input{flex:1 1 90px;}'
		. '.ihfm-addr-row .ihfm-city{flex:2 1 140px;}'
		. '.ihfm-notes{width:100%;min-height:52px;margin-top:7px;padding:8px 12px;border:1px solid var(--ihfm-border);border-radius:9px;background:var(--ihfm-field);color:var(--ihfm-fg);font:inherit;font-size:14px;resize:vertical;}'
		. '.ihfm-row input[type=file]{width:100%;padding:8px;border:1px dashed var(--ihfm-border);border-radius:9px;background:var(--ihfm-field);color:var(--ihfm-fg);font:inherit;font-size:14px;}'
		. '.ihfm-row[hidden]{display:none;}';

	$fail_server = esc_js( __( 'Could not reach the server — please try again.', 'wp-fan-mail' ) );
	$busy        = esc_js( __( 'Sending…', 'wp-fan-mail' ) );

	$bad_email = esc_js( __( 'That email address doesn\'t look complete — name@example.com', 'wp-fan-mail' ) );
	$bad_phone = esc_js( __( 'That doesn\'t look like a full phone number.', 'wp-fan-mail' ) );
	$bad_zip   = esc_js( __( 'ZIP codes look like 12345 or 12345-6789.', 'wp-fan-mail' ) );
	$bad_file  = esc_js( sprintf( __( 'That file is too large — up to %d MB.', 'wp-fan-mail' ), ih247_wpfm_max_upload_mb() ) );

	$js = '(function(){'
		// Inline field feedback: red border + a small hint under the field.
		. 'function mark(el,ok,msg){el.classList.toggle("ihfm-bad",!ok);'
		. 'var h=el.parentNode.querySelector(".ihfm-hint");'
		. 'if(!h){h=document.createElement("div");h.className="ihfm-hint";el.parentNode.appendChild(h);}'
		. 'h.textContent=ok?"":msg;h.style.display=ok?"none":"block";}'
		// Email: validate as soon as the visitor leaves the field.
		. 'function checkEmail(el){var v=el.value.trim();'
		. 'mark(el,v===""||/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v),"' . $bad_email . '");}'
		// Phone: auto-heal to E.164 (+15551234567) using the site\'s default
		// country code; heal when confident, flag when implausible.
		. 'function healPhone(el,cc){var v=el.value.trim();if(v===""){mark(el,true,"");return;}'
		. 'var d=v.replace(/\D/g,""),out;'
		. 'if(v.charAt(0)==="+"){out="+"+d;}'
		. 'else if(d.indexOf("00")===0){out="+"+d.slice(2);}'
		. 'else if(cc&&d.indexOf(cc)===0&&d.length>=11){out="+"+d;}'
		. 'else if(cc){out="+"+cc+d.replace(/^0+/,"");}'
		. 'else{out="+"+d;}'
		. 'var n=out.replace(/\D/g,"").length,ok=n>=8&&n<=15;'
		. 'if(ok){el.value=out;}mark(el,ok,"' . $bad_phone . '");}'
		. 'function bind(f){if(f.dataset.ihfmBound){return;}f.dataset.ihfmBound="1";'
		. 'var cc=f.getAttribute("data-cc")||"";'
		. 'var em=f.querySelector("input[name=ihfm_email]");'
		. 'if(em){em.addEventListener("blur",function(){checkEmail(em);});'
		. 'em.addEventListener("input",function(){if(em.classList.contains("ihfm-bad")){checkEmail(em);}});}'
		. 'var ph=f.querySelector("input[name=ihfm_phone]");'
		. 'if(ph){ph.addEventListener("blur",function(){healPhone(ph,cc);});'
		. 'ph.addEventListener("input",function(){if(ph.classList.contains("ihfm-bad")){mark(ph,true,"");}});}'
		// Conditional rules: rows carry data-cond-*; hidden rows disable
		// their inputs so nothing hidden ever submits or blocks validation.
		. 'function getVal(name,type){'
		. 'if(type==="checkbox"){var c=f.querySelector("input[name=\'"+name+"\']");return (c&&c.checked)?"Yes":"";}'
		. 'if(type==="checkboxes"){var out=[];f.querySelectorAll("input[name=\'"+name+"[]\']:checked").forEach(function(c){out.push(c.value);});return out.join(", ");}'
		. 'var r=f.querySelector("input[name=\'"+name+"\']:checked");if(r){return r.value;}'
		. 'var el=f.querySelector("[name=\'"+name+"\']");if(!el){return "";}'
		. 'return el.type==="radio"?"":(el.value||"");}'
		. 'function conds(){f.querySelectorAll(".ihfm-row[data-cond-name]").forEach(function(row){'
		. 'var v=getVal(row.getAttribute("data-cond-name"),row.getAttribute("data-cond-type"));'
		. 'var want=row.getAttribute("data-cond-val")||"";'
		. 'var show=(row.getAttribute("data-cond-op")==="not")?(v!==want):(v===want);'
		. 'row.hidden=!show;'
		. 'row.querySelectorAll("input,select,textarea").forEach(function(el){el.disabled=!show;});});}'
		. 'f.addEventListener("input",conds);f.addEventListener("change",conds);conds();'
		// ZIP lookup: format check locally; city/state autofill goes through
		// THIS site\'s server (never the visitor\'s browser to a third party).
		. 'Array.prototype.forEach.call(f.querySelectorAll("input.ihfm-zip"),function(z){'
		. 'z.addEventListener("blur",function(){var v=z.value.trim();'
		. 'if(v===""){mark(z,true,"");return;}'
		. 'if(!/^\\d{5}(-\\d{4})?$/.test(v)){mark(z,false,"' . $bad_zip . '");return;}'
		. 'mark(z,true,"");'
		. 'var wrap=z.closest(".ihfm-addr-row");if(!wrap){return;}'
		. 'var city=wrap.querySelector("input[name$=_city]"),st=wrap.querySelector("input[name$=_state]");'
		. 'if(city&&st&&(city.value.trim()===""||st.value.trim()==="")){'
		. 'var data=new FormData();data.append("action","ih247_wpfm_zip");data.append("zip",v.slice(0,5));'
		. 'fetch(f.getAttribute("action"),{method:"POST",body:data,credentials:"same-origin"})'
		. '.then(function(r){return r.json();})'
		. '.then(function(j){if(j&&j.success){if(city.value.trim()===""){city.value=j.data.city;}if(st.value.trim()===""){st.value=j.data.state;}}})'
		. '.catch(function(){});}});});'
		// File size pre-check (server enforces too).
		. 'var maxmb=parseInt(f.getAttribute("data-maxmb")||"10",10);'
		. 'Array.prototype.forEach.call(f.querySelectorAll("input[type=file]"),function(fi){'
		. 'fi.addEventListener("change",function(){'
		. 'if(fi.files[0]&&fi.files[0].size>maxmb*1048576){mark(fi,false,"' . $bad_file . '");fi.value="";}'
		. 'else{mark(fi,true,"");}});});'
		. 'f.addEventListener("submit",function(e){e.preventDefault();'
		. 'var btn=f.querySelector(".ihfm-submit"),note=f.querySelector(".ihfm-notice");'
		. 'if(btn){btn.dataset.label=btn.textContent;btn.disabled=true;btn.textContent="' . $busy . '";}'
		. 'var data=new FormData(f);data.append("ihfm_ajax","1");'
		. 'var fail=function(msg){if(note){note.hidden=false;note.textContent=msg;}'
		. 'if(btn){btn.disabled=false;btn.textContent=btn.dataset.label;}};'
		. 'fetch(f.getAttribute("action"),{method:"POST",body:data,credentials:"same-origin"})'
		. '.then(function(r){return r.json();})'
		. '.then(function(j){if(j&&j.success){var d=document.createElement("div");d.className="ihfm-success";'
		. 'd.innerHTML=j.data.message;f.parentNode.replaceChild(d,f);}'
		. 'else{fail(j&&j.data&&j.data.message?j.data.message:"' . $fail_server . '");}})'
		. '.catch(function(){fail("' . $fail_server . '");});});}'
		. 'Array.prototype.forEach.call(document.querySelectorAll(".ihfm-form"),bind);'
		. 'document.addEventListener("DOMContentLoaded",function(){Array.prototype.forEach.call(document.querySelectorAll(".ihfm-form"),bind);});})();';

	return '<style>' . $css . '</style><script>' . $js . '</script>';
}

/** Render one form to HTML. $args: 'preview' => true disables submission. */
function ih247_wpfm_form_html( $form, $args = array() ) {
	if ( ! is_array( $form ) || empty( $form['slug'] ) ) {
		return ''; // fail open — never break the page a form was meant to sit on
	}
	$slug           = $form['slug'];
	$form['fields'] = array_map( 'ih247_wpfm_normalize_row', array_values( (array) $form['fields'] ) );
	if ( ! ih247_wpfm_form_has_fields( $form ) ) {
		return ''; // a form with nothing to fill in isn't a form — fail open
	}
	$preview = ! empty( $args['preview'] );
	$ts      = time();
	$id      = 'ihfm-' . sanitize_html_class( $slug );
	$theme   = in_array( $form['theme'], array( 'auto', 'light', 'dark' ), true ) ? $form['theme'] : 'auto';

	// Look overrides ride CSS variables inline on the wrapper, so custom
	// colors win over the theme class without any specificity fights.
	$style = '--ihfm-accent:' . esc_attr( $form['accent'] ) . ';';
	$class = 'ihfm-wrap ihfm-theme-' . esc_attr( $theme );
	if ( 'clear' === $form['bg_mode'] ) {
		$class .= ' ihfm-bg-clear'; // passthrough — the page's own background shows behind the fields
	} elseif ( 'custom' === $form['bg_mode'] && $form['bg_color'] ) {
		$style .= '--ihfm-bg:' . esc_attr( $form['bg_color'] ) . ';';
	}
	if ( 'custom' === $form['field_mode'] && $form['field_color'] ) {
		$style .= '--ihfm-field:' . esc_attr( $form['field_color'] ) . ';';
	}
	if ( 'custom' === $form['ink_mode'] && $form['ink_color'] ) {
		$style .= '--ihfm-fg:' . esc_attr( $form['ink_color'] ) . ';--ihfm-muted:' . esc_attr( $form['ink_color'] ) . ';';
	}

	$html  = ih247_wpfm_assets_html();
	$html .= '<div class="' . $class . '" id="' . esc_attr( $id ) . '" style="' . $style . '">';

	if ( '' !== trim( (string) $form['css'] ) ) {
		$html .= '<style>' . $form['css'] . '</style>';
	}

	// No-JS success round-trip: the redirect back carries ?fan-mail=<slug>.
	if ( ! $preview && isset( $_GET['fan-mail'] ) && $slug === sanitize_title( wp_unslash( $_GET['fan-mail'] ) ) ) {
		$html .= '<div class="ihfm-success">' . wp_kses_post( $form['success'] ) . '</div></div>';
		return $html;
	}

	$error = '';
	if ( ! $preview && isset( $_GET['fan-mail-error'], $_GET['fan-mail-form'] ) && $slug === sanitize_title( wp_unslash( $_GET['fan-mail-form'] ) ) ) {
		$error = ih247_wpfm_error_text( sanitize_key( wp_unslash( $_GET['fan-mail-error'] ) ) );
	}

	if ( '' !== trim( (string) $form['intro'] ) ) {
		$html .= '<div class="ihfm-intro">' . wp_kses_post( $form['intro'] ) . '</div>';
	}

	$action = $preview ? '#' : esc_url( admin_url( 'admin-ajax.php' ) );
	$html  .= '<form class="ihfm-form" method="post" enctype="multipart/form-data" action="' . $action . '" data-cc="' . esc_attr( ih247_wpfm_default_cc() ) . '" data-maxmb="' . esc_attr( ih247_wpfm_max_upload_mb() ) . '"' . ( $preview ? ' onsubmit="return false"' : '' ) . '>';
	$html  .= '<input type="hidden" name="action" value="ih247_wpfm_submit" />';
	$html  .= '<input type="hidden" name="ihfm_form" value="' . esc_attr( $slug ) . '" />';
	$html  .= '<input type="hidden" name="ihfm_ts" value="' . esc_attr( $ts ) . '" />';
	$html  .= '<input type="hidden" name="ihfm_sig" value="' . esc_attr( ih247_wpfm_sign( $slug, $ts ) ) . '" />';
	$html  .= '<input type="hidden" name="ihfm_redirect" value="' . esc_attr( ih247_wpfm_current_url() ) . '" />';

	// Honeypot — invisible to people, irresistible to bots.
	$html .= '<p class="ihfm-hp" aria-hidden="true"><label>' . esc_html__( 'Website', 'wp-fan-mail' )
		. '<input type="text" name="ihfm_website" tabindex="-1" autocomplete="off" /></label></p>';

	$html .= '<div class="ihfm-notice" role="alert"' . ( '' === $error ? ' hidden' : '' ) . '>' . esc_html( $error ) . '</div>';

	$optional = ' <span class="ihfm-opt">' . esc_html__( '(optional)', 'wp-fan-mail' ) . '</span>';

	// Per-field tint: '' = follow theme/Look, hex = this field's own background.
	$tint = static function ( $hex ) {
		return $hex ? ' style="background:' . esc_attr( $hex ) . ';"' : '';
	};

	// THE field loop — nothing is baked in. Every row is a field the user
	// chose, the classic contact fields included; the input map hands the
	// first name/email/phone/subject/message their canonical wiring.
	$imap  = ih247_wpfm_field_input_map( $form );
	$bykey = array();
	foreach ( (array) $form['fields'] as $i => $cf ) {
		if ( '' !== $cf['key'] ) {
			$bykey[ $cf['key'] ] = array( 'name' => $imap[ $i ], 'type' => $cf['type'] );
		}
	}
	foreach ( (array) $form['fields'] as $i => $cf ) {
		$cname  = $imap[ $i ];
		$core   = 0 !== strpos( $cname, 'ihfm_cf_' );
		$cfid   = $id . '-f' . $i;
		$label  = esc_html( $cf['label'] ) . ( empty( $cf['required'] ) ? $optional : '' );
		$opts   = array_filter( array_map( 'trim', explode( "\n", (string) $cf['options'] ) ) );
		$req    = empty( $cf['required'] ) ? '' : ' required';
		$cftint = $tint( isset( $cf['color'] ) ? $cf['color'] : '' );

		// Conditional rule → data attributes; the tiny front-end engine
		// shows/hides live. No-JS visitors just see every field (graceful),
		// and the server mirrors the same rule for required-ness.
		$cond = '';
		if ( '' !== $cf['cond_key'] && isset( $bykey[ $cf['cond_key'] ] ) ) {
			$ctrl = $bykey[ $cf['cond_key'] ];
			$cond = ' data-cond-name="' . esc_attr( $ctrl['name'] ) . '" data-cond-type="' . esc_attr( $ctrl['type'] ) . '"'
				. ' data-cond-op="' . esc_attr( $cf['cond_op'] ) . '" data-cond-val="' . esc_attr( $cf['cond_val'] ) . '"';
		}
		$html .= '<div class="ihfm-row"' . $cond . '>';
		switch ( $cf['type'] ) {
			case 'name':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<input type="text" id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" maxlength="190" autocomplete="name"' . $req . $cftint . ' />';
				break;
			case 'email':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<input type="email" id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" maxlength="190" autocomplete="email"' . $req . $cftint . ' />';
				break;
			case 'phone':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<input type="tel" id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" maxlength="64" autocomplete="tel"' . $req . $cftint . ' />';
				break;
			case 'subject':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<input type="text" id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" maxlength="200"' . $req . $cftint . ' />';
				break;
			case 'message':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<textarea id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" maxlength="10000"' . $req . $cftint . '></textarea>';
				break;
			case 'textarea':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<textarea id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" maxlength="5000" style="min-height:80px;' . ( isset( $cf['color'] ) && $cf['color'] ? 'background:' . esc_attr( $cf['color'] ) . ';' : '' ) . '"' . $req . '></textarea>';
				break;
			case 'select':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<select id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '"' . $req . $cftint . '>'
					. '<option value="">' . esc_html__( 'Please choose…', 'wp-fan-mail' ) . '</option>';
				foreach ( $opts as $opt ) {
					$html .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
				}
				$html .= '</select>';
				break;
			case 'radio':
				$html .= '<label>' . $label . '</label>';
				foreach ( $opts as $j => $opt ) {
					$html .= '<label class="ihfm-choice"><input type="radio" name="' . esc_attr( $cname ) . '" value="' . esc_attr( $opt ) . '"' . ( $req && 0 === $j ? ' required' : '' ) . ' /> ' . esc_html( $opt ) . '</label>';
				}
				break;
			case 'checkboxes':
				// Pick all that apply — submits as an array.
				$html .= '<label>' . $label . '</label>';
				foreach ( $opts as $opt ) {
					$html .= '<label class="ihfm-choice"><input type="checkbox" name="' . esc_attr( $cname ) . '[]" value="' . esc_attr( $opt ) . '" /> ' . esc_html( $opt ) . '</label>';
				}
				break;
			case 'checkbox':
				$html .= '<label class="ihfm-choice"><input type="checkbox" name="' . esc_attr( $cname ) . '" value="1"' . $req . ' /> ' . $label . '</label>';
				break;
			case 'likert':
				// Agree…disagree — options override the stock 5 labels.
				$scale = $opts ? $opts : ih247_wpfm_likert_defaults();
				$html .= '<label>' . $label . '</label><span class="ihfm-inline">';
				foreach ( $scale as $j => $opt ) {
					$html .= '<label class="ihfm-choice"><input type="radio" name="' . esc_attr( $cname ) . '" value="' . esc_attr( $opt ) . '"' . ( $req && 0 === $j ? ' required' : '' ) . ' /> ' . esc_html( $opt ) . '</label>';
				}
				$html .= '</span>';
				break;
			case 'nps':
				$html .= '<label>' . $label . '</label><span class="ihfm-nps">';
				for ( $s = 0; $s <= 10; $s++ ) {
					$html .= '<input type="radio" id="' . esc_attr( $cfid . '-' . $s ) . '" name="' . esc_attr( $cname ) . '" value="' . $s . '"' . ( $req && 0 === $s ? ' required' : '' ) . ' />'
						. '<label for="' . esc_attr( $cfid . '-' . $s ) . '">' . $s . '</label>';
				}
				$html .= '</span><span class="ihfm-nps-ends"><span>' . esc_html__( 'Not at all likely', 'wp-fan-mail' ) . '</span><span>' . esc_html__( 'Extremely likely', 'wp-fan-mail' ) . '</span></span>';
				break;
			case 'address':
				// Street + city/state/ZIP. The ZIP input autofills city and
				// state through THIS site's server (never the visitor's
				// browser talking to a third party).
				$html .= '<label for="' . esc_attr( $cfid ) . '-street">' . $label . '</label>'
					. '<input type="text" id="' . esc_attr( $cfid ) . '-street" name="' . esc_attr( $cname ) . '_street" maxlength="190" autocomplete="address-line1" placeholder="' . esc_attr__( 'Street address', 'wp-fan-mail' ) . '"' . $req . $cftint . ' />'
					. '<span class="ihfm-addr-row">'
					. '<input type="text" class="ihfm-city" name="' . esc_attr( $cname ) . '_city" maxlength="100" autocomplete="address-level2" placeholder="' . esc_attr__( 'City', 'wp-fan-mail' ) . '"' . $req . $cftint . ' />'
					. '<input type="text" name="' . esc_attr( $cname ) . '_state" maxlength="40" autocomplete="address-level1" placeholder="' . esc_attr__( 'State', 'wp-fan-mail' ) . '"' . $req . $cftint . ' />'
					. '<input type="text" class="ihfm-zip" inputmode="numeric" name="' . esc_attr( $cname ) . '_zip" maxlength="10" autocomplete="postal-code" placeholder="' . esc_attr__( 'ZIP', 'wp-fan-mail' ) . '"' . $req . $cftint . ' />'
					. '</span>';
				break;
			case 'file':
				$html .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<input type="file" id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" accept=".jpg,.jpeg,.png,.gif,.webp,.heic,.pdf"' . $req . ' />'
					. '<div style="font-size:12.5px;color:var(--ihfm-muted);margin-top:4px;">' . esc_html( sprintf( __( 'Images or PDF, up to %d MB.', 'wp-fan-mail' ), ih247_wpfm_max_upload_mb() ) ) . '</div>';
				break;
			case 'rating':
				// Reverse DOM order so the :checked ~ sibling trick lights the
				// stars left-to-right. Values 5 → 1.
				$html .= '<label>' . $label . '</label><span class="ihfm-stars">';
				for ( $s = 5; $s >= 1; $s-- ) {
					$html .= '<input type="radio" id="' . esc_attr( $cfid . '-' . $s ) . '" name="' . esc_attr( $cname ) . '" value="' . $s . '"' . ( $req && 5 === $s ? ' required' : '' ) . ' />'
						. '<label for="' . esc_attr( $cfid . '-' . $s ) . '" title="' . esc_attr( $s . '/5' ) . '">★</label>';
				}
				$html .= '</span>';
				break;
			default:
				$input_types = array( 'number' => 'number', 'date' => 'date', 'url' => 'url' );
				$t           = isset( $input_types[ $cf['type'] ] ) ? $input_types[ $cf['type'] ] : 'text';
				$html       .= '<label for="' . esc_attr( $cfid ) . '">' . $label . '</label>'
					. '<input type="' . $t . '" id="' . esc_attr( $cfid ) . '" name="' . esc_attr( $cname ) . '" maxlength="500"' . $req . $cftint . ' />';
		}

		// Optional per-field notes box — room to elaborate on any answer.
		if ( ! empty( $cf['notes'] ) ) {
			$html .= '<textarea class="ihfm-notes" name="' . esc_attr( $cname ) . '_notes" maxlength="1000" placeholder="' . esc_attr__( 'Anything to add? (optional)', 'wp-fan-mail' ) . '"></textarea>';
		}

		$html .= '</div>';
	}

	if ( ! empty( $form['consent_on'] ) ) {
		$html .= '<div class="ihfm-row"><label class="ihfm-consent"><input type="checkbox" name="ihfm_consent" value="1" required /> '
			. '<span>' . esc_html( $form['consent_text'] ) . '</span></label></div>';
	}

	$html .= '<div class="ihfm-row"><button type="submit" class="ihfm-submit">' . esc_html( $form['button'] ) . '</button></div>';
	$html .= '</form>';

	if ( ! get_option( IH247_WPFM_OPT_HIDE_CREDIT, false ) ) {
		$html .= '<p class="ihfm-credit"><a href="' . esc_url( IH247_WPFM_PROJECTS_URL ) . '" target="_blank" rel="noopener nofollow">'
			. esc_html__( 'fan mail by iHelp247', 'wp-fan-mail' ) . '</a></p>';
	}

	$html .= '</div>';
	return apply_filters( 'ihelp247_wp_fan_mail_form_html', $html, $form, $args );
}

/* -------------------------------------------------------------------------
 * Placement — a shortcode for regular pages and a token for raw HTML.
 * Both read like the thing they are: the form you named "Contact us"
 * answers to [fan-mail form="contact-us"] and {fan-mail-contact-us}.
 * The bare [fan-mail] / {fan-mail} always means your first form.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'form' => '' ), $atts, 'fan-mail' );
	$form = ih247_wpfm_get_form( sanitize_title( $atts['form'] ) );
	return $form ? ih247_wpfm_form_html( $form ) : '';
}
add_shortcode( 'fan-mail', 'ih247_wpfm_shortcode' );

function ih247_wpfm_replace_tokens( $html ) {
	if ( ! is_string( $html ) || false === strpos( $html, '{fan-mail' ) ) {
		return $html;
	}
	return preg_replace_callback(
		'/\{fan-mail(?:-([a-z0-9\-]+))?\}/',
		function ( $m ) {
			$form = ih247_wpfm_get_form( isset( $m[1] ) ? $m[1] : '' );
			return $form ? ih247_wpfm_form_html( $form ) : ''; // unknown token disappears — fail open
		},
		$html
	);
}
// Sibling integration: WP Intermission filters its final page HTML here, so a
// {fan-mail-…} token dropped into a maintenance page becomes a working form
// (AJAX submits are never blocked by Intermission — by both plugins' design).
add_filter( 'ihelp247_wp_intermission_html', 'ih247_wpfm_replace_tokens', 20 );
// And the same token works in regular post/page content, next to the shortcode.
add_filter( 'the_content', 'ih247_wpfm_replace_tokens', 11 );

/* -------------------------------------------------------------------------
 * Submission — validate like a doorman, store like a vault, mail like a
 * courier. The database write always happens BEFORE the send attempt, so
 * a broken mail setup can never lose a message.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_client_ip() {
	// REMOTE_ADDR only — proxy headers are trivially forged. Used for rate
	// limiting always (hashed, in a transient); stored only when opted in.
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/** Answer the browser: JSON for fetch() submits, redirect for no-JS ones. */
function ih247_wpfm_respond( $ok, $slug, $message, $code = '' ) {
	if ( ! empty( $_POST['ihfm_ajax'] ) ) {
		if ( $ok ) {
			wp_send_json_success( array( 'message' => $message ) );
		}
		wp_send_json_error( array( 'message' => $message ) );
	}
	$url = isset( $_POST['ihfm_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['ihfm_redirect'] ) ) : home_url( '/' );
	if ( ! $url || 0 !== strpos( $url, home_url() ) ) {
		$url = home_url( '/' ); // only ever redirect within this site
	}
	$url = $ok
		? add_query_arg( 'fan-mail', $slug, $url )
		: add_query_arg( array( 'fan-mail-error' => $code, 'fan-mail-form' => $slug ), $url );
	wp_safe_redirect( $url . '#ihfm-' . $slug );
	exit;
}

function ih247_wpfm_handle_submit() {
	$slug = isset( $_POST['ihfm_form'] ) ? sanitize_title( wp_unslash( $_POST['ihfm_form'] ) ) : '';
	$form = ih247_wpfm_get_form( $slug );
	if ( ! $form ) {
		ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'fresh' ), 'fresh' );
	}
	$slug = $form['slug'];

	// Honeypot filled = bot. Say "thanks" and store nothing.
	if ( ! empty( $_POST['ihfm_website'] ) ) {
		ih247_wpfm_respond( true, $slug, wp_kses_post( $form['success'] ) );
	}

	// Signed render timestamp: too fast = bot, too old = stale cached page.
	$ts  = isset( $_POST['ihfm_ts'] ) ? absint( $_POST['ihfm_ts'] ) : 0;
	$sig = isset( $_POST['ihfm_sig'] ) ? (string) wp_unslash( $_POST['ihfm_sig'] ) : '';
	if ( ! $ts || ! hash_equals( ih247_wpfm_sign( $slug, $ts ), $sig ) || time() - $ts > IH247_WPFM_MAX_AGE ) {
		ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'fresh' ), 'fresh' );
	}
	if ( time() - $ts < IH247_WPFM_MIN_SECONDS ) {
		ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'fast' ), 'fast' );
	}

	// Rate limit per IP (hashed key; the address itself is not persisted here).
	$ip = ih247_wpfm_client_ip();
	if ( $ip ) {
		$key   = 'ih247_wpfm_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= IH247_WPFM_RATE_MAX ) {
			ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'rate' ), 'rate' );
		}
		set_transient( $key, $count + 1, IH247_WPFM_RATE_WINDOW );
	}

	// THE field loop — mirrors the renderer via the same input map. The
	// first name/email/phone/subject/message row feeds its canonical slot;
	// everything else (generic types and duplicate smart types) lands in
	// the extra data, label => value.
	$form['fields'] = array_map( 'ih247_wpfm_normalize_row', array_values( (array) $form['fields'] ) );

	$name    = '';
	$email   = '';
	$phone   = '';
	$subject = '';
	$message = '';
	$extra   = array();
	$consent = ! empty( $_POST['ihfm_consent'] ) ? 1 : 0;
	$imap    = ih247_wpfm_field_input_map( $form );

	// Pass 1 — comparable value per field key, for conditional rules
	// (mirrors the front-end engine's getVal()).
	$rawmap = array();
	foreach ( (array) $form['fields'] as $i => $cf ) {
		if ( '' === $cf['key'] ) {
			continue;
		}
		$cname = $imap[ $i ];
		if ( 'checkbox' === $cf['type'] ) {
			$rawmap[ $cf['key'] ] = empty( $_POST[ $cname ] ) ? '' : __( 'Yes', 'wp-fan-mail' );
		} elseif ( 'checkboxes' === $cf['type'] ) {
			$vals                 = isset( $_POST[ $cname ] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $cname ] ) ) : array();
			$rawmap[ $cf['key'] ] = implode( ', ', array_filter( $vals ) );
		} elseif ( 'file' === $cf['type'] ) {
			$rawmap[ $cf['key'] ] = ( ! empty( $_FILES[ $cname ]['name'] ) ) ? __( 'Yes', 'wp-fan-mail' ) : '';
		} else {
			$rawmap[ $cf['key'] ] = trim( sanitize_text_field( isset( $_POST[ $cname ] ) ? (string) wp_unslash( $_POST[ $cname ] ) : '' ) );
		}
	}
	$visible = static function ( $cf ) use ( $rawmap ) {
		if ( '' === $cf['cond_key'] || ! isset( $rawmap[ $cf['cond_key'] ] ) ) {
			return true;
		}
		$v    = $rawmap[ $cf['cond_key'] ];
		$want = $cf['cond_val'];
		return 'not' === $cf['cond_op'] ? ( $v !== $want ) : ( $v === $want );
	};

	foreach ( (array) $form['fields'] as $i => $cf ) {
		$cname = $imap[ $i ];
		$core  = 0 !== strpos( $cname, 'ihfm_cf_' );
		$raw   = isset( $_POST[ $cname ] ) ? wp_unslash( $_POST[ $cname ] ) : '';
		// A field hidden by its rule is never required — but a value a
		// no-JS visitor typed into it is still kept (kinder than dropping it).
		$reqd = ! empty( $cf['required'] ) && $visible( $cf );

		// Optional notes box rides with any field type.
		if ( ! empty( $cf['notes'] ) ) {
			$note = isset( $_POST[ $cname . '_notes' ] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST[ $cname . '_notes' ] ) ), 0, 1000 ) : '';
			if ( '' !== trim( $note ) ) {
				$extra[ $cf['label'] . ' — ' . __( 'notes', 'wp-fan-mail' ) ] = $note;
			}
		}

		if ( $core ) {
			switch ( $cf['type'] ) {
				case 'name':
					$name = mb_substr( sanitize_text_field( (string) $raw ), 0, 190 );
					if ( $reqd && '' === trim( $name ) ) {
						ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'missing' ), 'missing' );
					}
					break;
				case 'email':
					$email = mb_substr( sanitize_email( (string) $raw ), 0, 190 );
					if ( ( '' !== $email && ! is_email( $email ) ) || ( $reqd && '' === $email ) ) {
						ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'email' ), 'email' );
					}
					break;
				case 'phone':
					$phone = mb_substr( ih247_wpfm_normalize_phone( sanitize_text_field( (string) $raw ), ih247_wpfm_default_cc() ), 0, 64 );
					if ( $reqd && '' === trim( $phone ) ) {
						ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'missing' ), 'missing' );
					}
					break;
				case 'subject':
					$subject = mb_substr( sanitize_text_field( (string) $raw ), 0, 200 );
					if ( $reqd && '' === trim( $subject ) ) {
						ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'missing' ), 'missing' );
					}
					break;
				case 'message':
					$message = mb_substr( sanitize_textarea_field( (string) $raw ), 0, 10000 );
					if ( $reqd && '' === trim( $message ) ) {
						ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'missing' ), 'missing' );
					}
					break;
			}
			continue;
		}

		$val = '';
		switch ( $cf['type'] ) {
			case 'textarea':
			case 'message':
				$val = mb_substr( sanitize_textarea_field( (string) $raw ), 0, 5000 );
				break;
			case 'checkbox':
				$val = empty( $raw ) ? '' : __( 'Yes', 'wp-fan-mail' );
				break;
			case 'select':
			case 'radio':
				// Only a configured choice is accepted — tampered values drop.
				$opts = array_filter( array_map( 'trim', explode( "\n", (string) $cf['options'] ) ) );
				$val  = in_array( (string) $raw, $opts, true ) ? (string) $raw : '';
				break;
			case 'checkboxes':
				// Pick-all-that-apply — only configured choices survive.
				$opts = array_filter( array_map( 'trim', explode( "\n", (string) $cf['options'] ) ) );
				$vals = array();
				foreach ( (array) $raw as $one ) {
					$one = sanitize_text_field( (string) $one );
					if ( in_array( $one, $opts, true ) ) {
						$vals[] = $one;
					}
				}
				$val = implode( ', ', $vals );
				break;
			case 'likert':
				$scale = array_filter( array_map( 'trim', explode( "\n", (string) $cf['options'] ) ) );
				$scale = $scale ? $scale : ih247_wpfm_likert_defaults();
				$val   = in_array( (string) $raw, $scale, true ) ? (string) $raw : '';
				break;
			case 'nps':
				$val = ( is_numeric( $raw ) && (int) $raw >= 0 && (int) $raw <= 10 ) ? (int) $raw . '/10' : '';
				break;
			case 'rating':
				$n   = absint( $raw );
				$val = ( $n >= 1 && $n <= 5 ) ? $n . '/5' : '';
				break;
			case 'phone':
				$val = mb_substr( ih247_wpfm_normalize_phone( sanitize_text_field( (string) $raw ), ih247_wpfm_default_cc() ), 0, 64 );
				break;
			case 'address':
				$street = isset( $_POST[ $cname . '_street' ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $cname . '_street' ] ) ), 0, 190 ) : '';
				$city   = isset( $_POST[ $cname . '_city' ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $cname . '_city' ] ) ), 0, 100 ) : '';
				$state  = isset( $_POST[ $cname . '_state' ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $cname . '_state' ] ) ), 0, 40 ) : '';
				$zip    = isset( $_POST[ $cname . '_zip' ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $cname . '_zip' ] ) ), 0, 10 ) : '';
				if ( '' !== $zip && ! preg_match( '/^\d{5}(-\d{4})?$/', $zip ) ) {
					ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'zip' ), 'zip' );
				}
				if ( $reqd && ( '' === trim( $street ) || '' === trim( $city ) || '' === trim( $zip ) ) ) {
					ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'missing' ), 'missing' );
				}
				$line2 = trim( $city . ( '' !== $state ? ', ' . $state : '' ) . ( '' !== $zip ? ' ' . $zip : '' ) );
				$val   = trim( $street . ( '' !== $line2 ? "\n" . $line2 : '' ) );
				break;
			case 'file':
				$upload = ih247_wpfm_handle_upload( $cname );
				if ( is_wp_error( $upload ) ) {
					ih247_wpfm_respond( false, $slug, $upload->get_error_message(), 'file' );
				}
				$val = (string) $upload;
				break;
			default:
				$val = mb_substr( sanitize_text_field( (string) $raw ), 0, 500 );
		}
		if ( $reqd && '' === trim( (string) $val ) ) {
			ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'missing' ), 'missing' );
		}
		if ( '' !== trim( (string) $val ) ) {
			$extra[ $cf['label'] ] = (string) $val;
		}
	}

	if ( ! empty( $form['consent_on'] ) && ! $consent ) {
		ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'consent' ), 'consent' );
	}

	// Floor: something must have been said.
	if ( '' === $email && '' === trim( $message ) && '' === trim( $name ) && '' === trim( $phone ) && '' === trim( $subject ) && empty( $extra ) ) {
		ih247_wpfm_respond( false, $slug, ih247_wpfm_error_text( 'missing' ), 'missing' );
	}

	// No email on this form? Keep name/phone with the message itself,
	// since there is no contact record to hang them on.
	if ( '' === $email ) {
		if ( '' !== trim( $phone ) ) {
			$extra = array( __( 'Phone', 'wp-fan-mail' ) => $phone ) + $extra;
		}
		if ( '' !== trim( $name ) ) {
			$extra = array( __( 'Name', 'wp-fan-mail' ) => $name ) + $extra;
		}
	}

	$page_url = isset( $_POST['ihfm_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['ihfm_redirect'] ) ) : '';

	// ---- Vault first: contact upsert + message insert. -------------------
	global $wpdb;
	$now      = current_time( 'mysql' );
	$contacts = ih247_wpfm_table_contacts();
	$messages = ih247_wpfm_table_messages();

	$contact_id = '' === $email ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $contacts WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( '' === $email ) {
		$contact_id = 0; // anonymous by the site owner's design — message still lands in the Inbox
	} elseif ( $contact_id ) {
		$update = array( 'last_seen' => $now );
		if ( '' !== $name ) {
			$update['name'] = $name;
		}
		if ( '' !== $phone ) {
			$update['phone'] = $phone;
		}
		if ( $consent ) {
			$update['consent'] = 1;
		}
		$wpdb->update( $contacts, $update, array( 'id' => $contact_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $contacts SET message_count = message_count + 1 WHERE id = %d", $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		$wpdb->insert(
			$contacts,
			array(
				'email'         => $email,
				'name'          => $name,
				'phone'         => $phone,
				'consent'       => $consent,
				'message_count' => 1,
				'first_seen'    => $now,
				'last_seen'     => $now,
			)
		);
		$contact_id = (int) $wpdb->insert_id;
	}

	$carrier = ih247_wpfm_carrier();
	$wpdb->insert(
		$messages,
		array(
			'contact_id'  => $contact_id,
			'form_slug'   => $slug,
			'subject'     => $subject,
			'message'     => $message,
			'extra'       => $extra ? wp_json_encode( $extra ) : '',
			'page_url'    => mb_substr( $page_url, 0, 255 ),
			'ip'          => get_option( IH247_WPFM_OPT_STORE_IP, false ) ? $ip : '',
			'status'      => 'new',
			'carrier'     => $carrier,
			'mail_status' => 'pending',
			'created_at'  => $now,
		)
	);
	$message_id = (int) $wpdb->insert_id;

	// ---- Then the courier. -----------------------------------------------
	$visitor = array( 'name' => $name, 'email' => $email );
	$result  = ih247_wpfm_send_notification( $form, $visitor, $phone, $subject, $message, $page_url, $extra, $message_id );

	if ( $message_id ) {
		$wpdb->update(
			$messages,
			array(
				'mail_status' => is_wp_error( $result ) ? 'failed' : 'sent',
				'mail_error'  => is_wp_error( $result ) ? mb_substr( $result->get_error_message(), 0, 1000 ) : '',
			),
			array( 'id' => $message_id )
		);
	}

	// The visitor's message is safe in the Inbox either way — success it is.
	do_action( 'ihelp247_wp_fan_mail_after_submit', $message_id, $form, $visitor, $result );
	ih247_wpfm_respond( true, $slug, wp_kses_post( $form['success'] ) );
}
add_action( 'wp_ajax_ih247_wpfm_submit', 'ih247_wpfm_handle_submit' );
add_action( 'wp_ajax_nopriv_ih247_wpfm_submit', 'ih247_wpfm_handle_submit' );

/* -------------------------------------------------------------------------
 * Uploads (File fields) + ZIP lookup — support plumbing for the two field
 * types that touch the outside world. Uploads ride WordPress's own
 * pipeline into uploads/fan-mail/ with randomized names; ZIP lookups go
 * out from THIS server (cached, rate-limited) so visitors' browsers never
 * contact a third party.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_upload_dir_filter( $dirs ) {
	$dirs['subdir'] = '/fan-mail' . $dirs['subdir'];
	$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
	$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
	return $dirs;
}

/** Handle one File field. Returns the stored URL, '' when no file was sent,
 *  or WP_Error when the file was rejected. */
function ih247_wpfm_handle_upload( $field_name ) {
	if ( empty( $_FILES[ $field_name ] ) || ! isset( $_FILES[ $field_name ]['error'] ) ) {
		return '';
	}
	$file = $_FILES[ $field_name ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below
	if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
		return '';
	}
	if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
		return new WP_Error( 'ih247_wpfm_file', __( 'The upload did not arrive intact — please try again.', 'wp-fan-mail' ) );
	}
	if ( (int) $file['size'] > ih247_wpfm_max_upload_mb() * 1048576 ) {
		return new WP_Error( 'ih247_wpfm_file', sprintf( __( 'That file is too large — up to %d MB.', 'wp-fan-mail' ), ih247_wpfm_max_upload_mb() ) );
	}
	// Real content check, not just the extension label.
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], ih247_wpfm_upload_mimes() );
	if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
		return new WP_Error( 'ih247_wpfm_file', __( 'That file type isn\'t accepted — images or PDF only.', 'wp-fan-mail' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	// Random prefix: unguessable URLs, no name collisions, original name kept readable.
	$file['name'] = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 10 ) . '-' . sanitize_file_name( $file['name'] );
	add_filter( 'upload_dir', 'ih247_wpfm_upload_dir_filter' );
	$res = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => ih247_wpfm_upload_mimes() ) );
	remove_filter( 'upload_dir', 'ih247_wpfm_upload_dir_filter' );
	if ( ! is_array( $res ) || isset( $res['error'] ) ) {
		return new WP_Error( 'ih247_wpfm_file', isset( $res['error'] ) ? $res['error'] : __( 'Upload failed.', 'wp-fan-mail' ) );
	}
	return esc_url_raw( $res['url'] );
}

/** Delete any fan-mail-uploaded files referenced by a message's extra data —
 *  called wherever messages are deleted, so files never outlive them. */
function ih247_wpfm_delete_files_for( $extra_json ) {
	if ( empty( $extra_json ) ) {
		return;
	}
	$data = json_decode( (string) $extra_json, true );
	if ( ! is_array( $data ) ) {
		return;
	}
	$up       = wp_get_upload_dir();
	$base_url = $up['baseurl'] . '/fan-mail/';
	foreach ( $data as $v ) {
		if ( is_string( $v ) && 0 === strpos( $v, $base_url ) ) {
			$rel = str_replace( array( '..', '\\' ), '', substr( $v, strlen( $base_url ) ) );
			$abs = $up['basedir'] . '/fan-mail/' . $rel;
			if ( file_exists( $abs ) ) {
				@unlink( $abs ); // phpcs:ignore
			}
		}
	}
}

/** ZIP → city/state, proxied through this server. Cached 30 days per ZIP,
 *  rate-limited per IP, free service (zippopotam.us), no API key. */
function ih247_wpfm_zip_ajax() {
	$zip = isset( $_POST['zip'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['zip'] ) ) : '';
	$zip = substr( $zip, 0, 5 );
	if ( 5 !== strlen( $zip ) ) {
		wp_send_json_error();
	}
	$ip = ih247_wpfm_client_ip();
	if ( $ip ) {
		$rk    = 'ih247_wpfm_zrl_' . md5( $ip );
		$count = (int) get_transient( $rk );
		if ( $count >= 30 ) {
			wp_send_json_error();
		}
		set_transient( $rk, $count + 1, 10 * MINUTE_IN_SECONDS );
	}
	$ck  = 'ih247_wpfm_zip_' . $zip;
	$hit = get_transient( $ck );
	if ( false === $hit || ! is_array( $hit ) ) {
		$hit  = array( 'city' => '', 'state' => '' );
		$resp = wp_remote_get( 'https://api.zippopotam.us/us/' . $zip, array( 'timeout' => 5 ) );
		if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( isset( $body['places'][0]['place name'] ) ) {
				$hit = array(
					'city'  => sanitize_text_field( $body['places'][0]['place name'] ),
					'state' => sanitize_text_field( isset( $body['places'][0]['state abbreviation'] ) ? $body['places'][0]['state abbreviation'] : '' ),
				);
			}
		}
		set_transient( $ck, $hit, 30 * DAY_IN_SECONDS ); // misses cached too (1 lookup per bad ZIP per month)
	}
	if ( '' === $hit['city'] ) {
		wp_send_json_error();
	}
	wp_send_json_success( $hit );
}
add_action( 'wp_ajax_ih247_wpfm_zip', 'ih247_wpfm_zip_ajax' );
add_action( 'wp_ajax_nopriv_ih247_wpfm_zip', 'ih247_wpfm_zip_ajax' );

/* -------------------------------------------------------------------------
 * Carriers — how notifications leave the building. v1 ships wp_mail and
 * SendGrid; SMTP, Microsoft 365, and Google are next. Developers can add
 * their own via the `ihelp247_wp_fan_mail_carriers` filter.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_carriers() {
	$carriers = array(
		'wp_mail'  => array(
			'label' => __( 'WordPress mail (wp_mail) — whatever your site already sends with. Zero setup, but shared hosts often land in spam.', 'wp-fan-mail' ),
			'send'  => 'ih247_wpfm_send_wp_mail',
		),
		'sendgrid' => array(
			'label' => __( 'SendGrid — reliable delivery through api.sendgrid.com with an API key. The easy fix for spam-folder problems; free tier available.', 'wp-fan-mail' ),
			'send'  => 'ih247_wpfm_send_sendgrid',
		),
	);
	return apply_filters( 'ihelp247_wp_fan_mail_carriers', $carriers );
}

function ih247_wpfm_carrier() {
	$carrier = get_option( IH247_WPFM_OPT_CARRIER, 'wp_mail' );
	$all     = ih247_wpfm_carriers();
	return isset( $all[ $carrier ] ) ? $carrier : 'wp_mail';
}

function ih247_wpfm_sendgrid_key() {
	// wp-config constant wins — keeps the key out of the database if you prefer.
	if ( defined( 'IH247_WPFM_SENDGRID_KEY' ) && IH247_WPFM_SENDGRID_KEY ) {
		return (string) IH247_WPFM_SENDGRID_KEY;
	}
	return (string) get_option( IH247_WPFM_OPT_SENDGRID_KEY, '' );
}

function ih247_wpfm_from_name() {
	$name = trim( (string) get_option( IH247_WPFM_OPT_FROM_NAME, '' ) );
	return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
}

function ih247_wpfm_from_email() {
	$email = sanitize_email( (string) get_option( IH247_WPFM_OPT_FROM_EMAIL, '' ) );
	return $email && is_email( $email ) ? $email : '';
}

/** Compose and send one notification email through the active carrier. */
function ih247_wpfm_send_notification( $form, $visitor, $phone, $subject, $message, $page_url, $extra, $message_id ) {
	$to        = ih247_wpfm_form_recipients( $form );
	$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$mail_subj = sprintf(
		/* translators: 1: site name, 2: form name, 3: visitor subject or name */
		__( '[%1$s] %2$s: %3$s', 'wp-fan-mail' ),
		$site,
		$form['name'],
		'' !== $subject ? $subject : ( '' !== $visitor['name'] ? $visitor['name'] : ( '' !== $visitor['email'] ? $visitor['email'] : __( 'new message', 'wp-fan-mail' ) ) )
	);

	$lines   = array();
	$lines[] = sprintf( __( 'You\'ve got fan mail on %s.', 'wp-fan-mail' ), $site );
	$lines[] = '';
	if ( '' !== $visitor['email'] || '' !== $visitor['name'] ) {
		$lines[] = __( 'From:', 'wp-fan-mail' ) . ' ' . trim( $visitor['name'] . ( '' !== $visitor['email'] ? ' <' . $visitor['email'] . '>' : '' ) );
	} else {
		$lines[] = __( 'From: (no contact details on this form)', 'wp-fan-mail' );
	}
	if ( '' !== $phone ) {
		$lines[] = __( 'Phone:', 'wp-fan-mail' ) . ' ' . $phone;
	}
	if ( '' !== $subject ) {
		$lines[] = __( 'Subject:', 'wp-fan-mail' ) . ' ' . $subject;
	}
	$lines[] = __( 'Form:', 'wp-fan-mail' ) . ' ' . $form['name'] . ' (' . $form['slug'] . ')';
	if ( '' !== $page_url ) {
		$lines[] = __( 'Page:', 'wp-fan-mail' ) . ' ' . $page_url;
	}
	foreach ( (array) $extra as $xk => $xv ) {
		$lines[] = $xk . ': ' . ( false === strpos( (string) $xv, "\n" ) ? $xv : "\n" . $xv );
	}
	$lines[] = '';
	$lines[] = $message;
	$lines[] = '';
	$lines[] = '--';
	if ( '' !== $visitor['email'] ) {
		$lines[] = __( 'Reply to this email to answer — Reply-To is set to the sender.', 'wp-fan-mail' );
	}
	$lines[] = __( 'Also saved in your Fan Mail inbox:', 'wp-fan-mail' ) . ' ' . admin_url( 'admin.php?page=fan-mail' . ( $message_id ? '&view=' . $message_id : '' ) );
	$body    = implode( "\n", $lines );

	$carriers = ih247_wpfm_carriers();
	$carrier  = ih247_wpfm_carrier();
	$send     = $carriers[ $carrier ]['send'];
	if ( ! is_callable( $send ) ) {
		return new WP_Error( 'ih247_wpfm_carrier', __( 'Mail carrier is not available.', 'wp-fan-mail' ) );
	}
	return call_user_func( $send, $to, $mail_subj, $body, $visitor );
}

/** Carrier: wp_mail(). From stays a site address (never the visitor's —
 *  spoofed From fails DMARC); Reply-To carries the visitor. The
 *  wp_mail_failed hook is captured during the send so failures carry the
 *  real PHPMailer reason (SMTP refused, could not instantiate, …) instead
 *  of a bare "returned false" — that detail lands in the Inbox row and the
 *  test-send result, which is where diagnosis starts. */
function ih247_wpfm_send_wp_mail( $to, $subject, $body, $visitor ) {
	$headers = array();
	if ( ! empty( $visitor['email'] ) ) {
		$headers[] = 'Reply-To: ' . trim( $visitor['name'] . ' <' . $visitor['email'] . '>' );
	}
	$from = ih247_wpfm_from_email();
	if ( $from ) {
		$headers[] = 'From: ' . ih247_wpfm_from_name() . ' <' . $from . '>';
	}

	$GLOBALS['ih247_wpfm_last_mail_error'] = '';
	$capture = static function ( $error ) {
		$GLOBALS['ih247_wpfm_last_mail_error'] = is_wp_error( $error ) ? $error->get_error_message() : '';
	};
	add_action( 'wp_mail_failed', $capture );
	$ok = wp_mail( $to, $subject, $body, $headers );
	remove_action( 'wp_mail_failed', $capture );

	if ( $ok ) {
		return true;
	}
	$detail = (string) $GLOBALS['ih247_wpfm_last_mail_error'];
	return new WP_Error(
		'ih247_wpfm_wp_mail',
		'' !== $detail
			? sprintf( __( 'wp_mail failed: %s', 'wp-fan-mail' ), $detail )
			: __( 'wp_mail() returned false — the site\'s mail setup refused the message. See the user guide\'s deliverability section.', 'wp-fan-mail' )
	);
}

/** Carrier: SendGrid v3 API. Needs an API key and a verified From address. */
function ih247_wpfm_send_sendgrid( $to, $subject, $body, $visitor ) {
	$key = ih247_wpfm_sendgrid_key();
	if ( '' === $key ) {
		return new WP_Error( 'ih247_wpfm_sendgrid', __( 'SendGrid is selected but no API key is set (Fan Mail → Settings).', 'wp-fan-mail' ) );
	}
	$from = ih247_wpfm_from_email();
	if ( '' === $from ) {
		return new WP_Error( 'ih247_wpfm_sendgrid', __( 'SendGrid needs a From address that is a verified sender in your SendGrid account (Fan Mail → Settings).', 'wp-fan-mail' ) );
	}

	$recipients = array();
	foreach ( (array) $to as $addr ) {
		$recipients[] = array( 'email' => $addr );
	}
	$payload = array(
		'personalizations' => array( array( 'to' => $recipients ) ),
		'from'             => array( 'email' => $from, 'name' => ih247_wpfm_from_name() ),
		'subject'          => $subject,
		'content'          => array( array( 'type' => 'text/plain', 'value' => $body ) ),
	);
	if ( ! empty( $visitor['email'] ) ) {
		$payload['reply_to'] = array( 'email' => $visitor['email'] ) + ( '' !== $visitor['name'] ? array( 'name' => $visitor['name'] ) : array() );
	}

	$resp = wp_remote_post(
		'https://api.sendgrid.com/v3/mail/send',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 202 === $code || 200 === $code ) {
		return true;
	}
	$detail = mb_substr( trim( (string) wp_remote_retrieve_body( $resp ) ), 0, 500 );
	return new WP_Error( 'ih247_wpfm_sendgrid', sprintf( __( 'SendGrid answered HTTP %1$d: %2$s', 'wp-fan-mail' ), $code, $detail ) );
}

/** Settings-page "Send a test" button. */
function ih247_wpfm_test_mail_ajax() {
	check_ajax_referer( 'ih247_wpfm_admin', 'nonce' );
	if ( ! current_user_can( IH247_WPFM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => __( 'Not allowed.', 'wp-fan-mail' ) ) );
	}
	$user    = wp_get_current_user();
	$to      = array( $user->user_email );
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$carrier = ih247_wpfm_carrier();
	$send    = ih247_wpfm_carriers()[ $carrier ]['send'];
	$result  = call_user_func(
		$send,
		$to,
		sprintf( __( '[%s] Fan Mail test message', 'wp-fan-mail' ), $site ),
		sprintf( __( "This is a test from iHelp247 WP Fan Mail on %s.\nCarrier: %s\nIf you are reading this, delivery works.", 'wp-fan-mail' ), $site, $carrier ),
		array( 'name' => $user->display_name, 'email' => $user->user_email )
	);
	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: 1: carrier key, 2: error detail */
					__( 'Not sent (via %1$s): %2$s', 'wp-fan-mail' ),
					$carrier,
					$result->get_error_message()
				),
			)
		);
	}
	$from = ih247_wpfm_from_email();
	$msg  = sprintf(
		/* translators: 1: carrier key, 2: from address, 3: recipient */
		__( 'Handed to %1$s (From: %2$s) for %3$s — check that inbox and its spam folder.', 'wp-fan-mail' ),
		$carrier,
		$from ? $from : __( 'host default address', 'wp-fan-mail' ),
		$user->user_email
	);
	if ( 'wp_mail' === $carrier ) {
		$msg .= ' ' . __( 'Heads-up: with wp_mail, "handed off" only means this server accepted the message — if nothing arrives, the host\'s mail setup is the culprit (see the deliverability guide, or switch to SendGrid).', 'wp-fan-mail' );
	}
	wp_send_json_success( array( 'message' => $msg ) );
}
add_action( 'wp_ajax_ih247_wpfm_test_mail', 'ih247_wpfm_test_mail_ajax' );

/* -------------------------------------------------------------------------
 * Admin — a proper Inbox. Messages live here first; email is a courtesy
 * copy. Fan Mail gets its own menu with an unread badge, like Comments.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_unread_count() {
	global $wpdb;
	$messages = ih247_wpfm_table_messages();
	$count    = $wpdb->get_var( "SELECT COUNT(*) FROM $messages WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $count;
}

function ih247_wpfm_menu() {
	$unread = ih247_wpfm_unread_count();
	$badge  = $unread
		? ' <span class="awaiting-mod count-' . $unread . '"><span class="pending-count">' . number_format_i18n( $unread ) . '</span></span>'
		: '';
	add_menu_page(
		__( 'iHelp247 WP Fan Mail', 'wp-fan-mail' ),
		__( 'Fan Mail', 'wp-fan-mail' ) . $badge,
		IH247_WPFM_CAPABILITY,
		'fan-mail',
		'ih247_wpfm_inbox_page',
		'dashicons-email-alt',
		26
	);
	add_submenu_page( 'fan-mail', __( 'Inbox', 'wp-fan-mail' ), __( 'Inbox', 'wp-fan-mail' ), IH247_WPFM_CAPABILITY, 'fan-mail', 'ih247_wpfm_inbox_page' );
	add_submenu_page( 'fan-mail', __( 'Forms', 'wp-fan-mail' ), __( 'Forms', 'wp-fan-mail' ), IH247_WPFM_CAPABILITY, 'fan-mail-forms', 'ih247_wpfm_forms_page' );
	add_submenu_page( 'fan-mail', __( 'Settings', 'wp-fan-mail' ), __( 'Settings', 'wp-fan-mail' ), IH247_WPFM_CAPABILITY, 'fan-mail-settings', 'ih247_wpfm_settings_page' );
}
add_action( 'admin_menu', 'ih247_wpfm_menu' );

/** Inbox actions run on admin_init so redirects happen before any output. */
function ih247_wpfm_inbox_actions() {
	if ( ! isset( $_GET['page'] ) || 'fan-mail' !== $_GET['page'] || ! current_user_can( IH247_WPFM_CAPABILITY ) ) {
		return;
	}
	global $wpdb;
	$messages = ih247_wpfm_table_messages();
	$back     = admin_url( 'admin.php?page=fan-mail' );

	// Bulk (POST).
	if ( isset( $_POST['ihfm_bulk'], $_POST['ihfm_ids'] ) && check_admin_referer( 'ih247_wpfm_bulk' ) ) {
		$ids = array_filter( array_map( 'absint', (array) $_POST['ihfm_ids'] ) );
		$act = sanitize_key( wp_unslash( $_POST['ihfm_bulk'] ) );
		if ( $ids && in_array( $act, array( 'read', 'unread', 'replied', 'delete' ), true ) ) {
			$in = implode( ',', $ids );
			if ( 'delete' === $act ) {
				foreach ( (array) $wpdb->get_col( "SELECT extra FROM $messages WHERE id IN ($in) AND extra != ''" ) as $ih_extra ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ih247_wpfm_delete_files_for( $ih_extra );
				}
				$wpdb->query( "DELETE FROM $messages WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$status = 'unread' === $act ? 'new' : $act;
				$wpdb->query( $wpdb->prepare( "UPDATE $messages SET status = %s WHERE id IN ($in)", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		wp_safe_redirect( $back );
		exit;
	}

	// Single (GET + nonce).
	if ( isset( $_GET['action'], $_GET['msg'], $_GET['_wpnonce'] ) ) {
		$id  = absint( $_GET['msg'] );
		$act = sanitize_key( wp_unslash( $_GET['action'] ) );
		if ( $id && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ih247_wpfm_msg_' . $id ) ) {
			if ( 'delete' === $act ) {
				ih247_wpfm_delete_files_for( $wpdb->get_var( $wpdb->prepare( "SELECT extra FROM $messages WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->delete( $messages, array( 'id' => $id ) );
				wp_safe_redirect( $back );
				exit;
			}
			if ( in_array( $act, array( 'read', 'unread', 'replied' ), true ) ) {
				$wpdb->update( $messages, array( 'status' => 'unread' === $act ? 'new' : $act ), array( 'id' => $id ) );
				wp_safe_redirect( 'replied' === $act || 'unread' === $act ? $back : add_query_arg( 'view', $id, $back ) );
				exit;
			}
		}
	}
}
add_action( 'admin_init', 'ih247_wpfm_inbox_actions' );

function ih247_wpfm_status_label( $status ) {
	$map = array(
		'new'     => __( 'New', 'wp-fan-mail' ),
		'read'    => __( 'Read', 'wp-fan-mail' ),
		'replied' => __( 'Replied', 'wp-fan-mail' ),
	);
	return isset( $map[ $status ] ) ? $map[ $status ] : $status;
}

function ih247_wpfm_inbox_page() {
	global $wpdb;
	$messages = ih247_wpfm_table_messages();
	$contacts = ih247_wpfm_table_contacts();

	// ---- Detail view. ----------------------------------------------------
	if ( isset( $_GET['view'] ) ) {
		$id  = absint( $_GET['view'] );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT m.*, c.email, c.name AS contact_name, c.phone AS contact_phone, c.message_count, c.first_seen, c.consent FROM $messages m LEFT JOIN $contacts c ON c.id = m.contact_id WHERE m.id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row && 'new' === $row->status ) {
			$wpdb->update( $messages, array( 'status' => 'read' ), array( 'id' => $id ) );
			$row->status = 'read';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fan Mail', 'wp-fan-mail' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=fan-mail' ) ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'Back to Inbox', 'wp-fan-mail' ); ?></a></h1>
			<?php if ( ! $row ) : ?>
				<p><?php esc_html_e( 'That message no longer exists.', 'wp-fan-mail' ); ?></p>
			<?php else : ?>
				<?php
				$nonce   = wp_create_nonce( 'ih247_wpfm_msg_' . $row->id );
				$self    = admin_url( 'admin.php?page=fan-mail' );
				$reply   = 'mailto:' . rawurlencode( $row->email ) . '?subject=' . rawurlencode( 'Re: ' . ( '' !== $row->subject ? $row->subject : get_bloginfo( 'name' ) ) );
				$sent_ok = 'sent' === $row->mail_status;
				?>
				<div style="max-width:820px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px 24px;">
					<h2 style="margin-top:0;"><?php echo esc_html( '' !== $row->subject ? $row->subject : __( '(no subject)', 'wp-fan-mail' ) ); ?></h2>
					<table class="form-table" role="presentation" style="margin-top:0;">
						<tr><th scope="row"><?php esc_html_e( 'From', 'wp-fan-mail' ); ?></th>
							<td><strong><?php echo esc_html( $row->contact_name ? $row->contact_name : '—' ); ?></strong>
								&lt;<a href="<?php echo esc_url( $reply ); ?>"><?php echo esc_html( $row->email ); ?></a>&gt;
								<?php if ( $row->contact_phone ) : ?> · <?php echo esc_html( $row->contact_phone ); ?><?php endif; ?>
								<p class="description"><?php echo esc_html( sprintf( __( '%1$d message(s) from this address since %2$s.', 'wp-fan-mail' ), (int) $row->message_count, mysql2date( get_option( 'date_format' ), $row->first_seen ) ) ); ?>
								<?php echo $row->consent ? esc_html__( 'Consent box was ticked.', 'wp-fan-mail' ) : ''; ?></p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Received', 'wp-fan-mail' ); ?></th>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->created_at ) ); ?>
								· <?php echo esc_html( sprintf( __( 'via form “%s”', 'wp-fan-mail' ), $row->form_slug ) ); ?>
								<?php if ( $row->page_url ) : ?> · <a href="<?php echo esc_url( $row->page_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'page', 'wp-fan-mail' ); ?></a><?php endif; ?>
								<?php if ( $row->ip ) : ?> · IP <?php echo esc_html( $row->ip ); ?><?php endif; ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Email copy', 'wp-fan-mail' ); ?></th>
							<td><?php if ( $sent_ok ) : ?>
									<span style="color:#00a32a;">✓ <?php echo esc_html( sprintf( __( 'sent via %s', 'wp-fan-mail' ), $row->carrier ) ); ?></span>
								<?php else : ?>
									<span style="color:#d63638;">✗ <?php echo esc_html( sprintf( __( '%1$s via %2$s', 'wp-fan-mail' ), $row->mail_status, $row->carrier ) ); ?></span>
									<?php if ( $row->mail_error ) : ?><p class="description"><?php echo esc_html( $row->mail_error ); ?></p><?php endif; ?>
									<p class="description"><?php esc_html_e( 'The message itself is safe right here — only the email copy had trouble.', 'wp-fan-mail' ); ?></p>
								<?php endif; ?></td></tr>
						<?php
						$extra_data = ! empty( $row->extra ) ? json_decode( (string) $row->extra, true ) : array();
						if ( is_array( $extra_data ) && $extra_data ) :
							foreach ( $extra_data as $xk => $xv ) :
								?>
						<tr><th scope="row"><?php echo esc_html( $xk ); ?></th><td style="white-space:pre-wrap;"><?php
						if ( is_string( $xv ) && preg_match( '#^https?://#', $xv ) ) {
							echo '<a href="' . esc_url( $xv ) . '" target="_blank" rel="noopener">' . esc_html( wp_basename( $xv ) ) . '</a>'; // uploaded file
						} else {
							echo esc_html( $xv );
						}
						?></td></tr>
								<?php
							endforeach;
						endif;
						?>
					</table>
					<div style="border-top:1px solid #dcdcde;padding-top:16px;white-space:pre-wrap;font-size:14px;line-height:1.6;"><?php echo esc_html( $row->message ); ?></div>
				</div>
				<p style="margin-top:16px;">
					<a class="button button-primary" href="<?php echo esc_url( $reply ); ?>">✉ <?php esc_html_e( 'Reply by email', 'wp-fan-mail' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( $self . '&action=replied&msg=' . $row->id, 'ih247_wpfm_msg_' . $row->id ) ); ?>"><?php esc_html_e( 'Mark replied', 'wp-fan-mail' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( $self . '&action=unread&msg=' . $row->id, 'ih247_wpfm_msg_' . $row->id ) ); ?>"><?php esc_html_e( 'Mark unread', 'wp-fan-mail' ); ?></a>
					<a class="button" style="color:#d63638;" onclick="return confirm('<?php echo esc_js( __( 'Delete this message permanently?', 'wp-fan-mail' ) ); ?>');" href="<?php echo esc_url( wp_nonce_url( $self . '&action=delete&msg=' . $row->id, 'ih247_wpfm_msg_' . $row->id ) ); ?>"><?php esc_html_e( 'Delete', 'wp-fan-mail' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return;
	}

	// ---- List view. ------------------------------------------------------
	$form_filter   = isset( $_GET['form'] ) ? sanitize_title( wp_unslash( $_GET['form'] ) ) : '';
	$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page      = 20;

	$where  = array( '1=1' );
	$params = array();
	if ( '' !== $form_filter ) {
		$where[]  = 'm.form_slug = %s';
		$params[] = $form_filter;
	}
	if ( in_array( $status_filter, array( 'new', 'read', 'replied' ), true ) ) {
		$where[]  = 'm.status = %s';
		$params[] = $status_filter;
	}
	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '(m.subject LIKE %s OR m.message LIKE %s OR c.email LIKE %s OR c.name LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}
	$where_sql = implode( ' AND ', $where );

	$count_sql = "SELECT COUNT(*) FROM $messages m LEFT JOIN $contacts c ON c.id = m.contact_id WHERE $where_sql";
	$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	$list_sql = "SELECT m.*, c.email, c.name AS contact_name FROM $messages m LEFT JOIN $contacts c ON c.id = m.contact_id WHERE $where_sql ORDER BY m.created_at DESC LIMIT %d OFFSET %d";
	$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	$forms = ih247_wpfm_get_forms();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Fan Mail — Inbox', 'wp-fan-mail' ); ?>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ih247_wpfm_export' ), 'ih247_wpfm_export' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'wp-fan-mail' ); ?></a></h1>

		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="fan-mail" />
			<select name="form">
				<option value=""><?php esc_html_e( 'All forms', 'wp-fan-mail' ); ?></option>
				<?php foreach ( $forms as $slug => $f ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $form_filter ); ?>><?php echo esc_html( $f['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'wp-fan-mail' ); ?></option>
				<?php foreach ( array( 'new', 'read', 'replied' ) as $st ) : ?>
					<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $st, $status_filter ); ?>><?php echo esc_html( ih247_wpfm_status_label( $st ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search messages…', 'wp-fan-mail' ); ?>" />
			<button class="button"><?php esc_html_e( 'Filter', 'wp-fan-mail' ); ?></button>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'ih247_wpfm_bulk' ); ?>
			<div class="tablenav top">
				<select name="ihfm_bulk">
					<option value=""><?php esc_html_e( 'Bulk actions', 'wp-fan-mail' ); ?></option>
					<option value="read"><?php esc_html_e( 'Mark read', 'wp-fan-mail' ); ?></option>
					<option value="unread"><?php esc_html_e( 'Mark unread', 'wp-fan-mail' ); ?></option>
					<option value="replied"><?php esc_html_e( 'Mark replied', 'wp-fan-mail' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'wp-fan-mail' ); ?></option>
				</select>
				<button class="button" onclick="return this.form.ihfm_bulk.value !== '';"><?php esc_html_e( 'Apply', 'wp-fan-mail' ); ?></button>
				<span style="margin-left:10px;color:#646970;"><?php echo esc_html( sprintf( _n( '%s message', '%s messages', $total, 'wp-fan-mail' ), number_format_i18n( $total ) ) ); ?></span>
			</div>
			<table class="widefat striped">
				<thead><tr>
					<td style="width:28px;"><input type="checkbox" onclick="this.closest('table').querySelectorAll('input[name=\'ihfm_ids[]\']').forEach(c=>c.checked=this.checked);" /></td>
					<th><?php esc_html_e( 'From', 'wp-fan-mail' ); ?></th>
					<th><?php esc_html_e( 'Message', 'wp-fan-mail' ); ?></th>
					<th><?php esc_html_e( 'Form', 'wp-fan-mail' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-fan-mail' ); ?></th>
					<th><?php esc_html_e( 'Email copy', 'wp-fan-mail' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wp-fan-mail' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No messages yet. Once your form is on a page, everything sent through it lands here.', 'wp-fan-mail' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$view  = admin_url( 'admin.php?page=fan-mail&view=' . $row->id );
						$bold  = 'new' === $row->status ? 'font-weight:600;' : '';
						$snip  = wp_trim_words( (string) $row->message, 14, '…' );
						$title = '' !== $row->subject ? $row->subject : $snip;
						?>
						<tr>
							<td><input type="checkbox" name="ihfm_ids[]" value="<?php echo esc_attr( $row->id ); ?>" /></td>
							<td style="<?php echo esc_attr( $bold ); ?>"><a href="<?php echo esc_url( $view ); ?>"><?php echo esc_html( $row->contact_name ? $row->contact_name : $row->email ); ?></a><br />
								<span style="color:#646970;font-weight:400;"><?php echo esc_html( $row->email ); ?></span></td>
							<td style="<?php echo esc_attr( $bold ); ?>"><a href="<?php echo esc_url( $view ); ?>" style="text-decoration:none;color:inherit;"><?php echo esc_html( $title ); ?></a></td>
							<td><?php echo esc_html( isset( $forms[ $row->form_slug ] ) ? $forms[ $row->form_slug ]['name'] : $row->form_slug ); ?></td>
							<td><?php echo esc_html( ih247_wpfm_status_label( $row->status ) ); ?></td>
							<td><?php if ( 'sent' === $row->mail_status ) : ?><span style="color:#00a32a;" title="<?php echo esc_attr( $row->carrier ); ?>">✓</span>
								<?php else : ?><span style="color:#d63638;" title="<?php echo esc_attr( $row->mail_error ? $row->mail_error : $row->mail_status ); ?>">✗</span><?php endif; ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->created_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</form>

		<?php
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'total'   => $pages,
						'current' => $paged,
					)
				)
			);
			echo '</div></div>';
		}
		?>
	</div>
	<?php
}

/** CSV export — the whole inbox, contacts joined, ready for a spreadsheet or CRM import. */
function ih247_wpfm_export_csv() {
	if ( ! current_user_can( IH247_WPFM_CAPABILITY ) || ! check_admin_referer( 'ih247_wpfm_export' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'wp-fan-mail' ) );
	}
	global $wpdb;
	$messages = ih247_wpfm_table_messages();
	$contacts = ih247_wpfm_table_contacts();
	$rows     = $wpdb->get_results( "SELECT m.created_at, c.name, c.email, c.phone, m.form_slug, m.subject, m.message, m.extra, m.status, m.mail_status, m.carrier, m.page_url, m.ip, c.consent FROM $messages m LEFT JOIN $contacts c ON c.id = m.contact_id ORDER BY m.created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=fan-mail-' . gmdate( 'Y-m-d' ) . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'received', 'name', 'email', 'phone', 'form', 'subject', 'message', 'extra_fields', 'status', 'mail_status', 'carrier', 'page_url', 'ip', 'consent' ) );
	foreach ( (array) $rows as $row ) {
		fputcsv( $out, $row );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_post_ih247_wpfm_export', 'ih247_wpfm_export_csv' );

/* -------------------------------------------------------------------------
 * Forms admin — name a form in plain words and it answers to that name
 * everywhere: "Contact us" becomes [fan-mail form="contact-us"] and
 * {fan-mail-contact-us}. Live preview on the right, like the sibling
 * WP Intermission — see the result while you type, save when it's right.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_forms_actions() {
	if ( ! isset( $_GET['page'] ) || 'fan-mail-forms' !== $_GET['page'] || ! current_user_can( IH247_WPFM_CAPABILITY ) ) {
		return;
	}
	$base = admin_url( 'admin.php?page=fan-mail-forms' );

	// Create.
	if ( isset( $_POST['ihfm_new_name'] ) && check_admin_referer( 'ih247_wpfm_forms' ) ) {
		$forms = ih247_wpfm_get_forms();
		$name  = sanitize_text_field( wp_unslash( $_POST['ihfm_new_name'] ) );
		if ( '' === trim( $name ) ) {
			$name = __( 'New form', 'wp-fan-mail' );
		}
		$slug         = ih247_wpfm_unique_slug( $name, $forms );
		$form         = ih247_wpfm_form_defaults();
		$form['name'] = $name;
		// New forms start with a blank field list — which fields exist is the
		// user's call (the activation-time Contact form is the prepopulated
		// exception). Saving enforces adding at least one.
		$forms[ $slug ] = $form;
		update_option( IH247_WPFM_OPT_FORMS, $forms );
		wp_safe_redirect( add_query_arg( 'form', $slug, $base ) );
		exit;
	}

	// Save.
	if ( isset( $_POST['ihfm_slug'], $_POST['f'] ) && check_admin_referer( 'ih247_wpfm_forms' ) ) {
		$forms = ih247_wpfm_get_forms();
		$slug  = sanitize_title( wp_unslash( $_POST['ihfm_slug'] ) );
		if ( isset( $forms[ $slug ] ) ) {
			$clean = ih247_wpfm_sanitize_form( wp_unslash( $_POST['f'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized field-by-field inside
			if ( ! ih247_wpfm_form_has_fields( $clean ) ) {
				// Minimum one field — a form with nothing to fill in isn't a
				// form. Nothing is saved; the editor shows what to do.
				wp_safe_redirect( add_query_arg( array( 'form' => $slug, 'nofields' => 1 ), $base ) );
				exit;
			}
			$forms[ $slug ] = $clean;
			update_option( IH247_WPFM_OPT_FORMS, $forms );
		}
		wp_safe_redirect( add_query_arg( array( 'form' => $slug, 'updated' => 1 ), $base ) );
		exit;
	}

	// Delete.
	if ( isset( $_GET['action'], $_GET['form'], $_GET['_wpnonce'] ) && 'delete' === $_GET['action'] ) {
		$slug = sanitize_title( wp_unslash( $_GET['form'] ) );
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ih247_wpfm_del_' . $slug ) ) {
			$forms = ih247_wpfm_get_forms();
			unset( $forms[ $slug ] );
			update_option( IH247_WPFM_OPT_FORMS, $forms );
		}
		wp_safe_redirect( $base );
		exit;
	}
}
add_action( 'admin_init', 'ih247_wpfm_forms_actions' );

/** Live-preview AJAX: renders unsaved editor fields into a mini page. */
function ih247_wpfm_form_preview_ajax() {
	check_ajax_referer( 'ih247_wpfm_admin', 'nonce' );
	if ( ! current_user_can( IH247_WPFM_CAPABILITY ) ) {
		wp_die( '' );
	}
	$form         = ih247_wpfm_sanitize_form( isset( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$form['slug'] = 'preview';
	$bg           = isset( $_POST['bg'] ) && 'dark' === $_POST['bg'] ? 'dark' : 'light';
	$body_bg      = 'dark' === $bg
		? 'radial-gradient(circle at 50% 35%, #0a2540 0%, #000510 70%)'
		: '#f0f0f1';
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>html{background:' . $body_bg . ';}body{margin:0;padding:28px 16px;box-sizing:border-box;background:' . $body_bg . ';}</style></head><body>' // phpcs:ignore WordPress.Security.EscapeOutput
		. ih247_wpfm_form_html( $form, array( 'preview' => true ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- built from sanitized parts
		. '</body></html>';
	wp_die();
}
add_action( 'wp_ajax_ih247_wpfm_form_preview', 'ih247_wpfm_form_preview_ajax' );

/** One field row in the form editor. $all = every row (for the rule dropdown). */
function ih247_wpfm_cf_row( $i, $cf, $all = array() ) {
	$cf        = ih247_wpfm_normalize_row( $cf );
	$show_opts = in_array( $cf['type'], array( 'select', 'radio', 'checkboxes', 'likert' ), true );
	if ( '' === $cf['key'] ) {
		$cf['key'] = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 ); // persisted on next save
	}
	?>
	<div class="ihfm-cf" style="border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;margin-bottom:8px;background:#fafafa;">
		<input type="hidden" class="ihfm-cf-key" name="f[fields][<?php echo (int) $i; ?>][key]" value="<?php echo esc_attr( $cf['key'] ); ?>" />
		<input type="text" class="ihfm-cf-label" name="f[fields][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $cf['label'] ); ?>" placeholder="<?php esc_attr_e( 'Field label — e.g. Budget, Preferred date…', 'wp-fan-mail' ); ?>" style="width:30%;" />
		<select name="f[fields][<?php echo (int) $i; ?>][type]" class="ihfm-cf-type">
			<?php foreach ( ih247_wpfm_field_types() as $tk => $tl ) : ?>
				<option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $tk, $cf['type'] ); ?>><?php echo esc_html( $tl ); ?></option>
			<?php endforeach; ?>
		</select>
		<label style="margin-left:6px;"><input type="checkbox" name="f[fields][<?php echo (int) $i; ?>][required]" value="1" <?php checked( ! empty( $cf['required'] ) ); ?> /> <?php esc_html_e( 'required', 'wp-fan-mail' ); ?></label>
		<label style="margin-left:6px;"><input type="checkbox" name="f[fields][<?php echo (int) $i; ?>][notes]" value="1" <?php checked( ! empty( $cf['notes'] ) ); ?> /> <?php esc_html_e( 'notes', 'wp-fan-mail' ); ?></label>
		<label style="margin-left:6px;"><input type="checkbox" name="f[fields][<?php echo (int) $i; ?>][color_use]" value="1" <?php checked( ! empty( $cf['color'] ) ); ?> /> <?php esc_html_e( 'color', 'wp-fan-mail' ); ?></label>
		<input type="color" name="f[fields][<?php echo (int) $i; ?>][color]" value="<?php echo esc_attr( ! empty( $cf['color'] ) ? $cf['color'] : '#ffffff' ); ?>" style="width:30px;height:22px;padding:0;vertical-align:middle;border:1px solid #c3c4c7;" />
		<span style="float:right;">
			<button type="button" class="button button-small ihfm-cf-up" title="<?php esc_attr_e( 'Move up', 'wp-fan-mail' ); ?>">▲</button>
			<button type="button" class="button button-small ihfm-cf-down" title="<?php esc_attr_e( 'Move down', 'wp-fan-mail' ); ?>">▼</button>
			<button type="button" class="button button-small ihfm-cf-del" title="<?php esc_attr_e( 'Remove', 'wp-fan-mail' ); ?>" style="color:#d63638;">✕</button>
		</span>
		<textarea name="f[fields][<?php echo (int) $i; ?>][options]" class="ihfm-cf-options" rows="3" placeholder="<?php esc_attr_e( 'One choice per line', 'wp-fan-mail' ); ?>" style="width:100%;margin-top:8px;<?php echo $show_opts ? '' : 'display:none;'; ?>"><?php echo esc_textarea( $cf['options'] ); ?></textarea>
		<div style="margin-top:8px;font-size:12.5px;color:#50575e;">
			<?php esc_html_e( 'Show:', 'wp-fan-mail' ); ?>
			<select name="f[fields][<?php echo (int) $i; ?>][cond_key]" class="ihfm-cf-condkey">
				<option value=""><?php esc_html_e( 'Always', 'wp-fan-mail' ); ?></option>
				<?php foreach ( (array) $all as $other ) : ?>
					<?php
					$other = ih247_wpfm_normalize_row( $other );
					if ( '' === $other['key'] || $other['key'] === $cf['key'] || '' === $other['label'] ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $other['key'] ); ?>" <?php selected( $other['key'], $cf['cond_key'] ); ?>><?php echo esc_html( sprintf( __( 'when “%s”', 'wp-fan-mail' ), $other['label'] ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="ihfm-cf-condextra" <?php echo '' === $cf['cond_key'] ? 'style="display:none;"' : ''; ?>>
				<select name="f[fields][<?php echo (int) $i; ?>][cond_op]">
					<option value="is" <?php selected( 'is', $cf['cond_op'] ); ?>><?php esc_html_e( 'is', 'wp-fan-mail' ); ?></option>
					<option value="not" <?php selected( 'not', $cf['cond_op'] ); ?>><?php esc_html_e( 'is not', 'wp-fan-mail' ); ?></option>
				</select>
				<input type="text" name="f[fields][<?php echo (int) $i; ?>][cond_val]" value="<?php echo esc_attr( $cf['cond_val'] ); ?>" placeholder="<?php esc_attr_e( 'value — e.g. Yes', 'wp-fan-mail' ); ?>" style="width:140px;" />
			</span>
		</div>
	</div>
	<?php
}

function ih247_wpfm_forms_page() {
	$forms = ih247_wpfm_get_forms();
	$edit  = isset( $_GET['form'] ) ? sanitize_title( wp_unslash( $_GET['form'] ) ) : '';

	// ---- Editor. ---------------------------------------------------------
	if ( '' !== $edit && isset( $forms[ $edit ] ) ) {
		$f     = $forms[ $edit ];
		$base  = admin_url( 'admin.php?page=fan-mail-forms' );
		$token = '{fan-mail-' . $edit . '}';
		$sc    = '[fan-mail form="' . $edit . '"]';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( __( 'Fan Mail — %s', 'wp-fan-mail' ), $f['name'] ) ); ?>
				<a href="<?php echo esc_url( $base ); ?>" class="page-title-action">&larr; <?php esc_html_e( 'All forms', 'wp-fan-mail' ); ?></a></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form saved.', 'wp-fan-mail' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['nofields'] ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Not saved — add at least one field. A form with nothing to fill in isn\'t a form: press "+ Add a field", give it a label, and Save again.', 'wp-fan-mail' ); ?></p></div>
			<?php endif; ?>

			<div style="display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap;">
				<form method="post" id="ihfm-editor" style="flex:1 1 480px;min-width:380px;max-width:640px;">
					<?php wp_nonce_field( 'ih247_wpfm_forms' ); ?>
					<input type="hidden" name="ihfm_slug" value="<?php echo esc_attr( $edit ); ?>" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Form name', 'wp-fan-mail' ); ?></th>
							<td><input type="text" class="regular-text ihfm-e" name="f[name]" value="<?php echo esc_attr( $f['name'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Put the form anywhere with either of these:', 'wp-fan-mail' ); ?><br />
									<code><?php echo esc_html( $sc ); ?></code> <?php esc_html_e( 'in a page or post', 'wp-fan-mail' ); ?> ·
									<code><?php echo esc_html( $token ); ?></code> <?php esc_html_e( 'in raw HTML — including a WP Intermission maintenance page', 'wp-fan-mail' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Intro text', 'wp-fan-mail' ); ?></th>
							<td><textarea class="large-text ihfm-e" rows="2" name="f[intro]" placeholder="<?php esc_attr_e( 'Optional — a friendly line above the fields.', 'wp-fan-mail' ); ?>"><?php echo esc_textarea( $f['intro'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Fields', 'wp-fan-mail' ); ?></th>
							<td>
								<?php // Nothing is hard-baked: every field on the form lives in this one list — add, label, reorder, require, color. ?>
								<div id="ihfm-cf-list">
									<?php foreach ( (array) $f['fields'] as $i => $cf ) : ?>
										<?php ih247_wpfm_cf_row( $i, $cf, $f['fields'] ); ?>
									<?php endforeach; ?>
								</div>
								<p><button type="button" class="button" id="ihfm-cf-add">+ <?php esc_html_e( 'Add a field', 'wp-fan-mail' ); ?></button></p>
								<p class="description"><?php esc_html_e( 'Every field is one of these rows — nothing is baked in. Pick at least one (saving enforces it). Name, Email, Phone, Subject, and Message are smart types: Email enables replies and checks itself as visitors type, Phone auto-heals to international +format, Subject titles the notification, Message is the body — most forms want at least Email and Message. Then the building blocks: dropdown, multiple choice, checkbox, number, date, website, star rating, paragraph, short text. Reorder with ▲▼; a field with an empty label is removed on save; every answer is saved with the message and included in the notification email.', 'wp-fan-mail' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Consent checkbox', 'wp-fan-mail' ); ?></th>
							<td><label><input type="checkbox" class="ihfm-e" name="f[consent_on]" value="1" <?php checked( ! empty( $f['consent_on'] ) ); ?> /> <?php esc_html_e( 'Ask visitors to agree before sending (GDPR-friendly)', 'wp-fan-mail' ); ?></label><br />
								<input type="text" class="large-text ihfm-e" name="f[consent_text]" value="<?php echo esc_attr( $f['consent_text'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Button label', 'wp-fan-mail' ); ?></th>
							<td><input type="text" class="regular-text ihfm-e" name="f[button]" value="<?php echo esc_attr( $f['button'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Thank-you message', 'wp-fan-mail' ); ?></th>
							<td><textarea class="large-text ihfm-e" rows="2" name="f[success]"><?php echo esc_textarea( $f['success'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Send notifications to', 'wp-fan-mail' ); ?></th>
							<td><input type="text" class="regular-text" name="f[recipients]" value="<?php echo esc_attr( $f['recipients'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Comma-separated email addresses. Empty = the site admin email. Every message is saved to the Inbox regardless.', 'wp-fan-mail' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Look', 'wp-fan-mail' ); ?></th>
							<td>
								<p style="margin-top:0;">
									<label><?php esc_html_e( 'Accent', 'wp-fan-mail' ); ?> <input type="color" class="ihfm-e" name="f[accent]" value="<?php echo esc_attr( $f['accent'] ); ?>" /></label>
									&nbsp;&nbsp;<label><?php esc_html_e( 'Theme', 'wp-fan-mail' ); ?>
										<select class="ihfm-e" name="f[theme]">
											<option value="auto" <?php selected( 'auto', $f['theme'] ); ?>><?php esc_html_e( 'Auto — follow the visitor\'s light/dark preference', 'wp-fan-mail' ); ?></option>
											<option value="light" <?php selected( 'light', $f['theme'] ); ?>><?php esc_html_e( 'Light card', 'wp-fan-mail' ); ?></option>
											<option value="dark" <?php selected( 'dark', $f['theme'] ); ?>><?php esc_html_e( 'Dark card — right at home on a WP Intermission page', 'wp-fan-mail' ); ?></option>
										</select></label>
								</p>
								<p>
									<label><?php esc_html_e( 'Card background', 'wp-fan-mail' ); ?>
										<select class="ihfm-e" name="f[bg_mode]">
											<option value="theme" <?php selected( 'theme', $f['bg_mode'] ); ?>><?php esc_html_e( 'Follow theme', 'wp-fan-mail' ); ?></option>
											<option value="custom" <?php selected( 'custom', $f['bg_mode'] ); ?>><?php esc_html_e( 'Custom color', 'wp-fan-mail' ); ?></option>
											<option value="clear" <?php selected( 'clear', $f['bg_mode'] ); ?>><?php esc_html_e( 'Transparent — the page behind shows through', 'wp-fan-mail' ); ?></option>
										</select></label>
									<input type="color" class="ihfm-e" name="f[bg_color]" value="<?php echo esc_attr( $f['bg_color'] ); ?>" />
								</p>
								<p>
									<label><?php esc_html_e( 'Field background', 'wp-fan-mail' ); ?>
										<select class="ihfm-e" name="f[field_mode]">
											<option value="theme" <?php selected( 'theme', $f['field_mode'] ); ?>><?php esc_html_e( 'Follow theme', 'wp-fan-mail' ); ?></option>
											<option value="custom" <?php selected( 'custom', $f['field_mode'] ); ?>><?php esc_html_e( 'Custom color', 'wp-fan-mail' ); ?></option>
										</select></label>
									<input type="color" class="ihfm-e" name="f[field_color]" value="<?php echo esc_attr( $f['field_color'] ); ?>" />
								</p>
								<p>
									<label><?php esc_html_e( 'Text color', 'wp-fan-mail' ); ?>
										<select class="ihfm-e" name="f[ink_mode]">
											<option value="auto" <?php selected( 'auto', $f['ink_mode'] ); ?>><?php esc_html_e( 'Auto — follow theme', 'wp-fan-mail' ); ?></option>
											<option value="custom" <?php selected( 'custom', $f['ink_mode'] ); ?>><?php esc_html_e( 'Custom color', 'wp-fan-mail' ); ?></option>
										</select></label>
									<input type="color" class="ihfm-e" name="f[ink_color]" value="<?php echo esc_attr( $f['ink_color'] ); ?>" />
								</p>
								<p class="description"><?php esc_html_e( 'Custom colors override the light/dark theme. Transparent card = pure passthrough for pages that already bring their own background (an Intermission page, a styled section). Check both preview backgrounds after changing colors.', 'wp-fan-mail' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Under the hood', 'wp-fan-mail' ); ?></th>
							<td><details <?php echo '' !== trim( (string) $f['css'] ) ? 'open' : ''; ?>>
									<summary style="cursor:pointer;"><?php esc_html_e( 'Custom CSS for this form (optional)', 'wp-fan-mail' ); ?></summary>
									<textarea class="large-text code ihfm-e" rows="6" name="f[css]" placeholder=".ihfm-wrap { border-radius: 0; }"><?php echo esc_textarea( $f['css'] ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Printed inside the form\'s wrapper. Every element carries an .ihfm- class; developers also get the ihelp247_wp_fan_mail_form_html filter for full control.', 'wp-fan-mail' ); ?></p>
								</details></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save form', 'wp-fan-mail' ) ); ?>
				</form>

				<?php // Sticky preview — rides along as you scroll the editor, like Intermission's. ?>
				<div style="flex:1 1 420px;min-width:360px;position:sticky;top:46px;align-self:flex-start;">
					<p style="margin:8px 0 6px;">
						<strong><?php esc_html_e( 'Live preview', 'wp-fan-mail' ); ?></strong> —
						<button type="button" class="button button-small button-primary" id="ihfm-bg-light"><?php esc_html_e( 'Light page', 'wp-fan-mail' ); ?></button>
						<button type="button" class="button button-small" id="ihfm-bg-dark"><?php esc_html_e( 'Dark page', 'wp-fan-mail' ); ?></button>
					</p>
					<iframe id="ihfm-preview" style="width:100%;height:480px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;"></iframe>
					<p class="description"><?php esc_html_e( 'Unsaved edits appear instantly; nothing goes live until you Save.', 'wp-fan-mail' ); ?></p>
				</div>
			</div>

			<script>
			(function(){
				var ed = document.getElementById('ihfm-editor');
				var bg = 'light', t = null;

				/* Live preview — the whole editor form (builder rows included)
				   is serialized as-is, so what renders is exactly what Save
				   would produce. */
				function paint(){
					clearTimeout(t);
					t = setTimeout(function(){
						var data = new FormData(ed);
						data.append('action', 'ih247_wpfm_form_preview');
						data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'ih247_wpfm_admin' ) ); ?>');
						data.append('bg', bg);
						fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
							.then(function(r){ return r.text(); })
							.then(function(html){ document.getElementById('ihfm-preview').srcdoc = html; });
					}, 300);
				}
				ed.addEventListener('input', paint);
				ed.addEventListener('change', paint);

				/* Preview sizes itself to its content — grows as fields are
				   added (no page scrolling to see the bottom of the form)
				   until it hits the viewport, then the preview itself
				   scrolls. Recomputed on every render and window resize. */
				var pv = document.getElementById('ihfm-preview');
				function fitPreview(){
					try {
						var doc = pv.contentDocument;
						if (!doc || !doc.documentElement) { return; }
						var h   = doc.documentElement.scrollHeight;
						var max = Math.max(360, window.innerHeight - 150);
						pv.style.height = Math.max(320, Math.min(h + 6, max)) + 'px';
					} catch (err) {}
				}
				pv.addEventListener('load', fitPreview);
				window.addEventListener('resize', fitPreview);

				document.getElementById('ihfm-bg-light').addEventListener('click', function(){ bg='light'; this.classList.add('button-primary'); document.getElementById('ihfm-bg-dark').classList.remove('button-primary'); paint(); });
				document.getElementById('ihfm-bg-dark').addEventListener('click', function(){ bg='dark'; this.classList.add('button-primary'); document.getElementById('ihfm-bg-light').classList.remove('button-primary'); paint(); });

				/* Extra-fields builder. Rows keep their index; the server walks
				   them in DOM order and reindexes on save, so reordering and
				   deleting need no renumbering here. */
				var list = document.getElementById('ihfm-cf-list');
				var cfNext = <?php echo (int) count( $f['fields'] ); ?>;
				var cfTypes = <?php echo wp_json_encode( ih247_wpfm_field_types() ); ?>;

				var OPT_TYPES = ['select', 'radio', 'checkboxes', 'likert']; // types with an options list

				document.getElementById('ihfm-cf-add').addEventListener('click', function(){
					var i = cfNext++;
					var key = Math.random().toString(36).slice(2, 10);
					var opts = '';
					for (var k in cfTypes) { opts += '<option value="' + k + '">' + cfTypes[k] + '</option>'; }
					var d = document.createElement('div');
					d.className = 'ihfm-cf';
					d.style.cssText = 'border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;margin-bottom:8px;background:#fafafa;';
					d.innerHTML = '<input type="hidden" class="ihfm-cf-key" name="f[fields][' + i + '][key]" value="' + key + '" />'
						+ '<input type="text" class="ihfm-cf-label" name="f[fields][' + i + '][label]" placeholder="<?php echo esc_js( __( 'Field label — e.g. Budget, Preferred date…', 'wp-fan-mail' ) ); ?>" style="width:30%;" />'
						+ ' <select name="f[fields][' + i + '][type]" class="ihfm-cf-type">' + opts + '</select>'
						+ ' <label style="margin-left:6px;"><input type="checkbox" name="f[fields][' + i + '][required]" value="1" /> <?php echo esc_js( __( 'required', 'wp-fan-mail' ) ); ?></label>'
						+ ' <label style="margin-left:6px;"><input type="checkbox" name="f[fields][' + i + '][notes]" value="1" /> <?php echo esc_js( __( 'notes', 'wp-fan-mail' ) ); ?></label>'
						+ ' <label style="margin-left:6px;"><input type="checkbox" name="f[fields][' + i + '][color_use]" value="1" /> <?php echo esc_js( __( 'color', 'wp-fan-mail' ) ); ?></label>'
						+ ' <input type="color" name="f[fields][' + i + '][color]" value="#ffffff" style="width:30px;height:22px;padding:0;vertical-align:middle;border:1px solid #c3c4c7;" />'
						+ '<span style="float:right;">'
						+ '<button type="button" class="button button-small ihfm-cf-up" title="<?php echo esc_js( __( 'Move up', 'wp-fan-mail' ) ); ?>">▲</button> '
						+ '<button type="button" class="button button-small ihfm-cf-down" title="<?php echo esc_js( __( 'Move down', 'wp-fan-mail' ) ); ?>">▼</button> '
						+ '<button type="button" class="button button-small ihfm-cf-del" title="<?php echo esc_js( __( 'Remove', 'wp-fan-mail' ) ); ?>" style="color:#d63638;">✕</button>'
						+ '</span>'
						+ '<textarea name="f[fields][' + i + '][options]" class="ihfm-cf-options" rows="3" placeholder="<?php echo esc_js( __( 'One choice per line', 'wp-fan-mail' ) ); ?>" style="width:100%;margin-top:8px;display:none;"></textarea>'
						+ '<div style="margin-top:8px;font-size:12.5px;color:#50575e;"><?php echo esc_js( __( 'Show:', 'wp-fan-mail' ) ); ?> '
						+ '<select name="f[fields][' + i + '][cond_key]" class="ihfm-cf-condkey"><option value=""><?php echo esc_js( __( 'Always', 'wp-fan-mail' ) ); ?></option></select> '
						+ '<span class="ihfm-cf-condextra" style="display:none;">'
						+ '<select name="f[fields][' + i + '][cond_op]"><option value="is"><?php echo esc_js( __( 'is', 'wp-fan-mail' ) ); ?></option><option value="not"><?php echo esc_js( __( 'is not', 'wp-fan-mail' ) ); ?></option></select> '
						+ '<input type="text" name="f[fields][' + i + '][cond_val]" placeholder="<?php echo esc_js( __( 'value — e.g. Yes', 'wp-fan-mail' ) ); ?>" style="width:140px;" />'
						+ '</span></div>';
					list.appendChild(d);
					d.querySelector('.ihfm-cf-type').value = 'text'; // sensible default; smart types are a deliberate pick
					d.querySelector('.ihfm-cf-label').focus();
					paint();
				});

				list.addEventListener('click', function(e){
					var row = e.target.closest ? e.target.closest('.ihfm-cf') : null;
					if (!row) { return; }
					if (e.target.classList.contains('ihfm-cf-del')) { row.parentNode.removeChild(row); paint(); }
					else if (e.target.classList.contains('ihfm-cf-up') && row.previousElementSibling) { list.insertBefore(row, row.previousElementSibling); paint(); }
					else if (e.target.classList.contains('ihfm-cf-down') && row.nextElementSibling) { list.insertBefore(row.nextElementSibling, row); paint(); }
				});
				list.addEventListener('change', function(e){
					if (e.target.classList.contains('ihfm-cf-type')) {
						var ta = e.target.closest('.ihfm-cf').querySelector('.ihfm-cf-options');
						ta.style.display = (OPT_TYPES.indexOf(e.target.value) !== -1) ? '' : 'none';
					}
					if (e.target.classList.contains('ihfm-cf-condkey')) {
						e.target.closest('.ihfm-cf').querySelector('.ihfm-cf-condextra').style.display = e.target.value ? '' : 'none';
					}
				});
				/* Rule dropdowns list the OTHER rows by label — rebuilt whenever
				   one gains focus, so new/renamed/reordered fields show up. */
				list.addEventListener('focusin', function(e){
					if (!e.target.classList.contains('ihfm-cf-condkey')) { return; }
					var sel = e.target, own = sel.closest('.ihfm-cf'), cur = sel.value;
					var html = '<option value=""><?php echo esc_js( __( 'Always', 'wp-fan-mail' ) ); ?></option>';
					list.querySelectorAll('.ihfm-cf').forEach(function(row){
						if (row === own) { return; }
						var k = row.querySelector('.ihfm-cf-key'), l = row.querySelector('.ihfm-cf-label');
						if (k && l && k.value && l.value.trim()) {
							html += '<option value="' + k.value + '"><?php echo esc_js( __( 'when', 'wp-fan-mail' ) ); ?> “' + l.value.replace(/</g, '&lt;') + '”</option>';
						}
					});
					sel.innerHTML = html;
					sel.value = cur;
					if (sel.value !== cur) { sel.value = ''; }
				});

				paint();
			})();
			</script>
		</div>
		<?php
		return;
	}

	// ---- List + create. --------------------------------------------------
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Fan Mail — Forms', 'wp-fan-mail' ); ?></h1>
		<table class="widefat striped" style="max-width:900px;margin-top:12px;">
			<thead><tr>
				<th><?php esc_html_e( 'Form', 'wp-fan-mail' ); ?></th>
				<th><?php esc_html_e( 'Put it on a page with', 'wp-fan-mail' ); ?></th>
				<th><?php esc_html_e( 'Notifications go to', 'wp-fan-mail' ); ?></th>
				<th style="width:120px;"></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $forms ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No forms yet — name one below and it\'s ready in seconds.', 'wp-fan-mail' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $forms as $slug => $f ) : ?>
					<?php $edit_url = admin_url( 'admin.php?page=fan-mail-forms&form=' . $slug ); ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $f['name'] ); ?></a></strong></td>
						<td><code>[fan-mail form="<?php echo esc_html( $slug ); ?>"]</code><br /><code>{fan-mail-<?php echo esc_html( $slug ); ?>}</code></td>
						<td><?php echo esc_html( '' !== $f['recipients'] ? $f['recipients'] : get_option( 'admin_email' ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'wp-fan-mail' ); ?></a>
							<a class="button button-small" style="color:#d63638;" onclick="return confirm('<?php echo esc_js( __( 'Delete this form? Messages already in the Inbox stay.', 'wp-fan-mail' ) ); ?>');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=fan-mail-forms&action=delete&form=' . $slug ), 'ih247_wpfm_del_' . $slug ) ); ?>"><?php esc_html_e( 'Delete', 'wp-fan-mail' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top:18px;">
			<?php wp_nonce_field( 'ih247_wpfm_forms' ); ?>
			<input type="text" name="ihfm_new_name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Support, Bookings, Say hello…', 'wp-fan-mail' ); ?>" />
			<button class="button button-primary"><?php esc_html_e( 'Add form', 'wp-fan-mail' ); ?></button>
			<p class="description"><?php esc_html_e( 'Name it in plain words — the shortcode and token take their name from it, so "Say hello" answers to {fan-mail-say-hello}.', 'wp-fan-mail' ); ?></p>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Settings — delivery, privacy, retention, support.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_sanitize_bool( $value ) {
	return empty( $value ) ? false : true;
}

function ih247_wpfm_sanitize_carrier( $value ) {
	$all = ih247_wpfm_carriers();
	return isset( $all[ $value ] ) ? $value : 'wp_mail';
}

function ih247_wpfm_register_settings() {
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_CARRIER, 'ih247_wpfm_sanitize_carrier' );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_SENDGRID_KEY, function ( $v ) {
		return trim( sanitize_text_field( (string) $v ) );
	} );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_FROM_NAME, 'sanitize_text_field' );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_FROM_EMAIL, 'sanitize_email' );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_STORE_IP, 'ih247_wpfm_sanitize_bool' );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_DEFAULT_CC, function ( $v ) {
		$cc = preg_replace( '/\D/', '', (string) $v );
		return '' === $cc ? '1' : substr( $cc, 0, 3 );
	} );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_MAX_UPLOAD, function ( $v ) {
		return max( 1, min( 100, absint( $v ) ? absint( $v ) : 10 ) );
	} );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_RETENTION, function ( $v ) {
		return min( 3650, absint( $v ) );
	} );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_HIDE_CREDIT, 'ih247_wpfm_sanitize_bool' );
	register_setting( 'ihelp247_wp_fan_mail', IH247_WPFM_OPT_DELETE_DATA, 'ih247_wpfm_sanitize_bool' );
}
add_action( 'admin_init', 'ih247_wpfm_register_settings' );

function ih247_wpfm_settings_page() {
	$carrier    = ih247_wpfm_carrier();
	$key_const  = defined( 'IH247_WPFM_SENDGRID_KEY' ) && IH247_WPFM_SENDGRID_KEY;
	$retention  = absint( get_option( IH247_WPFM_OPT_RETENTION, 0 ) );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Fan Mail — Settings', 'wp-fan-mail' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'ihelp247_wp_fan_mail' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Email delivery', 'wp-fan-mail' ); ?></th>
					<td>
						<?php foreach ( ih247_wpfm_carriers() as $key => $c ) : ?>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" class="ihfm-carrier" name="<?php echo esc_attr( IH247_WPFM_OPT_CARRIER ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $carrier ); ?> />
								<?php echo esc_html( $c['label'] ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Every message is saved to the Inbox before any sending happens — a mail hiccup can never lose a message. SMTP, Microsoft 365, and Google connectors are on the roadmap.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<?php
				// Carrier-specific rows: tagged with data-carrier and shown only
				// while that carrier is selected — future gateways (SMTP, M365,
				// Google) follow the same pattern, so the UI stays clean and
				// specific instead of a wall of every provider's fields.
				?>
				<tr class="ihfm-carrier-opt" data-carrier="sendgrid">
					<th scope="row"><?php esc_html_e( 'SendGrid API key', 'wp-fan-mail' ); ?></th>
					<td>
						<?php if ( $key_const ) : ?>
							<p><em><?php esc_html_e( 'Set in wp-config.php via IH247_WPFM_SENDGRID_KEY — the constant wins over this field.', 'wp-fan-mail' ); ?></em></p>
						<?php else : ?>
							<input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( IH247_WPFM_OPT_SENDGRID_KEY ); ?>" value="<?php echo esc_attr( get_option( IH247_WPFM_OPT_SENDGRID_KEY, '' ) ); ?>" />
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Create one in SendGrid under Settings → API Keys ("Mail Send" permission is enough). Your From address below must be a verified sender there. Prefer keeping keys out of the database? define IH247_WPFM_SENDGRID_KEY in wp-config.php instead.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'From', 'wp-fan-mail' ); ?></th>
					<td>
						<input type="text" class="regular-text" name="<?php echo esc_attr( IH247_WPFM_OPT_FROM_NAME ); ?>" value="<?php echo esc_attr( get_option( IH247_WPFM_OPT_FROM_NAME, '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
						<input type="email" class="regular-text" name="<?php echo esc_attr( IH247_WPFM_OPT_FROM_EMAIL ); ?>" value="<?php echo esc_attr( get_option( IH247_WPFM_OPT_FROM_EMAIL, '' ) ); ?>" placeholder="mail@<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Notifications are sent FROM this address (never the visitor\'s — that fails DMARC); replying still reaches the visitor because Reply-To carries them. Use an address on your own domain. With wp_mail, empty = your host\'s default; with SendGrid it must be a verified sender.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test delivery', 'wp-fan-mail' ); ?></th>
					<td>
						<button type="button" class="button" id="ihfm-test"><?php esc_html_e( 'Send a test email to me', 'wp-fan-mail' ); ?></button>
						<span id="ihfm-test-result" style="margin-left:8px;"></span>
						<p class="description"><?php esc_html_e( 'Uses the saved settings above — press Save Changes first if you just edited them.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Phone numbers', 'wp-fan-mail' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Default country code', 'wp-fan-mail' ); ?>
							+<input type="text" inputmode="numeric" maxlength="3" style="width:56px;" name="<?php echo esc_attr( IH247_WPFM_OPT_DEFAULT_CC ); ?>" value="<?php echo esc_attr( ih247_wpfm_default_cc() ); ?>" /></label>
						<p class="description"><?php esc_html_e( 'Phone fields auto-heal what visitors type into international +format (E.164): "(555) 123-4567" becomes +15551234567. A number typed without a country code is assumed to be from this country — 1 for US/Canada, 44 for UK, 61 for Australia, and so on. Implausible numbers are flagged to the visitor as they type, and healed again server-side.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uploads', 'wp-fan-mail' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Max file size', 'wp-fan-mail' ); ?>
							<input type="number" min="1" max="100" style="width:70px;" name="<?php echo esc_attr( IH247_WPFM_OPT_MAX_UPLOAD ); ?>" value="<?php echo esc_attr( ih247_wpfm_max_upload_mb() ); ?>" /> MB</label>
						<p class="description"><?php esc_html_e( 'For File fields on your forms. Accepted types: jpg, jpeg, png, gif, webp, heic, pdf — checked by real content, not just the filename. Files land in your uploads/fan-mail folder with randomized names, link from the Inbox and the notification email, and are deleted with their message (including retention sweeps and privacy erasures). Your host\'s PHP limits still apply on top.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Privacy', 'wp-fan-mail' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( IH247_WPFM_OPT_STORE_IP ); ?>" value="1" <?php checked( (bool) get_option( IH247_WPFM_OPT_STORE_IP, false ) ); ?> />
							<?php esc_html_e( 'Store the sender\'s IP address with each message (off = GDPR-friendliest; rate limiting works either way)', 'wp-fan-mail' ); ?></label>
						<p style="margin-top:10px;">
							<label><?php esc_html_e( 'Auto-delete messages older than', 'wp-fan-mail' ); ?>
								<input type="number" min="0" max="3650" style="width:80px;" name="<?php echo esc_attr( IH247_WPFM_OPT_RETENTION ); ?>" value="<?php echo esc_attr( $retention ); ?>" />
								<?php esc_html_e( 'days (0 = keep forever)', 'wp-fan-mail' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'Submissions belong to you and live in this site\'s database only — nothing is ever sent to iHelp247. WordPress\'s built-in privacy tools (Export / Erase Personal Data) cover Fan Mail messages automatically.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'wp-fan-mail' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( IH247_WPFM_OPT_DELETE_DATA ); ?>" value="1" <?php checked( (bool) get_option( IH247_WPFM_OPT_DELETE_DATA, false ) ); ?> />
							<?php esc_html_e( 'Also delete all messages and contacts when the plugin is uninstalled. Off by default — your data outlives the plugin.', 'wp-fan-mail' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Help', 'wp-fan-mail' ); ?></th>
					<td>
						<a href="<?php echo esc_url( apply_filters( 'ihelp247_wp_fan_mail_docs_url', IH247_WPFM_DOCS_URL ) ); ?>" target="_blank">
							<?php esc_html_e( 'User guide & troubleshooting', 'wp-fan-mail' ); ?>
						</a>
						<p class="description"><?php esc_html_e( 'Plain-language guide with a self-diagnosis section — including why contact emails land in spam and how to fix it. A copy also ships in the plugin\'s docs/ folder, so it works offline.', 'wp-fan-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<img src="<?php echo esc_url( plugins_url( 'assets/logo-dark.svg', __FILE__ ) ); ?>" alt="<?php esc_attr_e( 'iHelp247 — Superior Technology Ecosystems', 'wp-fan-mail' ); ?>" width="152" style="max-width:100%;height:auto;" />
					</th>
					<td>
						<p style="margin-top:0;"><?php esc_html_e( 'If Fan Mail is earning its keep in your world, letting us know is what keeps us building — a quick review, or an energy drink. Any amount, one-time or monthly, goes straight to feeding the developers\' break room. No pressure; we\'re glad it\'s useful either way.', 'wp-fan-mail' ); ?></p>
						<p>
							<a class="button" href="<?php echo esc_url( IH247_WPFM_DONATE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '☕ Keep the energy drinks & coffee flowing', 'wp-fan-mail' ); ?></a>
							<a class="button" href="<?php echo esc_url( IH247_WPFM_REVIEW_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '★ Leave a review', 'wp-fan-mail' ); ?></a>
							<a class="button" href="<?php echo esc_url( IH247_WPFM_SUPPORT_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Support', 'wp-fan-mail' ); ?></a>
						</p>
						<p class="description">
							<a href="<?php echo esc_url( IH247_WPFM_PROJECTS_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'More free tools from iHelp247 →', 'wp-fan-mail' ); ?></a>
						</p>
						<p style="margin-bottom:0;">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( IH247_WPFM_OPT_HIDE_CREDIT ); ?>" value="1" <?php checked( (bool) get_option( IH247_WPFM_OPT_HIDE_CREDIT, false ) ); ?> />
								<?php esc_html_e( 'Hide the small "fan mail by iHelp247" link under the forms. Free either way — the link just helps others find the plugin.', 'wp-fan-mail' ); ?>
							</label>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<script>
		(function(){
			/* Carrier-specific rows: only the selected gateway's fields show. */
			function carrierUI(){
				var sel = document.querySelector('input.ihfm-carrier:checked');
				var v = sel ? sel.value : '';
				document.querySelectorAll('.ihfm-carrier-opt').forEach(function(row){
					row.style.display = (row.getAttribute('data-carrier') === v) ? '' : 'none';
				});
			}
			document.querySelectorAll('input.ihfm-carrier').forEach(function(r){ r.addEventListener('change', carrierUI); });
			carrierUI();

			/* Unsaved-changes guard: the test uses SAVED settings, so warn once
			   before testing with stale edits on screen — the classic trap of
			   "picked SendGrid, hit test, green light" while wp_mail was still
			   the saved carrier. */
			var form = document.querySelector('form[action="options.php"]');
			var dirty = false, warned = false;
			if (form) {
				form.addEventListener('input', function(){ dirty = true; warned = false; });
				form.addEventListener('change', function(){ dirty = true; warned = false; });
			}

			var btn = document.getElementById('ihfm-test'), out = document.getElementById('ihfm-test-result');
			btn.addEventListener('click', function(){
				if (dirty && !warned) {
					warned = true;
					out.style.color = '#b45309';
					out.textContent = '<?php echo esc_js( __( 'You have unsaved changes — the test uses the last SAVED settings. Press Save Changes first, or click Test again to test the saved ones anyway.', 'wp-fan-mail' ) ); ?>';
					return;
				}
				btn.disabled = true;
				out.textContent = '…';
				var data = new FormData();
				data.append('action', 'ih247_wpfm_test_mail');
				data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'ih247_wpfm_admin' ) ); ?>');
				fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						btn.disabled = false;
						out.style.color = (j && j.success) ? '#00a32a' : '#d63638';
						out.textContent = (j && j.data && j.data.message) ? j.data.message : '<?php echo esc_js( __( 'No answer from the server.', 'wp-fan-mail' ) ); ?>';
					})
					.catch(function(){ btn.disabled = false; out.style.color = '#d63638'; out.textContent = '<?php echo esc_js( __( 'No answer from the server.', 'wp-fan-mail' ) ); ?>'; });
			});
		})();
		</script>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * WordPress privacy tools — Fan Mail data shows up in the core
 * Export / Erase Personal Data screens, keyed by email address.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_register_exporter( $exporters ) {
	$exporters['wp-fan-mail'] = array(
		'exporter_friendly_name' => __( 'Fan Mail messages', 'wp-fan-mail' ),
		'callback'               => 'ih247_wpfm_privacy_exporter',
	);
	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'ih247_wpfm_register_exporter' );

function ih247_wpfm_privacy_exporter( $email, $page = 1 ) {
	global $wpdb;
	$messages = ih247_wpfm_table_messages();
	$contacts = ih247_wpfm_table_contacts();
	$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM $messages m INNER JOIN $contacts c ON c.id = m.contact_id WHERE c.email = %s ORDER BY m.created_at ASC", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$export = array();
	foreach ( (array) $rows as $row ) {
		$data = array(
			array( 'name' => __( 'Received', 'wp-fan-mail' ), 'value' => $row->created_at ),
			array( 'name' => __( 'Form', 'wp-fan-mail' ), 'value' => $row->form_slug ),
			array( 'name' => __( 'Subject', 'wp-fan-mail' ), 'value' => $row->subject ),
			array( 'name' => __( 'Message', 'wp-fan-mail' ), 'value' => $row->message ),
		);
		if ( $row->ip ) {
			$data[] = array( 'name' => __( 'IP address', 'wp-fan-mail' ), 'value' => $row->ip );
		}
		$export[] = array(
			'group_id'    => 'ih247-fan-mail',
			'group_label' => __( 'Fan Mail messages', 'wp-fan-mail' ),
			'item_id'     => 'ih247-fan-mail-' . $row->id,
			'data'        => $data,
		);
	}
	return array( 'data' => $export, 'done' => true );
}

function ih247_wpfm_register_eraser( $erasers ) {
	$erasers['wp-fan-mail'] = array(
		'eraser_friendly_name' => __( 'Fan Mail messages', 'wp-fan-mail' ),
		'callback'             => 'ih247_wpfm_privacy_eraser',
	);
	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'ih247_wpfm_register_eraser' );

function ih247_wpfm_privacy_eraser( $email, $page = 1 ) {
	global $wpdb;
	$messages = ih247_wpfm_table_messages();
	$contacts = ih247_wpfm_table_contacts();
	$id       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $contacts WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$removed  = false;
	if ( $id ) {
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT extra FROM $messages WHERE contact_id = %d AND extra != ''", $id ) ) as $ih_extra ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ih247_wpfm_delete_files_for( $ih_extra );
		}
		$wpdb->delete( $messages, array( 'contact_id' => $id ) );
		$wpdb->delete( $contacts, array( 'id' => $id ) );
		$removed = true;
	}
	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

/* -------------------------------------------------------------------------
 * Retention — a daily sweep honors the auto-delete setting.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_daily_maintenance() {
	$days = absint( get_option( IH247_WPFM_OPT_RETENTION, 0 ) );
	if ( ! $days ) {
		return;
	}
	global $wpdb;
	$messages = ih247_wpfm_table_messages();
	$contacts = ih247_wpfm_table_contacts();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT extra FROM $messages WHERE created_at < %s AND extra != ''", $cutoff ) ) as $ih_extra ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ih247_wpfm_delete_files_for( $ih_extra ); // uploaded files never outlive their messages
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $messages WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Contacts with no messages left go too — no orphaned personal data.
	$wpdb->query( "DELETE c FROM $contacts c LEFT JOIN $messages m ON m.contact_id = c.id WHERE m.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
add_action( 'ih247_wpfm_daily_maintenance', 'ih247_wpfm_daily_maintenance' );

/* -------------------------------------------------------------------------
 * GitHub release updates — WordPress's native mechanism for third-party
 * update sources. Because our "Update URI" header points at github.com,
 * core fires the `update_plugins_github.com` hook during its normal update
 * checks; we answer it from the repo's latest Release. Sites then update
 * through the standard Plugins screen like any other plugin.
 *
 * NOTE for wordpress.org directory hosting: the directory serves updates
 * itself and disallows third-party update sources — if this plugin is ever
 * submitted there, remove this block and the Update URI header.
 * ---------------------------------------------------------------------- */

function ih247_wpfm_github_update( $update, $plugin_data, $plugin_file, $locales ) {
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}

	$info = get_transient( 'ih247_wpfm_gh_release' );
	if ( false === $info ) {
		$info = array( 'version' => '', 'zip' => '' );
		$resp = wp_remote_get(
			'https://api.github.com/repos/iHelp247/wp-fan-mail/releases/latest',
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wp-fan-mail/' . IH247_WPFM_VERSION,
				),
			)
		);
		if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			$tag  = isset( $body['tag_name'] ) ? ltrim( (string) $body['tag_name'], 'v' ) : '';
			$zip  = '';
			// Only a release ASSET zip is usable — GitHub's auto zipball wraps
			// the code in a "iHelp247-wp-fan-mail-<hash>" folder, which would
			// break the plugin slug on install.
			foreach ( isset( $body['assets'] ) ? (array) $body['assets'] : array() as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
					$zip = $asset['browser_download_url'];
					break;
				}
			}
			$info = array( 'version' => $tag, 'zip' => $zip );
		}
		set_transient( 'ih247_wpfm_gh_release', $info, 12 * HOUR_IN_SECONDS ); // fail-soft: cache misses too
	}

	if ( $info['version'] && $info['zip'] && version_compare( $info['version'], IH247_WPFM_VERSION, '>' ) ) {
		return array(
			'id'      => 'https://github.com/iHelp247/wp-fan-mail',
			'slug'    => 'wp-fan-mail',
			'plugin'  => $plugin_file,
			'version' => $info['version'],
			'url'     => 'https://github.com/iHelp247/wp-fan-mail',
			'package' => $info['zip'],
		);
	}
	return $update;
}
add_filter( 'update_plugins_github.com', 'ih247_wpfm_github_update', 10, 4 );

/* Quick links on the Plugins screen. */
function ih247_wpfm_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=fan-mail' ) ) . '">' . esc_html__( 'Inbox', 'wp-fan-mail' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=fan-mail-settings' ) ) . '">' . esc_html__( 'Settings', 'wp-fan-mail' ) . '</a>'
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ih247_wpfm_action_links' );

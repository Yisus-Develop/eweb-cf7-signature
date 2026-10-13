=== EWEB CF7 Signature ===
Contributors: enlaweb
Tags: contact form 7, signature, digital signature, canvas, e-signature
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a secure HTML5 signature pad to any Contact Form 7 form, with safe PNG storage, inline e-mail images and a native PDF bridge.

== Description ==

EWEB CF7 Signature adds a `[signature]` form-tag to Contact Form 7. Visitors draw their
signature with a mouse, a finger or a stylus, and the plugin stores it as a PNG, attaches it
to the e-mails and feeds it to PDF generators without ever making an HTTP request to your
own server.

**Features**

* Responsive HTML5 canvas pad. No jQuery, no external JavaScript library, no `var`.
* Signature re-rendered from its stroke data on resize, so it stays sharp on high-DPI phones.
* Tight-cropped PNG export with the background colour baked in, readable in dark e-mail clients.
* Public but sandboxed storage in `wp-content/uploads/eweb_signatures/` with valid Apache 2.4
  and IIS guards. No more 403 errors, no more 500 errors from deprecated `.htaccess` syntax.
* Strict payload validation: PNG magic bytes, MIME check and PNG chunk walk that discards
  anything appended after the `IEND` chunk.
* Four e-mail body modes: public link, Base64 data URI, native CID embedded image, or nothing.
* The PNG is attached automatically to the notification and/or the autoresponder as an
  absolute file path, so Contact Form 7 never performs a loopback HTTP request.
* Native PDF bridge: absolute local paths for mPDF, dompdf and TCPDF, plus a ready-made
  integration with "Send PDF for Contact Form 7".
* Optional retention policy (nothing is deleted unless you opt in), 9 filters, 1 action and
  documented PHP and JavaScript APIs.

**Form-tag options**

`color:`, `backcolor:`, `penwidth:`, `width:`, `height:`, `mode:link|base64|cid|none`,
`noattach`, `attachto:mail|mail_2|both`, `notrim`, `hint:`, `class:`, `id:`.

Example:

`[signature* your-signature color:#1d4ed8 backcolor:#ffffff penwidth:3 mode:cid]`

The legacy `inline` option is understood as `mode:cid`.

== Installation ==

1. Upload the `eweb-cf7-signature` folder to `/wp-content/plugins/`.
2. Activate the plugin through the *Plugins* screen. Contact Form 7 must be active.
3. Add `[signature your-signature]` (or `[signature* your-signature]`) to any contact form,
   or use the "Signature field" button of the form-tag generator.
4. Insert `[your-signature]` in the Mail tab to display the signature in the e-mail.

Activation creates `wp-content/uploads/eweb_signatures/` with its access guards.

== Frequently Asked Questions ==

= Why are the signatures stored in a public folder? =

Because e-mail clients and PDF generators have to be able to read the image. Contact Form 7
keeps its own temporary upload folder locked with "Require all denied", which is what caused
the classic HTTP 403 on signature links. This plugin uses its own folder that serves `.png`
files only and refuses to execute anything.

= Is a public folder safe? =

Only validated PNG files are ever written there, everything else is denied by the guard file,
and file names include a timestamp and six random characters. If you need confidential
signatures, keep them off the web by using `mode:cid` and filtering
`eweb_cf7_signature_upload_path` / `eweb_cf7_signature_upload_url`.

= How do I make the image appear immediately in Gmail or Outlook? =

Use the `cid` mode: `[signature your-signature mode:cid]`. The image travels inside the
message itself, so no client has to fetch anything. The `base64` mode also works but some
clients strip data URIs and very large messages can be clipped.

= Does it work with PDF plugins? =

Yes. When a PDF generator resolves the mail-tags, `[your-signature]` returns the absolute
filesystem path of the PNG. The `wpcf7pdf_text` filter of "Send PDF for Contact Form 7" is
also handled, and `EWEB_CF7_Signature_PDF_Bridge::get_local_path()` is available for custom
integrations.

= Are old signatures deleted? =

No. Retention is disabled by default. Return a number of days from the
`eweb_cf7_signature_retention_days` filter to enable daily cleanup.

= Can I use it with the old "Digital Signature For Contact Form 7" plugin? =

Yes. The form-tag name is the same and the legacy `inline` and `attachment` options are
understood, so existing forms keep working.

= What happens when Contact Form 7 is updated? =

The plugin assumes as little as possible about Contact Form 7 and checks the rest. It
verifies, on every admin request, that every function, class and method it uses still
exists, and shows a notice listing what broke and how it is being handled instead of
failing silently. Validation is registered twice (the modern SWV rule and the legacy
validate filter), the form-tag generator falls back to a legacy panel if the modern one is
absent, and the e-mail integration normalises both array and string attachments. If a
required symbol disappears, the administrator sees a red notice with the exact impact.
Developers can also run `php tests/check-cf7-contract.php <contact-form-7-dir>`, which
verifies 50 facts against the Contact Form 7 source, including the argument count of every
hook the plugin relies on.

== Changelog ==

= 1.0.0 =
* Initial release.
* `[signature]` and `[signature*]` form-tags with a modern responsive canvas pad.
* Sandboxed public storage with Apache 2.4 and IIS guards (fixes 403 and 500 errors).
* Strict PNG validation, including truncation of data appended after the IEND chunk.
* Automatic attachments through `wpcf7_mail_components` and `link`, `base64`, `cid` and
  `none` e-mail body modes with native PHPMailer embedding.
* Native PDF bridge with absolute paths and "Send PDF for Contact Form 7" integration.
* Contact Form 7 update safety: runtime compatibility verification with admin notices,
  dual (SWV + legacy) validation, legacy form-tag generator fallback and a static contract
  checker for developers.
* Optional retention cron, 12 filters, 1 action, full PHP and JavaScript APIs.

== Upgrade Notice ==

= 1.0.0 =
First public release.

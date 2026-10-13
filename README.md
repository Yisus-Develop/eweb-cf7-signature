# EWEB CF7 Signature

A clean, modern and dependency-free digital signature field for **Contact Form 7**.
Built to the *EWEB Elite / Vault Factory* standard: strict types, no bloatware, no ads,
no bundled libraries.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b?logo=wordpress&logoColor=white)
![Contact Form 7](https://img.shields.io/badge/Contact%20Form%207-5.x%20%2F%206.x-2f6f9f)
![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)
![Tests](https://img.shields.io/badge/tests-125%20assertions%20%2B%20contract%20check-success)
[![CI](https://github.com/Yisus-Develop/eweb-cf7-signature/actions/workflows/ci.yml/badge.svg)](https://github.com/Yisus-Develop/eweb-cf7-signature/actions/workflows/ci.yml)
[![Contact Form 7 contract](https://github.com/Yisus-Develop/eweb-cf7-signature/actions/workflows/cf7-contract.yml/badge.svg)](https://github.com/Yisus-Develop/eweb-cf7-signature/actions/workflows/cf7-contract.yml)

Repository: <https://github.com/Yisus-Develop/eweb-cf7-signature>

---

## Table of contents

- [Why this plugin exists](#why-this-plugin-exists)
- [Feature overview](#feature-overview)
- [Package layout](#package-layout)
- [Installation](#installation)
- [Usage](#usage)
- [Form-tag options](#form-tag-options)
- [E-mail behaviour](#e-mail-behaviour)
- [Storage and security model](#storage-and-security-model)
- [Contact Form 7 update safety](#contact-form-7-update-safety)
- [PDF generators (mPDF, dompdf…)](#pdf-generators-mpdf-dompdf)
- [Distribution and updates](#distribution-and-updates)
- [Development](#development)
- [Reference: filters](#reference-filters)
- [Reference: actions](#reference-actions)
- [Reference: PHP API](#reference-php-api)
- [Reference: JavaScript API](#reference-javascript-api)
- [Accessibility](#accessibility)
- [Troubleshooting](#troubleshooting)
- [Migrating from the legacy plugin](#migrating-from-the-legacy-plugin)
- [Development](#development)
- [Changelog](#changelog)
- [License](#license)

---

## Why this plugin exists

A rewrite of *Digital Signature for Contact Form 7*, replacing its legacy
architecture with a modular one and fixing five critical problems:

| # | Problem in the legacy implementation | How it is solved here |
|---|---|---|
| 1 | Signatures were written into `wp-content/uploads/wpcf7_uploads/`, where Contact Form 7 writes a `Require all denied` guard, so every link answered **403**. | Signatures live in their own public folder, `wp-content/uploads/eweb_signatures/`, with a valid **Apache 2.4** and **IIS** guard. |
| 2 | The guard file used the deprecated `Order deny,allow` syntax, which produces a **500** on any Apache 2.4 server without `mod_access_compat`. | `Require`-based rules with `IfModule` fallbacks for both 2.4 and 2.2. |
| 3 | Inline images required an HTTP request to the site itself. | `base64` and native `CID` modes: the image travels inside the message, no network calls, no proxies. |
| 4 | mPDF and other PDF engines received an URL and performed an HTTP loopback call. | `wpcf7pdf_text` integration and the `eweb_cf7_signature_pdf_path` filter hand over the **absolute filesystem path**. |
| 5 | Attachments were handled by mutating `$_POST`, which broke whenever the field name was reused. | `wpcf7_mail_components` appends the local file path, per mail template, with no duplication. |

It also removes every trace of upsell code (there were hard-coded links to the "Pro"
version of a third-party site in the tag generator) and replaces the legacy
`var`-based JavaScript with a modern, responsive ES6+ canvas.

---

## Feature overview

- **HTML5 canvas pad** — Pointer Events, so mouse, finger and stylus all work. No jQuery, no `signature_pad` dependency.
- **Responsive and crisp** — the drawing is stored as vector-like stroke data and re-rendered on resize, so it never becomes blurry on high-DPI phones.
- **Tight-cropped PNG export** — empty margins are removed and the background colour is painted into the file, so the image is readable in dark e-mail clients.
- **Secure storage** — payloads are validated as real PNG data (magic bytes, MIME, chunk walk) and anything appended after `IEND` is discarded before the file is written.
- **Three in-mail representations** — public link, Base64 data URI, or native CID embedded image.
- **Automatic attachments** — the signature `.png` is attached to the notification and/or the autoresponder, with no HTTP request.
- **Native PDF bridge** — absolute local paths for mPDF/dompdf/TCPDF, plus a ready-made integration with *Send PDF for Contact Form 7*.
- **Optional retention policy** — nothing is ever deleted unless you opt in.
- **Zero dependencies** — 3 PHP classes, 1 JavaScript file, 1 CSS file.

---

## Package layout

The repository root **is** the plugin folder WordPress installs, following the
*Vault Factory* deployment protocol: the local `src/` directory maps to the repository
root, and `.gitattributes` keeps every development file out of the release package.

```
eweb-cf7-signature/                    # repository root = plugin root
├── eweb-cf7-signature.php             # Bootstrap (headers, constants, activation)
├── readme.txt                         # WordPress.org style readme
├── README.md
├── LICENSE                            # GPL-2.0
├── includes/
│   ├── class-eweb-github-updater.php  # Silent update engine (GitHub releases)
│   ├── class-compat.php               # Contact Form 7 contract + early warning
│   ├── class-signature-handler.php    # Form-tag, storage, validation
│   ├── class-mail-integrator.php      # Attachments and inline images
│   └── class-pdf-bridge.php           # Absolute paths for PDF engines
├── assets/
│   ├── js/signature-pad.js            # Vanilla ES6+ canvas pad
│   ├── css/signature-pad.css
│   └── banner-772x250.png, banner-1544x500.png, icon-128x128.png, icon-256x256.png
├── languages/eweb-cf7-signature.pot
│
│   ── development only: excluded from the release ZIP ──
├── tests/
│   ├── run-tests.php                  # 125 assertions, no WordPress required
│   ├── check-cf7-contract.php         # 50 checks against a real Contact Form 7
│   └── mocks/wp.php
├── .github/workflows/
│   ├── ci.yml                         # Lint, tests, version alignment, CF7 contract
│   ├── cf7-contract.yml               # Weekly early warning against Contact Form 7
│   └── release.yml                    # Tag → clean ZIP attached to the Release
├── composer.json                      # PHPCS + WordPress Coding Standards
├── phpcs.xml.dist                     # WordPress-Core, WordPress-Extra, WordPress-Docs
├── .gitattributes                     # export-ignore list
└── .gitignore
```

`git archive` (and therefore GitHub's source archives) honours `.gitattributes`, so the
downloaded package never contains tests, CI files, Composer files or coding-standard
configuration.

## Installation

1. Copy the `eweb-cf7-signature` folder into `wp-content/plugins/`.
2. Activate **EWEB CF7 Signature** in *Plugins*. Contact Form 7 must be active.
3. Edit any contact form and use the **Signature field** button of the form-tag generator,
   or type the tag by hand:

```
[signature your-signature]
[signature* your-signature]
```

Activation creates `wp-content/uploads/eweb_signatures/` together with its
`.htaccess`, `web.config` and `index.php` guards.

---

## Usage

### Basic form

```
<label>Full name [text* your-name]</label>
<label>Signature [signature* your-signature]</label>
[submit "Send"]
```

### Mail tab

Put the mail-tag in the body to display the signature:

```
Signature: [your-signature]
```

By default the PNG is **also attached** to both e-mails, and the body contains the public
URL. If you want an image instead of a link, use the `mode:` option (see below).

### Customised tag

```
[signature* your-signature color:#1d4ed8 backcolor:#ffffff penwidth:3 width:600 height:240 mode:cid hint:"Sign above" class:my-pad]
```

The legacy syntax keeps working: `inline` is understood as `mode:cid`, and
`attachment` is still accepted for backwards compatibility.

---

## Form-tag options

| Option | Default | Description |
|---|---|---|
| `color:#rrggbb` | `#111827` | Pen colour. `#` is optional, 3- and 6-digit hex are accepted. |
| `backcolor:#rrggbb` | `#ffffff` | Pad background, painted into the exported PNG. `backcolor:transparent` keeps transparency. |
| `penwidth:2.5` | `2.5` | Maximum stroke width, clamped between `0.5` and `8`. |
| `width:600` | `600` | Canvas width, in pixels. Clamped between `200` and `2000`. |
| `height:240` | `240` | Canvas height, in pixels. Clamped between `100` and `1200`. |
| `mode:link` | `link` | How the value appears in the e-mail body: `link`, `base64`, `cid` or `none`. |
| `attach` | **on** | Kept for backwards compatibility; attachments are enabled by default. |
| `noattach` | — | Do not attach the PNG to any e-mail. |
| `attachto:both` | `both` | Which mail receives the attachment: `mail`, `mail_2` or `both`. |
| `notrim` | — | Keep the full canvas, do not crop the empty margins. |
| `hint:text` | — | Small caption rendered under the pad. Quote it when it contains spaces. |
| `class:name` | — | Extra CSS classes on the wrapper. |
| `id:name` | auto | `id` of the canvas element. |
| `inline` | — | **Legacy.** Same as `mode:cid`. |

---

## E-mail behaviour

`[your-signature]` can be rendered in four different ways. The choice depends on the
mail client and on how much weight you accept in the message.

| `mode:` | What the recipient sees | Notes |
|---|---|---|
| `link` *(default)* | The public URL of the PNG | Lightest option. The image loads over the network when the client renders it. Combined with the automatic attachment, the signature is always available. |
| `cid` | The image rendered **from inside the message** | Most reliable for desktop Outlook and Gmail. No external request at all. **Recommended** when the image must be visible immediately. |
| `base64` | The image rendered from a Base64 data URI | Fully self-contained, but some clients strip `data:` URIs and large messages get clipped (Gmail clips bodies above ~102 KB). Keep `width`/`height` modest if you use it. |
| `none` | Nothing in the body | Use it when the attachment is enough. |

Attachments are added through `wpcf7_mail_components` as **absolute file paths**, which is
why Contact Form 7 never performs an HTTP request to fetch the image.

---

## Storage and security model

```
wp-content/uploads/eweb_signatures/
├── .htaccess        # Apache: deny everything but *.png (2.4 + 2.2 syntax)
├── web.config       # IIS: <handlers accessPolicy="Read" />
├── index.php        # no directory listing
└── 42-signature-20261013-120501-ab12cd.png
```

**Why a public folder at all?** A PNG that is referenced from an e-mail or a PDF has to be
reachable over HTTP by the client. Contact Form 7's temporary upload folder is deliberately
locked down (that is the source of the classic 403), so signatures get their own folder.

**Why is that not a security problem?** Signatures are only ever written as validated PNG
files, the folder refuses to serve or execute anything else, and file names are
unguessable-ish (field slug + timestamp + 6 random characters). Even so, a public URL is a
public URL: if your use case requires confidential signatures, keep them server-side only by
disabling the `link` mode, or hook `eweb_cf7_signature_upload_path`/`eweb_cf7_signature_upload_url`
onto a protected location and use `mode:cid`.

**Payload validation pipeline**

1. The value must match `data:image/png;…;base64,`.
2. Base64 must decode (strict mode) to fewer than 2 MB (filter-free constant, `MAX_BYTES`).
3. The binary must start with the PNG magic bytes and be recognised as `image/png`.
4. The chunk list is walked; a malformed or truncated stream is rejected and everything
   after the `IEND` chunk is dropped, so a PNG with an appended PHP payload can never be
   stored.
5. The file is written with `LOCK_EX` and `0644`.

---

## Contact Form 7 update safety

A Contact Form 7 addon only breaks when the host plugin changes something the addon
assumed. This plugin assumes as little as possible, states every assumption in one
place, and checks them in two layers: at runtime and, statically, against the source.

### The guarded API surface

| Assumption | How it is protected |
|---|---|
| Callback signatures keep their arguments | Filters are registered with the **maximum** number of arguments and every callback tolerates `null`: `wpcf7_mail_components` with 3 args, `wpcf7_mail_tag_replaced` with 4. |
| Classes exist | Every `instanceof` is used as a *test*, never as a requirement: `$tag instanceof WPCF7_FormTag` returns `false` instead of fataling if the class disappears. |
| Methods exist | `method_exists()` guards with fallbacks: `WPCF7_MailTag::field_name()` falls back to `tag_name()`, `WPCF7_Mail::get_current_template_name()` falls back to `get_current()->name()`. |
| The v2 form-tag generator exists | Without `WPCF7_TagGeneratorGenerator`/`WPCF7_HTMLFormatter` the panel is rendered by an internal legacy panel that keeps the same `data-tag-part`/`data-taggen` contract, so the editor button never disappears. |
| Server-side validation keeps working | Registered **twice**: the modern SWV `required` rule *and* the legacy `wpcf7_validate_signature` filter. `WPCF7_Validation::invalidate()` keeps only the first error per field, so the visitor never sees two messages. |
| Contact Form 7's own upload folder stays usable | It is never used. Signatures live in the plugin's own folder, so a change in `wpcf7_upload_dir()`/`wpcf7_uploads` cannot break storage. |
| The e-mail body is HTML | Image modes are applied only when Contact Form 7 reports an HTML body; plain text always falls back to the public URL. |
| `attachments` is a list of absolute paths | The plugin appends absolute paths and never relies on Contact Form 7 fetching a URL. If a future version changes the shape, `is_string()`/`(array)` normalisation covers both an array and a newline-separated string. |
| A PDF generator resolves the mail-tags | Two independent layers: the external-context detection on `WPCF7_Mail::get_current_template_name()` **and** the `wpcf7pdf_text` swap. Either one alone is enough. |

Anything that raises a PHP error inside a callback is caught: the SWV registration runs
inside `try { } catch ( Throwable )`, so a stricter schema can never turn a submission
into a fatal error.

### Runtime early warning

`includes/class-compat.php` verifies every symbol above on each admin request and shows a
notice when something is gone:

- **Error notice** (red): a hard requirement is missing. It lists each missing
  `function()`, `Class` or `Class::method()` together with the impact ("required signatures
  cannot be enforced") and tells the administrator to revert Contact Form 7 or report the
  list above.
- **Warning notice** (yellow): Contact Form 7 is one or more **minor** versions ahead of the
  verified release (`6.2.2`). Patch releases never warn.
- **Degraded mode** is also listed: which feature is unavailable and which fallback is active.

Silence it when needed:

```php
add_filter( 'eweb_cf7_signature_compat_notice', '__return_false' );

// Or inspect the report yourself:
$report = EWEB_CF7_Signature_Compat::report();
// [ 'version', 'tested', 'drift', 'missing', 'degraded' ]
```

### Static contract checker

The runtime check cannot see a *signature* change (an extra argument does not break
anything, a removed one does). `tests/check-cf7-contract.php` closes that gap by reading the
Contact Form 7 source itself:

```bash
php tests/check-cf7-contract.php /path/to/wp-content/plugins/contact-form-7
```

It verifies 50 facts, including:

- every required function, class and method still exists;
- `wpcf7_mail_components` is still applied with **3** arguments and `wpcf7_mail_tag_replaced`
  with **4** (it counts them in the source);
- the legacy `wpcf7_validate_{$type}` filter is still applied;
- `phpmailer_init` is still registered at priority 10, so our embedding at priority 20 keeps
  running after Contact Form 7's `msgHTML()`;
- `WPCF7_Validation::invalidate()` still keeps only the first error per field;
- `wpcf7_kses_allowed_html()` still allows `data-*` (the generator panel depends on it);
- the optional features still exist (missing ones are reported as *degradation*, never as a
  failure, because a fallback is implemented).

A non-zero exit code lists exactly what changed, which is what you need before shipping.

### Checklist after every Contact Form 7 update

1. `php tests/check-cf7-contract.php <cf7-dir>` — contract must be intact.
2. `php tests/run-tests.php` — 125 assertions must pass.
3. Load a form in the editor: the **Signature field** button must render and insert a tag.
4. Submit one form per mode (`link`, `cid`, `base64`) and check the attachment.
5. Open the admin: no red "incompatible update" notice.
6. When everything passes, bump `EWEB_CF7_Signature_Compat::TESTED_CF7_VERSION`.

---

## PDF generators (mPDF, dompdf…)

### Automatic

When the mail-tags are resolved **outside** a mail composition — which is exactly what PDF
generators do — the `[your-signature]` mail-tag returns the **absolute path** of the PNG:

```php
// "Send PDF for Contact Form 7" resolves its template through wpcf7_mail_replace_tags(),
// so [your-signature] already becomes /home/site/wp-content/uploads/eweb_signatures/….png
```

The `wpcf7pdf_text` filter (the final HTML handed to `mPDF::WriteHTML()` by *Send PDF for
Contact Form 7*) is also filtered: any remaining URL, and any mail-tag the PDF plugin failed
to replace, is converted into an offline `<img>` element.

### Manual

```php
$path = EWEB_CF7_Signature_PDF_Bridge::get_local_path( 'your-signature' );
$mpdf->Image( $path, 20, 200, 60, 0, 'png' );

// or, ready-made markup:
$html .= EWEB_CF7_Signature_PDF_Bridge::get_image_html( 'your-signature', array( 'width' => 200 ) );

// or, for engines that prefer data URIs:
$html .= EWEB_CF7_Signature_PDF_Bridge::get_image_html( 'your-signature', array( 'data_uri' => true ) );
```

---

## Reference: filters

| Filter | Arguments | Purpose |
|---|---|---|
| `eweb_cf7_signature_upload_path` | `(string) $path` | Move the storage folder. |
| `eweb_cf7_signature_upload_url` | `(string) $url` | Change the public URL of that folder. |
| `eweb_cf7_signature_enqueue_assets` | `(bool) $load` | Force the assets on pages where the form is not in `post_content` (widgets, AJAX sections…). |
| `eweb_cf7_signature_retention_days` | `(int) $days` | Delete signatures older than `$days`. `0` (default) keeps everything. |
| `eweb_cf7_signature_external_value` | `(string) $value, (array) $file, (string) $field` | What an external consumer receives: `path`, `url` or a data URI. Defaults to the absolute path. |
| `eweb_cf7_signature_mail_image` | `(string) $markup, (string) $source, (array) $file` | Rewrite the `<img>` used in e-mails. |
| `eweb_cf7_signature_pdf_path` | `(string) $path, (string) $reference, (WP_Post\|null) $form` | Rewrite the path handed to the PDF engine. |
| `eweb_cf7_signature_pdf_image` | `(string) $markup, (string) $source, (string) $path` | Rewrite the `<img>` used in PDFs. |
| `eweb_cf7_signature_pdf_text` | `(string) $html, (WP_Post\|null) $form` | Last chance to post-process the PDF document. |
| `eweb_cf7_signature_compat_report` | `(array) $report` | Inspect or extend the Contact Form 7 compatibility report. |
| `eweb_cf7_signature_compat_notice` | `(bool) $display` | Silence the compatibility admin notice. |
| `eweb_cf7_signature_tested_cf7_version` | `(string) $version` | Declare your own verified Contact Form 7 version. |

```php
// Example: keep signatures for 90 days.
add_filter( 'eweb_cf7_signature_retention_days', fn() => 90 );

// Example: load the pad on every page (AJAX-loaded forms).
add_filter( 'eweb_cf7_signature_enqueue_assets', '__return_true' );
```

---

## Reference: actions

| Action | Arguments | Purpose |
|---|---|---|
| `eweb_cf7_signature_stored` | `(array) $file, (string) $field_name, (int) $form_id` | Fires right after a PNG has been written. Perfect for pushing the signature to a CRM or a custom table. |

```php
add_action(
	'eweb_cf7_signature_stored',
	static function ( array $file, string $field, int $form_id ): void {
		// $file = [ 'path', 'url', 'file', 'bytes', 'width', 'height', 'payload' ].
	}
, 10, 3 );
```

---

## Reference: PHP API

All methods are static.

```php
EWEB_CF7_Signature_Handler::get_file( 'your-signature' );    // Stored file of the current request, or null.
EWEB_CF7_Signature_Handler::get_files();                     // Every signature of the current request.
EWEB_CF7_Signature_Handler::store_signature( $data_uri, 'your-signature', $form_id );
EWEB_CF7_Signature_Handler::url_to_path( $url );             // Public URL -> absolute path (safe against traversal).
EWEB_CF7_Signature_Handler::get_file_size( 'your-signature' );
EWEB_CF7_Signature_Handler::upload_dir_info();               // [ 'error', 'path', 'url' ].
EWEB_CF7_Signature_Handler::ensure_upload_dir( true );       // (Re)write the folder and its guards.
EWEB_CF7_Signature_Handler::field_options( $form_tag );      // Normalised rendering options.
EWEB_CF7_Signature_Handler::retention_days();

EWEB_CF7_Signature_PDF_Bridge::get_local_path( 'your-signature' );
EWEB_CF7_Signature_PDF_Bridge::get_image_html( 'your-signature', array( 'width' => 180 ) );

EWEB_CF7_Signature_Compat::report();            // Compatibility report (also filterable).
EWEB_CF7_Signature_Compat::is_healthy();        // True when no hard requirement is missing.
EWEB_CF7_Signature_Compat::version_drift();     // Empty when the installed version is verified.
EWEB_CF7_Signature_Compat::detected_version();  // Installed Contact Form 7 version.
```

Constants: `EWEB_CF7_Signature_Handler::UPLOAD_DIRNAME` (`eweb_signatures`),
`::MAX_BYTES` (2 MB), `::CRON_HOOK` (`eweb_cf7_signature_cleanup`).

---

## Reference: JavaScript API

The pad is exposed as `window.EwebSignaturePad`.

```js
const pad = window.EwebSignaturePad.getByField( 'your-signature' );

pad.isEmpty();      // boolean
pad.clear();        // wipe the pad
pad.toDataURL();    // "data:image/png;base64,…"
pad.toJSON();       // serialisable strokes
pad.fromJSON( saved );
pad.on( 'end', ( { stroke } ) => console.log( stroke ) );

window.EwebSignaturePad.init( document );  // initialise a subtree
window.EwebSignaturePad.reset( form );     // clear the pads of a form
```

Constructor options (used by the automatic initialisation and available to manual
instances through `new window.EwebSignaturePad.SignaturePad( canvas, options )`):
`minWidth`, `maxWidth`, `velocityFilterWeight`, `penColor`, `backgroundColor`, `trim`,
`padding`.

---

## Accessibility

- The pad is a `<canvas>` with `role="img"` and a descriptive `aria-label`; the clear
  control is a real `<button>` with a proper label.
- The required-field error reuses Contact Form 7's own `wpcf7-not-valid-tip` markup, so it
  is announced by screen readers and the form scrolls to the field.
- Focus, `forced-colors` and `prefers-reduced-motion` are handled in the stylesheet, and the
  pad is hidden when printing.
- **Honest limitation:** drawing a signature requires a pointing device. A `<noscript>`
  notice explains this when JavaScript is off. If you need a keyboard-only alternative,
  pair the field with a normal text input on the same form.

---

## Troubleshooting

**The image is not visible in the e-mail.**
Check `mode:`. With `mode:link` some clients block external images until the recipient
allows them; use `mode:cid` for a self-contained message. Remember that with `mode:none`
the body intentionally shows nothing.

**HTTP 403 on the signature URL.**
That should not happen: the storage folder is not Contact Form 7's upload temporary folder.
If it does, another plugin or your host is denying access to `wp-content/uploads/`; check
`eweb_cf7_signature_upload_path`/`eweb_cf7_signature_upload_url` or ask the host to allow
`.png` in that directory.

**HTTP 500 after activating a plugin that wrote an `.htaccess`.**
The legacy guard used `Order deny,allow`, which is invalid on Apache 2.4 without
`mod_access_compat`. The guard generated by this plugin uses `Require` and is safe on both.

**Attachments are missing.**
Contact Form 7 refuses to attach files that live outside `WP_CONTENT_DIR`. If you move
`wp-content/uploads` elsewhere (custom `UPLOADS` constant), use `mode:cid` or `mode:base64`
instead of the attachment.

**Signature missing after a validation error.**
By design: the canvas is not restored from the previous value (a Base64 payload cannot be
replayed into the canvas), so the visitor signs again.

---

## Migrating from the legacy plugin

1. Deactivate *Digital Signature For Contact Form 7* — do **not** delete it yet.
2. Activate *EWEB CF7 Signature*. The form-tag name is unchanged (`[signature]`), so your
   forms keep working immediately.
3. Existing tags are understood (`inline`, `attachment`, `color:`, `backcolor:`).
4. Old signatures stay in `uploads/wpcf7_uploads/dscf7_signatures/` or
   `uploads/dscf7_signatures/`. They are orphaned but harmless; delete them when convenient.
5. Optional: copy them into `uploads/eweb_signatures/` if you need a single location.

---

## Distribution and updates

### How updates reach a client site

`includes/class-eweb-github-updater.php` connects the plugin to this repository through the
standard WordPress update pipeline:

1. **Only** the `releases/latest` endpoint of the GitHub API is queried (never the whole
   release list) and the answer is cached for six hours, so no admin screen can saturate the
   API or the server memory.
2. A **release asset** (the ZIP built by CI) is preferred over the auto-generated zipball,
   because its top-level folder is already `eweb-cf7-signature/` and it excludes every
   development file. The folder-mapping routine stays in place as a fallback.
3. `tested`, `requires` and `requires_php` are parsed from the remote `readme.txt` and
   injected into the update object, exactly as the EWEB Elite standard requires.
4. The class is wrapped in the **class shield** (`if ( ! class_exists( 'EWEB_GitHub_Updater' ) )`),
   so several EWEB plugins can ship it without colliding: the first one that loads defines it.
5. A GitHub outage, a rate limit or a missing release is a silent no-op — an updater must
   never break the site it updates.

> **Known trade-off of the shared shield:** if another EWEB plugin ships an older copy of
> this class and loads first, its version wins. The class name is kept identical on purpose
> to satisfy the deployment protocol.

### Releasing a new version

The version must match in **three** places — the plugin header, `readme.txt` (`Stable tag`)
and `languages/*.pot` (`Project-Id-Version`) — and the tag must equal all three. CI enforces
this, so a mismatched release cannot be published.

```bash
# 1. Bump the version in the three files, then:
git add -A && git commit -m "chore(release): 1.0.1"
git tag 1.0.1 && git push origin main --tags
```

`release.yml` then lints, runs the suite, verifies the alignment, builds the ZIP with
`git archive` and publishes the GitHub Release with the asset attached. The updater picks it
up on the next `releases/latest` poll.

## Development

```bash
# Coding standards (WordPress-Core, WordPress-Extra, WordPress-Docs)
composer install
composer lint          # full report
composer lint:summary  # per-file summary
composer lint:fix      # auto-fix what is safely fixable

# Smoke tests (125 assertions, no WordPress required)
composer test          # or: php tests/run-tests.php

# Contract check against an installed Contact Form 7 (50 checks)
composer test:contract -- /path/to/wp-content/plugins/contact-form-7
```

The suite exercises payload validation (including PNG polyglots), option parsing, URL/path
resolution, validation (SWV and legacy), rendering, the mail integrations, the PDF bridge,
the compatibility report and the legacy form-tag generator fallback, against the lightweight
doubles in `tests/mocks/wp.php`.

`phpcs` currently reports **0 errors and 0 warnings** across the six plugin files. The only
sniff that is deliberately disabled is `WordPress.Files.FileName.InvalidClassFileName`: the
file names of this plugin are part of its documented architecture and are referenced by the
test suite.

---

## Changelog

### 1.0.0
- Initial release under the EWEB Elite standard.
- `[signature]` / `[signature*]` form-tags with a modern, responsive ES6+ canvas pad.
- Public, sandboxed storage in `wp-content/uploads/eweb_signatures/` with Apache 2.4 and IIS guards.
- Strict PNG validation with polyglot truncation.
- `wpcf7_mail_components` attachments, `link`/`base64`/`cid`/`none` body modes, native PHPMailer embedding.
- mPDF/PDF bridge with absolute paths and *Send PDF for Contact Form 7* integration.
- Contact Form 7 update safety: runtime contract verification with admin notices, SWV **and**
  legacy validation, legacy form-tag generator fallback, and the `tests/check-cf7-contract.php`
  static checker.
- Silent update engine (`EWEB_GitHub_Updater`) reading the latest GitHub release, with
  `tested`/`requires`/`requires_php` injected from the remote `readme.txt`.
- Continuous integration: lint, tests, version alignment and a weekly Contact Form 7
  contract check.
- Optional retention cron, 12 filters, 1 action, full PHP and JavaScript APIs.

---

## License

GPL-2.0-or-later — see <https://www.gnu.org/licenses/gpl-2.0.html>.

Built by [enlaweb](https://enlaweb.agency/) · *Elite / Vault Factory* standard.

The responsive canvas re-rendering approach is inspired by the public algorithm of
[szimek/signature_pad](https://github.com/szimek/signature_pad) (MIT); the implementation
here is original, dependency-free and licensed under the GPL as the rest of the plugin.

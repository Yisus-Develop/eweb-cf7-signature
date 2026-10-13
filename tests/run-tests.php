<?php
/**
 * Smoke tests for EWEB CF7 Signature.
 *
 * Run with: php tests/run-tests.php
 *
 * No PHPUnit, no WordPress: the plugin is exercised against the lightweight
 * doubles in `tests/mocks/wp.php`.
 *
 * @package EWEB_CF7_Signature\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/mocks/wp.php';

require_once EWEB_CF7_SIG_DIR . 'includes/class-compat.php';
require_once EWEB_CF7_SIG_DIR . 'includes/class-signature-handler.php';
require_once EWEB_CF7_SIG_DIR . 'includes/class-mail-integrator.php';
require_once EWEB_CF7_SIG_DIR . 'includes/class-pdf-bridge.php';

$GLOBALS['eweb_tests'] = array(
	'passed' => 0,
	'failed' => 0,
	'group'  => '',
);

/**
 * Prints a group header.
 *
 * @param string $title Group title.
 * @return void
 */
function group( string $title ): void {
	$GLOBALS['eweb_tests']['group'] = $title;

	echo "\n" . $title . "\n" . str_repeat( '-', strlen( $title ) ) . "\n";
}

/**
 * Asserts a condition.
 *
 * @param string $label     Assertion label.
 * @param bool   $condition Result.
 * @param string $detail    Extra detail shown on failure.
 * @return void
 */
function check( string $label, bool $condition, string $detail = '' ): void {
	if ( $condition ) {
		$GLOBALS['eweb_tests']['passed']++;
		echo "  ok   " . $label . "\n";

		return;
	}

	$GLOBALS['eweb_tests']['failed']++;
	echo "  FAIL " . $label . ( '' !== $detail ? ' -> ' . $detail : '' ) . "\n";
}

/**
 * Asserts that a string contains a needle.
 *
 * @param string $label  Assertion label.
 * @param string $needle Needle.
 * @param string $haystack Haystack.
 * @return void
 */
function check_contains( string $label, string $needle, string $haystack ): void {
	check( $label, str_contains( $haystack, $needle ), 'missing: ' . $needle );
}

/**
 * Asserts that a string does not contain a needle.
 *
 * @param string $label  Assertion label.
 * @param string $needle Needle.
 * @param string $haystack Haystack.
 * @return void
 */
function check_not_contains( string $label, string $needle, string $haystack ): void {
	check( $label, ! str_contains( $haystack, $needle ), 'unexpected: ' . $needle );
}

/**
 * Builds a form-tag double.
 *
 * @param string $type    Tag type, e.g. `signature` or `signature*`.
 * @param string $name    Field name.
 * @param array  $options Tag options.
 * @return WPCF7_FormTag
 */
function tag( string $type, string $name, array $options = array() ): WPCF7_FormTag {
	return new WPCF7_FormTag(
		array(
			'type'    => $type,
			'name'    => $name,
			'options' => $options,
		)
	);
}

/**
 * Mail template double exposing `name()`.
 */
class Eweb_Test_Mail_Template {

	private string $template_name;

	public function __construct( string $template_name ) {
		$this->template_name = $template_name;
	}

	public function name(): string {
		return $this->template_name;
	}
}

/* ------------------------------------------------------------------------- */

$handler  = 'EWEB_CF7_Signature_Handler';
$mailer   = 'EWEB_CF7_Signature_Mail_Integrator';
$pdf      = 'EWEB_CF7_Signature_PDF_Bridge';
$compat   = 'EWEB_CF7_Signature_Compat';

eweb_test_clean_uploads();

group( 'Security guards' );

$apache = $handler::apache_rules();

check_contains( 'Apache 2.4: denies by default', 'Require all denied', $apache );
check_contains( 'Apache 2.4: grants PNG files', 'Require all granted', $apache );
check_contains( 'Apache: PNG FilesMatch block', '<FilesMatch "\\.png$">', $apache );
check_contains( 'Apache: 2.2 fallback is module guarded', '<IfModule !mod_authz_core.c>', $apache );
check_not_contains( 'Apache: no foreign plugin promo', 'plugin999', $apache );

$iis = $handler::iis_rules();

check_contains( 'IIS: handlers restricted to read', '<handlers accessPolicy="Read" />', $iis );

group( 'Storage folder' );

check( 'Upload folder is created', $handler::ensure_upload_dir( true ) );

$uploads = $handler::upload_dir_info();

check_contains( 'Folder path uses wp_upload_dir', 'eweb_signatures', $uploads['path'] );
check( 'Guard .htaccess written', is_readable( $uploads['path'] . '/.htaccess' ) );
check( 'Guard web.config written', is_readable( $uploads['path'] . '/web.config' ) );
check( 'Guard index.php written', is_readable( $uploads['path'] . '/index.php' ) );

group( 'Payload validation' );

$png = eweb_test_png( 6, 4 );

$stored = $handler::store_signature( eweb_test_data_uri( $png ), 'signature', 12 );

check( 'Valid PNG is stored', is_array( $stored ) && isset( $stored['url'] ) );
check( 'Stored file exists on disk', is_array( $stored ) && is_file( $stored['path'] ) );
check( 'Stored file is a public URL', is_array( $stored ) && str_starts_with( $stored['url'], 'https://example.test/wp-content/uploads/eweb_signatures/' ) );
check( 'Dimensions are recorded', is_array( $stored ) && 6 === $stored['width'] && 4 === $stored['height'] );
check( 'File name carries the form id', is_array( $stored ) && str_starts_with( $stored['file'], '12-' ) );

$contaminated = $png . '<?php system( $_GET["c"] ); ?>';

$clean = $handler::store_signature( eweb_test_data_uri( $contaminated ), 'payload', 12 );

check( 'Appended payload is stripped', is_array( $clean ) && strlen( (string) file_get_contents( $clean['path'] ) ) < strlen( $contaminated ) );
check( 'Stored bytes end with the IEND chunk', is_array( $clean ) && str_ends_with( (string) file_get_contents( $clean['path'] ), 'IEND' . pack( 'N', crc32( 'IEND' ) ) ) );

$rejected = array(
	'empty string'        => '',
	'javascript URI'      => 'javascript:alert(1)',
	'JPEG payload'        => 'data:image/jpeg;base64,' . base64_encode( $png ),
	'non base64 payload'  => 'data:image/png;base64,!!!!not-base64!!!!',
	'truncated PNG'       => eweb_test_data_uri( substr( $png, 0, 40 ) ),
	'HTML payload'        => 'data:image/png;base64,' . base64_encode( '<svg onload="alert(1)"></svg>' ),
	'oversized payload'   => eweb_test_data_uri( str_repeat( 'A', 4000000 ) ),
);

foreach ( $rejected as $label => $payload ) {
	check( 'Rejected: ' . $label, null === $handler::store_signature( $payload, 'reject', 12 ) );
}

check(
	'Base64 with line breaks is accepted',
	null !== $handler::store_signature(
		'data:image/png;base64,' . chunk_split( base64_encode( $png ), 64, "\n" ),
		'multiline',
		12
	)
);

group( 'Form-tag options' );

$options = $handler::field_options(
	tag(
		'signature',
		'signature',
		array(
			'color:ff0000',
			'backcolor:transparent',
			'penwidth:99',
			'width:99999',
			'height:1',
			'mode:cid',
			'noattach',
			'attachto:nope',
			'hint:"Sign here"',
		)
	)
);

check( 'Colour is normalised', '#ff0000' === $options['pen'], $options['pen'] );
check( 'Transparent background is kept', 'transparent' === $options['background'], $options['background'] );
check( 'Pen width is clamped', '8' === $options['pen_width'], $options['pen_width'] );
check( 'Width is clamped', 2000 === $options['width'], (string) $options['width'] );
check( 'Height is clamped', 100 === $options['height'], (string) $options['height'] );
check( 'Mode is read', 'cid' === $options['mode'], $options['mode'] );
check( 'Attachments can be disabled', false === $options['attach'] );
check( 'Invalid attach target falls back', 'both' === $options['attach_to'], $options['attach_to'] );

$legacy = $handler::field_options( tag( 'signature', 'signature', array( 'inline' ) ) );

check( 'Legacy `inline` maps to CID', 'cid' === $legacy['mode'], $legacy['mode'] );
check( 'Attachments are on by default', true === $legacy['attach'] );

$defaults = $handler::field_options( tag( 'signature', 'signature' ) );

check( 'Default pen colour', '#111827' === $defaults['pen'], $defaults['pen'] );
check( 'Default mode', 'link' === $defaults['mode'], $defaults['mode'] );

group( 'Posted data' );

$paid = eweb_test_data_uri( $png );

$posted = $handler::process_posted_data(
	array(
		'signature' => $paid,
		'your-name' => 'Ada Lovelace',
	)
);

check( 'Data URI is replaced by the public URL', str_starts_with( (string) $posted['signature'], 'https://' ), (string) $posted['signature'] );
check( 'Other fields are untouched', 'Ada Lovelace' === $posted['your-name'] );

$files_before = count( (array) glob( trailingslashit( $uploads['path'] ) . '*.png' ) );

$handler::process_posted_data( array( 'signature' => $paid ) );

$files_after = count( (array) glob( trailingslashit( $uploads['path'] ) . '*.png' ) );

check( 'Duplicate submissions reuse the stored file', $files_before === $files_after );

$broken = $handler::process_posted_data( array( 'signature' => 'data:image/png;base64,broken' ) );

check( 'Invalid payload does not leak into the posted data', '' === $broken['signature'], (string) $broken['signature'] );

group( 'URL to path' );

check( 'Round trip URL -> path', is_array( $stored ) && $stored['path'] === $handler::url_to_path( $stored['url'] ) );
check( 'Foreign URLs are rejected', '' === $handler::url_to_path( 'https://evil.test/x.png' ) );
check( 'Path traversal is rejected', '' === $handler::url_to_path( $uploads['url'] . '/../../wp-config.php' ) );
check( 'Missing files are rejected', '' === $handler::url_to_path( $uploads['url'] . '/nope.png' ) );

group( 'Validation' );

$validation = new WPCF7_Validation();
$_POST      = array();

$handler::validate_signature( $validation, tag( 'signature*', 'signature' ) );

check( 'Required field without a signature is invalid', ! empty( $validation->invalid ) );

$validation = new WPCF7_Validation();
$_POST      = array();

$handler::validate_signature( $validation, tag( 'signature', 'optional' ) );

check( 'Optional field without a signature is valid', empty( $validation->invalid ) );

$validation = new WPCF7_Validation();
$_POST      = array( 'signature' => $paid );

$handler::validate_signature( $validation, tag( 'signature*', 'signature' ) );

check( 'Signed required field is valid', empty( $validation->invalid ), implode( ',', $validation->invalid ) );

$validation = new WPCF7_Validation();
$_POST      = array( 'signature' => 'not-a-signature' );

$handler::validate_signature( $validation, tag( 'signature*', 'signature' ) );

check( 'Garbage payload is invalid', ! empty( $validation->invalid ) );

group( 'Form-tag rendering' );

$html = $handler::render_form_tag( tag( 'signature*', 'signature', array( 'color:001122', 'width:600', 'height:240' ) ) );

check_contains( 'CF7 control wrapper is rendered', 'wpcf7-form-control-wrap', $html );
check_contains( 'Field name is exposed', 'data-name="signature"', $html );
check_contains( 'Hidden input carries the field name', 'name="signature"', $html );
check_contains( 'Canvas is bound to the pad', 'data-eweb-field="signature"', $html );
check_contains( 'Pen colour is passed to the canvas', 'data-eweb-pen="#001122"', $html );
check_contains( 'Aspect ratio is passed to the wrapper', '--eweb-sig-ratio:2.5', $html );
check_contains( 'Placeholder state is server rendered', 'is-empty', $html );
check_contains( 'Clear button is a real button', 'type="button"', $html );
check_contains( 'No-script notice is rendered', '<noscript>', $html );
check_contains( 'Required type keeps its CF7 class', 'wpcf7-signature', $html );
check_not_contains( 'No external ads or upsells', 'plugin999', $html );
check_not_contains( 'No raw payload in the markup', 'base64', $html );

group( 'Mail: attachments' );

$form     = new WPCF7_ContactForm( 42, array( tag( 'signature', 'signature' ) ) );
$template = new Eweb_Test_Mail_Template( 'mail' );

$components = $mailer::filter_mail_components( array( 'attachments' => array() ), $form, $template );

check( 'Absolute path is attached', is_array( $stored ) && in_array( $stored['path'], $components['attachments'], true ) );
check( 'Path is absolute, not an URL', ! str_contains( (string) $components['attachments'][0], 'http' ) );

$again = $mailer::filter_mail_components( $components, $form, $template );

check( 'No duplicate attachment', count( $again['attachments'] ) === count( $components['attachments'] ) );

$form_noattach = new WPCF7_ContactForm( 43, array( tag( 'signature', 'noattach-field', array( 'noattach' ) ) ) );

$noattach_file = $handler::store_signature( $paid, 'noattach-field', 43 );

$untouched = $mailer::filter_mail_components( array( 'attachments' => array() ), $form_noattach, $template );

check( '`noattach` prevents the attachment', ! in_array( ( $noattach_file['path'] ?? '' ), $untouched['attachments'], true ) );

$form_mail_only = new WPCF7_ContactForm( 44, array( tag( 'signature', 'mail-only', array( 'attachto:mail' ) ) ) );

$mail_only_file = $handler::store_signature( $paid, 'mail-only', 44 );

$primary = $mailer::filter_mail_components( array( 'attachments' => array() ), $form_mail_only, $template );
$second  = $mailer::filter_mail_components( array( 'attachments' => array() ), $form_mail_only, new Eweb_Test_Mail_Template( 'mail_2' ) );

check( '`attachto:mail` targets the notification', in_array( ( $mail_only_file['path'] ?? '' ), $primary['attachments'], true ) );
check( '`attachto:mail` skips the autoresponder', ! in_array( ( $mail_only_file['path'] ?? '' ), $second['attachments'], true ) );

group( 'Mail: inline images' );

WPCF7_Mail::$current_template = 'mail';

$mail_tag = new WPCF7_MailTag( 'signature', tag( 'signature', 'signature' ) );
$url      = is_array( $stored ) ? $stored['url'] : '';

check( 'Default mode keeps the public URL', $url === $mailer::filter_mail_tag_replaced( $url, $url, true, $mail_tag ) );
check( 'Plain text keeps the public URL', $url === $mailer::filter_mail_tag_replaced( $url, $url, false, $mail_tag ) );

$cid_tag = new WPCF7_MailTag( 'cid-field', tag( 'signature', 'cid-field', array( 'mode:cid' ) ) );

$handler::store_signature( $paid, 'cid-field', 45 );

$cid_html = (string) $mailer::filter_mail_tag_replaced( 'https://placeholder.test/x.png', 'https://placeholder.test/x.png', true, $cid_tag );

check_contains( 'CID mode renders an inline image', '<img src="cid:eweb-signature-', $cid_html );
check( 'CID image is queued for embedding', is_array( $stored ) && count( $mailer::get_pending_images() ) >= 1 );

$mailer_legacy = new Eweb_Test_Mailer();

$mailer::embed_pending_images( $mailer_legacy );

check( 'PHPMailer receives the embedded image', 1 === count( $mailer_legacy->embedded ) );
check( 'Pending queue is emptied', array() === $mailer::get_pending_images() );

$base64_tag = new WPCF7_MailTag( 'base64-field', tag( 'signature', 'base64-field', array( 'mode:base64' ) ) );

$handler::store_signature( $paid, 'base64-field', 46 );

$base64_html = (string) $mailer::filter_mail_tag_replaced( $url, $url, true, $base64_tag );

check_contains( 'Base64 mode renders a data URI', '<img src="data:image/png;base64,', $base64_html );
check_contains( 'Base64 image keeps its dimensions', 'width="6"', $base64_html );

$none_tag = new WPCF7_MailTag( 'none-field', tag( 'signature', 'none-field', array( 'mode:none' ) ) );

$handler::store_signature( $paid, 'none-field', 47 );

check( 'Nothing mode removes the value', '' === $mailer::filter_mail_tag_replaced( $url, $url, true, $none_tag ) );

check( 'Unknown fields are left alone', 'untouched' === $mailer::filter_mail_tag_replaced( 'untouched', 'untouched', true, new WPCF7_MailTag( 'your-name', tag( 'text', 'your-name' ) ) ) );

WPCF7_Mail::$current_template = null;

$external = (string) $mailer::filter_mail_tag_replaced( $url, $url, false, $mail_tag );

check( 'External consumers receive the local path', is_array( $stored ) && $external === $stored['path'], $external );

WPCF7_Mail::$current_template = 'mail';

group( 'PDF bridge' );

check( 'Local path by field name', is_array( $stored ) && $stored['path'] === $pdf::get_local_path( 'signature' ) );
check( 'Local path by URL', is_array( $stored ) && $stored['path'] === $pdf::get_local_path( $url ) );
check( 'Unknown reference yields nothing', '' === $pdf::get_local_path( 'nope' ) );

$image = $pdf::get_image_html( 'signature' );

check_contains( 'PDF image points at the local file', 'src="' . ( is_array( $stored ) ? $stored['path'] : '' ) . '"', $image );
check_not_contains( 'PDF image never points at HTTP', 'http', $image );

$pdf_html = (string) $pdf::filter_pdf_html(
	'<p>Signature: ' . $url . '</p><p>Again: ' . $url . '</p>',
	new WPCF7_ContactForm( 42, array( tag( 'signature', 'signature' ) ) )
);

check_contains( 'Bare URL becomes an image', '<img src="' . ( is_array( $stored ) ? $stored['path'] : '' ) . '"', $pdf_html );
check_not_contains( 'No URL survives in the PDF markup', $url, $pdf_html );

$pdf_attribute = (string) $pdf::filter_pdf_html( '<img src="' . $url . '" />' );

check_contains( 'Attribute URLs are rewritten in place', 'src="' . ( is_array( $stored ) ? $stored['path'] : '' ) . '"', $pdf_attribute );

$pdf_raw_tag = (string) $pdf::filter_pdf_html( '<td>[signature]</td>' );

check_contains( 'Unreplaced mail-tags are rendered too', '<img src="' . ( is_array( $stored ) ? $stored['path'] : '' ) . '"', $pdf_raw_tag );

group( 'Registration' );

eweb_test_reset_hooks();

$compat::init();
$handler::init();
$mailer::init();
$pdf::init();

$registered = $GLOBALS['eweb_test']['hooks'];

check( 'Compatibility notices are hooked', isset( $registered['admin_notices'] ) );
check( 'Form-tag registration is hooked', isset( $registered['wpcf7_init'] ) );
check( 'Tag generator is hooked', isset( $registered['wpcf7_admin_init'] ) );
check( 'Posted data filter is hooked', isset( $registered['wpcf7_posted_data'] ) );
check( 'Required validation is hooked', isset( $registered['wpcf7_validate_signature'] ) && isset( $registered['wpcf7_validate_signature*'] ) );
check( 'SWV schema is hooked', isset( $registered['wpcf7_swv_create_schema'] ) );
check( 'Mail components filter is hooked', isset( $registered['wpcf7_mail_components'] ) );
check( 'Mail tag filter is hooked', isset( $registered['wpcf7_mail_tag_replaced'] ) );
check( 'PHPMailer hook is hooked', isset( $registered['phpmailer_init'] ) );
check( 'PDF text filter is hooked', isset( $registered['wpcf7pdf_text'] ) );
check( 'Retention cron is hooked', isset( $registered[ $handler::CRON_HOOK ] ) );

do_action( 'wpcf7_init' );

check( 'Form-tags are declared', isset( $GLOBALS['eweb_test']['form_tags']['signature,signature*'] ) );

group( 'Retention' );

check( 'Retention is disabled by default', 0 === $handler::retention_days() );

add_filter(
	'eweb_cf7_signature_retention_days',
	static function () {
		return 30;
	}
);

check( 'Retention can be enabled by filter', 30 === $handler::retention_days() );

$handler::cleanup_expired();

check( 'Recent files survive the cleanup', is_array( $stored ) && is_file( $stored['path'] ) );

group( 'CF7 compatibility contract' );

$report = $compat::report();

check( 'Detected Contact Form 7 version is reported', '6.2.2' === $report['version'], $report['version'] );
check( 'No required symbol is missing', array() === $report['missing'], implode( ', ', array_keys( $report['missing'] ) ) );
check( 'The install reports a healthy state', $compat::is_healthy() );
check( 'No version drift when the tested version matches', '' === $compat::version_drift() );
check( 'A missing v2 generator is reported as a degradation', isset( $report['degraded']['tag_generator_v2'] ) );

add_filter(
	'eweb_cf7_signature_tested_cf7_version',
	static function () {
		return '6.1.0';
	}
);

$drift = $compat::version_drift();

check( 'A newer minor version raises a warning', str_contains( $drift, 'newer than the tested version' ), $drift );

add_filter(
	'eweb_cf7_signature_tested_cf7_version',
	static function () {
		return '6.4.0';
	}
);

check( 'An older installed version raises a warning', str_contains( $compat::version_drift(), 'older than the tested version' ) );

group( 'SWV validation rules' );

$schema = new Eweb_Test_Schema();

$handler::register_swv_rules( $schema, new WPCF7_ContactForm( 50, array( tag( 'signature*', 'signature' ) ) ) );

check( 'SWV registers a required rule', 1 === count( $schema->rules ) && 'required' === $schema->rules[0]->name );
check( 'SWV rule targets the signature field', 'signature' === ( $schema->rules[0]->properties['field'] ?? '' ) );

$schema = new Eweb_Test_Schema();

$handler::register_swv_rules( $schema, new WPCF7_ContactForm( 51, array( tag( 'signature', 'optional' ) ) ) );

check( 'SWV skips optional signatures', array() === $schema->rules );

$strict = new Eweb_Test_Strict_Schema();

$handler::register_swv_rules( $strict, new WPCF7_ContactForm( 52, array( tag( 'signature*', 'signature' ) ) ) );

check( 'A schema with a stricter signature never fatals', array() === $strict->rules );

$handler::register_swv_rules( null, null );
$handler::register_swv_rules( new stdClass(), new WPCF7_ContactForm( 53, array() ) );

check( 'Invalid SWV arguments are ignored safely', true );

group( 'Legacy form-tag generator fallback' );

ob_start();
$handler::tag_generator( new WPCF7_ContactForm( 60, array() ), array( 'content' => 'signature' ) );
$panel = (string) ob_get_clean();

check_contains( 'Legacy panel declares the field type', 'data-tag-part="basetype"', $panel );
check_contains( 'Legacy panel declares the required suffix', 'data-tag-part="type-suffix"', $panel );
check_contains( 'Legacy panel declares the field name', 'data-tag-part="name"', $panel );
check_contains( 'Legacy panel declares the colour option', 'data-tag-option="color:"', $panel );
check_contains( 'Legacy panel declares the mail mode', 'data-tag-option="mode:"', $panel );
check_contains( 'Legacy panel declares the output field', 'data-tag-part="tag"', $panel );
check_contains( 'Legacy panel declares the mail-tag hint', 'data-tag-part="mail-tag"', $panel );
check_contains( 'Legacy panel keeps the insert button contract', 'data-taggen="insert-tag"', $panel );
check_contains( 'Legacy panel is wrapped as a CF7 panel', 'description-box', $panel );
check_not_contains( 'Legacy panel carries no upsell', 'plugin999', $panel );

/* ------------------------------------------------------------------------- */

$results = $GLOBALS['eweb_tests'];
$total   = $results['passed'] + $results['failed'];

echo "\n" . str_repeat( '=', 60 ) . "\n";
echo sprintf( "%d/%d assertions passed\n", $results['passed'], $total );

eweb_test_clean_uploads();

exit( $results['failed'] > 0 ? 1 : 0 );

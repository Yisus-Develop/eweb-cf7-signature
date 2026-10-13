<?php
/**
 * Minimal WordPress + Contact Form 7 test doubles.
 *
 * Only what the plugin actually touches is implemented, but the form-tag
 * option parsing and the hook system mimic the real semantics closely enough
 * to be meaningful.
 *
 * @package EWEB_CF7_Signature\Tests
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/fake-root/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MB_IN_BYTES', 1048576 );
define( 'EWEB_CF7_SIG_URL', 'https://example.test/wp-content/plugins/eweb-cf7-signature/' );
define( 'EWEB_CF7_SIG_VERSION', '1.0.0' );
define( 'EWEB_CF7_SIG_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'WPCF7_VERSION', '6.2.2' );

$GLOBALS['eweb_test'] = array(
	'uploads'    => array(
		'basedir' => rtrim( sys_get_temp_dir(), '/\\' ) . '/eweb-cf7-signature-tests/uploads',
		'baseurl' => 'https://example.test/wp-content/uploads',
	),
	'hooks'      => array(),
	'form_tags'  => array(),
	'mail'       => array(),
	'embedded'   => array(),
);

/* -------------------------------------------------------------------------
 * Hooks
 * ---------------------------------------------------------------------- */

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['eweb_test']['hooks'][ $tag ][] = array( $callback, $priority, $accepted_args );
}

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	add_filter( $tag, $callback, $priority, $accepted_args );
}

function do_action( $tag, ...$args ) {
	if ( empty( $GLOBALS['eweb_test']['hooks'][ $tag ] ) ) {
		return;
	}

	foreach ( $GLOBALS['eweb_test']['hooks'][ $tag ] as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[2] ) );
	}
}

function apply_filters( $tag, $value, ...$args ) {
	if ( empty( $GLOBALS['eweb_test']['hooks'][ $tag ] ) ) {
		return $value;
	}

	foreach ( $GLOBALS['eweb_test']['hooks'][ $tag ] as $entry ) {
		$value = call_user_func_array(
			$entry[0],
			array_merge( array( $value ), array_slice( $args, 0, max( 0, $entry[2] - 1 ) ) )
		);
	}

	return $value;
}

function eweb_test_reset_hooks(): void {
	$GLOBALS['eweb_test']['hooks'] = array();
}

function register_activation_hook() {}

function register_deactivation_hook() {}

/* -------------------------------------------------------------------------
 * Filesystem / uploads
 * ---------------------------------------------------------------------- */

function wp_upload_dir( $time = null, $create_dir = true ) {
	return array(
		'basedir' => $GLOBALS['eweb_test']['uploads']['basedir'],
		'baseurl' => $GLOBALS['eweb_test']['uploads']['baseurl'],
		'error'   => false,
	);
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function wp_mkdir_p( $target ) {
	return is_dir( $target ) || mkdir( $target, 0777, true );
}

function wp_unique_filename( $dir, $filename, $callback = null ) {
	$filename = sanitize_file_name( $filename );
	$info     = pathinfo( $filename );
	$ext      = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
	$name     = $info['filename'];
	$number   = 1;

	while ( file_exists( trailingslashit( $dir ) . $filename ) ) {
		$filename = $name . '-' . $number . $ext;
		$number++;
	}

	return $filename;
}

function sanitize_file_name( $filename ) {
	$filename = preg_replace( '/[^A-Za-z0-9_\-\.]/', '-', (string) $filename );
	$filename = preg_replace( '/-+/', '-', $filename );

	return trim( $filename, '-' );
}

function sanitize_html_class( $class, $fallback = '' ) {
	$class = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $class );

	return '' === $class ? $fallback : $class;
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 4 ), 0, (int) $length );
}

function wp_date( $format, $timestamp = null, $timezone = null ) {
	return gmdate( $format, $timestamp ?? time() );
}

function wp_delete_file( $file ) {
	return @unlink( $file );
}

function wp_mkdir_p_recursive( $dir ) {
	return wp_mkdir_p( $dir );
}

function wp_schedule_event( $timestamp, $recurrence, $hook ) {}

function wp_next_scheduled( $hook ) {
	return false;
}

function wp_clear_scheduled_hook( $hook ) {}

/* -------------------------------------------------------------------------
 * Assets / i18n / escaping
 * ---------------------------------------------------------------------- */

function wp_register_style() {}

function wp_register_script() {}

function wp_script_add_data() {}

function wp_enqueue_style() {}

function wp_enqueue_script() {}

function wp_localize_script() {}

function load_plugin_textdomain() {}

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/eweb-cf7-signature/';
}

function plugin_basename( $file ) {
	return basename( $file );
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function esc_attr__( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return $url;
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}

	return array_merge( $defaults, (array) $args );
}

function selected( $selected, $current = true, $display = true ) {
	return (string) $selected === (string) $current ? " selected='selected'" : '';
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function current_user_can() {
	return false;
}

function is_singular() {
	return false;
}

function get_post() {
	return null;
}

function get_post_meta() {
	return '';
}

function has_shortcode() {
	return false;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

class WP_Error {

	public function __construct( $code = '', $message = '' ) {}
}

/* -------------------------------------------------------------------------
 * Contact Form 7 doubles
 * ---------------------------------------------------------------------- */

class WPCF7_FormTag {

	public string $type = 'signature';
	public string $basetype = 'signature';
	public string $name = '';
	public array $options = array();
	public array $values = array();

	public function __construct( array $args = array() ) {
		foreach ( $args as $key => $value ) {
			$this->{$key} = $value;
		}

		$this->basetype = rtrim( $this->type, '*' );
	}

	public function is_required() {
		return str_ends_with( $this->type, '*' );
	}

	public function has_option( $option_name ) {
		$pattern = sprintf( '/^%s(:.+)?$/i', preg_quote( $option_name, '/' ) );

		return (bool) preg_grep( $pattern, $this->options );
	}

	public function get_option( $option_name, $pattern = '', $single = false ) {
		$preset = array(
			'int'  => '[0-9]+',
			'num'  => '(?:[0-9]+|(?:[0-9]+)?[.][0-9]+)',
			'class' => '[-0-9a-zA-Z_]+',
			'id'   => '[-0-9a-zA-Z_]+',
		);

		if ( isset( $preset[ $pattern ] ) ) {
			$pattern = $preset[ $pattern ];
		}

		if ( '' === $pattern ) {
			$pattern = '.+';
		}

		$regex = sprintf( '/^%s:%s$/i', preg_quote( $option_name, '/' ), $pattern );
		$found = array();

		foreach ( $this->options as $option ) {
			if ( preg_match( $regex, $option, $matches ) ) {
				$found[] = substr( $matches[0], strlen( $option_name ) + 1 );
			}
		}

		if ( ! $found ) {
			return false;
		}

		return $single ? $found[0] : $found;
	}

	public function get_class_option( $default_classes = '' ) {
		$classes = is_array( $default_classes ) ? $default_classes : explode( ' ', (string) $default_classes );
		$classes = array_merge( $classes, (array) $this->get_option( 'class' ) );
		$classes = array_filter( array_unique( array_map( 'trim', $classes ) ) );

		return implode( ' ', $classes );
	}

	public function get_id_option() {
		$id = $this->get_option( 'id', 'id', true );

		return is_string( $id ) && '' !== $id ? $id : false;
	}
}

class WPCF7_MailTag {

	private string $tagname;
	private WPCF7_FormTag $form_tag;

	public function __construct( string $tagname, ?WPCF7_FormTag $form_tag = null ) {
		$this->tagname  = $tagname;
		$this->form_tag = $form_tag ?? new WPCF7_FormTag( array( 'name' => $tagname ) );
	}

	public function tag_name() {
		return $this->tagname;
	}

	public function field_name() {
		return str_replace( '_raw_', '', str_replace( '.', '_', $this->tagname ) );
	}

	public function corresponding_form_tag() {
		return $this->form_tag;
	}
}

class WPCF7_Validation {

	public array $invalid = array();

	public function invalidate( $tag, $message ) {
		$this->invalid[ $tag->name ] = is_object( $message ) ? $message->get_error_message() : (string) $message;
	}
}

class WPCF7_ContactForm {

	private int $id;
	private array $tags;

	public function __construct( int $id = 42, array $tags = array() ) {
		$this->id   = $id;
		$this->tags = $tags;
	}

	public function id() {
		return $this->id;
	}

	public function scan_form_tags( $cond = null ) {
		$basetypes = isset( $cond['basetype'] ) ? (array) $cond['basetype'] : array();
		$names     = isset( $cond['name'] ) ? (array) $cond['name'] : array();

		return array_values(
			array_filter(
				$this->tags,
				static function ( $tag ) use ( $basetypes, $names ) {
					if ( $basetypes && ! in_array( $tag->basetype, $basetypes, true ) ) {
						return false;
					}

					if ( $names && ! in_array( $tag->name, $names, true ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}
}

class WPCF7_Mail {

	public static ?string $current_template = null;

	public static function get_current_template_name() {
		return self::$current_template;
	}

	public static function get_current() {
		return null;
	}
}

class WPCF7_Submission {

	public static function get_instance() {
		return null;
	}
}

/**
 * Intentionally NOT provided, so the test suite exercises the degradation
 * paths: `WPCF7_TagGeneratorGenerator` and `WPCF7_HTMLFormatter` are missing,
 * which must send the form-tag generator to its legacy panel fallback.
 */

class Eweb_Test_Rule {

	public string $name;
	public array $properties;

	public function __construct( string $name, array $properties = array() ) {
		$this->name       = $name;
		$this->properties = $properties;
	}
}

class Eweb_Test_Schema {

	public array $rules = array();

	public function add_rule( $rule ) {
		$this->rules[] = $rule;

		return true;
	}
}

/**
 * Mimics a future Contact Form 7 that requires a specific rule type: adding a
 * foreign rule must be caught by the plugin instead of fataling.
 */
class Eweb_Test_Strict_Schema {

	public array $rules = array();

	public function add_rule( AbstractRule $rule ) {
		$this->rules[] = $rule;

		return true;
	}
}

function wpcf7_swv_create_rule( $rule_name, $properties = array() ) {
	return new Eweb_Test_Rule( (string) $rule_name, (array) $properties );
}

class Eweb_Test_Mailer {

	public array $embedded = array();

	public function addEmbeddedImage( $path, $cid, $name = '', $encoding = '', $type = '' ) {
		$this->embedded[ $cid ] = $path;

		return true;
	}
}

function wpcf7_format_atts( $atts ) {
	$formatted = array();

	foreach ( (array) $atts as $key => $value ) {
		if ( null === $value || false === $value ) {
			continue;
		}

		if ( true === $value ) {
			$formatted[] = $key;

			continue;
		}

		$formatted[] = sprintf( '%s="%s"', $key, esc_attr( (string) $value ) );
	}

	return implode( ' ', $formatted );
}

function wpcf7_form_controls_class( $type, $default_classes = '' ) {
	$type = trim( (string) $type );

	if ( is_string( $default_classes ) ) {
		$default_classes = explode( ' ', $default_classes );
	}

	$classes = array( 'wpcf7-form-control', sprintf( 'wpcf7-%s', rtrim( $type, '*' ) ) );

	if ( str_ends_with( $type, '*' ) ) {
		$classes[] = 'wpcf7-validates-as-required';
	}

	$classes = array_merge( $classes, (array) $default_classes );

	return implode( ' ', array_filter( array_unique( $classes ) ) );
}

function wpcf7_get_validation_error( $name ) {
	return '';
}

function wpcf7_get_message( $name ) {
	return 'Please fill in the required field.';
}

function wpcf7_add_form_tag( $types, $callback, $features = array() ) {
	$GLOBALS['eweb_test']['form_tags'][ implode( ',', (array) $types ) ] = $callback;
}

function wpcf7_get_current_contact_form() {
	return $GLOBALS['eweb_test']['mail']['contact_form'] ?? null;
}

/* -------------------------------------------------------------------------
 * Test helpers
 * ---------------------------------------------------------------------- */

function eweb_test_chunk( string $type, string $data ): string {
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
}

function eweb_test_png( int $width = 4, int $height = 3 ): string {
	$raw = '';

	for ( $y = 0; $y < $height; $y++ ) {
		$raw .= "\x00" . str_repeat( "\x11\x22\x33\xff", $width );
	}

	$ihdr = pack( 'NNCCCCC', $width, $height, 8, 6, 0, 0, 0 );

	return "\x89PNG\r\n\x1a\n"
		. eweb_test_chunk( 'IHDR', $ihdr )
		. eweb_test_chunk( 'IDAT', (string) gzcompress( $raw, 9 ) )
		. eweb_test_chunk( 'IEND', '' );
}

function eweb_test_data_uri( string $binary ): string {
	return 'data:image/png;base64,' . base64_encode( $binary );
}

function eweb_test_clean_uploads(): void {
	$dir = $GLOBALS['eweb_test']['uploads']['basedir'];

	if ( ! is_dir( $dir ) ) {
		return;
	}

	foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
		is_dir( $file ) ? @rmdir( $file ) : @unlink( $file );
	}

	@rmdir( $dir );
}

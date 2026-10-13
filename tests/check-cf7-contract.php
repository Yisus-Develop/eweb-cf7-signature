<?php
/**
 * Contact Form 7 contract checker.
 *
 * Verifies, against the source of an installed Contact Form 7, that every
 * symbol and hook signature this plugin depends on is still present. Run it
 * after every Contact Form 7 update:
 *
 *   php tests/check-cf7-contract.php /path/to/wp-content/plugins/contact-form-7
 *
 * Exit code 0 means the contract still holds. A non-zero exit code lists
 * exactly what changed, which is what you need to know before releasing.
 *
 * @package EWEB_CF7_Signature\Tests
 */

declare( strict_types=1 );

$target = rtrim( $argv[1] ?? '', '/\\' );

if ( '' === $target || ! is_dir( $target ) ) {
	fwrite( STDERR, "Usage: php tests/check-cf7-contract.php /path/to/contact-form-7\n" );
	exit( 2 );
}

/* -------------------------------------------------------------------------
 * Load the source
 * ---------------------------------------------------------------------- */

$files = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$files[ ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $target ) ) ), '/' ) ]
		= (string) file_get_contents( $file->getPathname() );
}

ksort( $files );

$source = implode( "\n", $files );

$version = 'unknown';

if ( isset( $files['wp-contact-form-7.php'] ) && preg_match( '/^\s*\*?\s*Version:\s*([0-9.]+)/mi', $files['wp-contact-form-7.php'], $match ) ) {
	$version = $match[1];
}

echo "\nContact Form 7 " . $version . ' — ' . count( $files ) . " PHP files scanned\n";
echo str_repeat( '=', 64 ) . "\n";

$passed = 0;
$failed = 0;
$degraded = 0;
$failed_checks = array();

/**
 * Runs one check.
 *
 * @param string $label     Human readable check.
 * @param bool   $condition Result.
 * @param string $detail    Detail shown on failure.
 * @return void
 */
function check( string $label, bool $condition, string $detail = '' ): void {
	global $passed, $failed, $failed_checks;

	if ( $condition ) {
		$passed++;
		printf( "  ok   %s\n", $label );

		return;
	}

	$failed++;
	$failed_checks[] = $label . ( '' !== $detail ? ' — ' . $detail : '' );

	printf( "  FAIL %s%s\n", $label, '' !== $detail ? ' — ' . $detail : '' );
}

/**
 * Records an optional feature: its absence is a handled degradation, never a
 * broken contract, so it does not fail the run.
 *
 * @param string $label     Human readable check.
 * @param bool   $condition Result.
 * @return void
 */
function check_optional( string $label, bool $condition ): void {
	global $passed, $degraded;

	if ( $condition ) {
		$passed++;
		printf( "  ok   %s\n", $label );

		return;
	}

	$degraded++;
	printf( "  n/a  %s (degradation path active)\n", $label );
}

/**
 * Tells whether the concatenated source matches a pattern.
 *
 * @param string $pattern Regular expression.
 * @return bool
 */
function src_matches( string $pattern ): bool {
	global $source;

	return 1 === preg_match( $pattern, $source );
}

/**
 * Counts the arguments of an `apply_filters()` call.
 *
 * @param string      $hook     Hook name (literal, without quotes).
 * @param string|null $haystack Source to search in. Defaults to the scanned plugin.
 * @return int Number of arguments, or -1 when the hook is not applied.
 */
function filter_arg_count( string $hook, ?string $haystack = null ): int {
	global $source;

	$haystack = $haystack ?? $source;

	$pattern = '/apply_filters\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]\s*(,.*?)\)\s*[;\)]/s';

	if ( ! preg_match( $pattern, $haystack, $match ) ) {
		return -1;
	}

	// Count top level commas, the first one being the filtered value.
	$arguments = 1;
	$depth     = 0;

	foreach ( str_split( $match[1] ) as $character ) {
		if ( in_array( $character, array( '(', '[', '{' ), true ) ) {
			$depth++;
		} elseif ( in_array( $character, array( ')', ']', '}' ), true ) ) {
			$depth--;
		} elseif ( ',' === $character && 0 === $depth ) {
			$arguments++;
		}
	}

	return $arguments;
}

/* -------------------------------------------------------------------------
 * Form-tag API
 * ---------------------------------------------------------------------- */

echo "\nForm-tag API\n";

$required_functions = array(
	'wpcf7_add_form_tag',
	'wpcf7_format_atts',
	'wpcf7_form_controls_class',
	'wpcf7_get_validation_error',
	'wpcf7_get_message',
	'wpcf7_get_current_contact_form',
);

foreach ( $required_functions as $function ) {
	check(
		'function ' . $function . '()',
		src_matches( '/\bfunction\s+' . preg_quote( $function, '/' ) . '\s*\(/' )
	);
}

$required_methods = array(
	'WPCF7_FormTag'    => array( 'get_option', 'has_option', 'is_required', 'get_class_option', 'get_id_option' ),
	'WPCF7_ContactForm' => array( 'scan_form_tags', 'id' ),
	'WPCF7_Validation' => array( 'invalidate', 'is_valid' ),
	'WPCF7_Submission' => array( 'get_instance' ),
	'WPCF7_MailTag'    => array( 'tag_name', 'corresponding_form_tag' ),
);

foreach ( $required_methods as $class => $methods ) {
	check( 'class ' . $class, src_matches( '/\bclass\s+' . preg_quote( $class, '/' ) . '\b/' ) );

	foreach ( $methods as $method ) {
		check(
			$class . '::' . $method . '()',
			src_matches( '/function\s+' . preg_quote( $method, '/' ) . '\s*\(/' )
		);
	}
}

check(
	'WPCF7_FormTag::get_option( $option_name, $pattern, $single )',
	src_matches( '/function\s+get_option\s*\(\s*\$option_name\s*,\s*\$pattern\s*=\s*[\'"]{2}\s*,\s*\$single\s*=\s*false\s*\)/' )
);

check(
	'WPCF7_FormTag::get_class_option( $default_classes )',
	src_matches( '/function\s+get_class_option\s*\(\s*\$default_classes/' )
);

/* -------------------------------------------------------------------------
 * Hooks and their signatures
 * ---------------------------------------------------------------------- */

echo "\nHooks\n";

check( 'do_action( wpcf7_init )', src_matches( '/do_action\(\s*[\'"]wpcf7_init[\'"]/' ) );
check( 'do_action( wpcf7_admin_init )', src_matches( '/do_action\(\s*[\'"]wpcf7_admin_init[\'"]/' ) );

$mail_components = filter_arg_count( 'wpcf7_mail_components' );

check(
	'filter wpcf7_mail_components( $components, $contact_form, $mail )',
	$mail_components >= 3,
	'found ' . $mail_components . ' argument(s)'
);

$mail_tag_replaced = filter_arg_count( 'wpcf7_mail_tag_replaced' );

check(
	'filter wpcf7_mail_tag_replaced( $replaced, $submitted, $html, $mail_tag )',
	$mail_tag_replaced >= 4,
	'found ' . $mail_tag_replaced . ' argument(s)'
);

check(
	'filter wpcf7_validate_{$type}( $result, $tag ) still applied',
	src_matches( '/apply_filters\(\s*"wpcf7_validate_\{\$type\}"\s*,\s*\$result\s*,\s*\$tag\s*\)/' )
);

check(
	'filter wpcf7_posted_data( $posted_data ) still applied',
	filter_arg_count( 'wpcf7_posted_data' ) >= 1
);

check(
	'action phpmailer_init registered at priority 10 (we embed at 20)',
	src_matches( '/add_action\(\s*[\'"]phpmailer_init[\'"]\s*,\s*[\'"]\w+[\'"]\s*,\s*10\s*,/' )
);

check(
	'class WPCF7_Mail',
	src_matches( '/\bclass\s+WPCF7_Mail\b/' )
);

check(
	'WPCF7_Mail::get_current_template_name()',
	src_matches( '/function\s+get_current_template_name\s*\(/' )
);

check(
	'WPCF7_MailTag::field_name()',
	src_matches( '/function\s+field_name\s*\(/' )
);

check(
	'WPCF7_Validation::invalidate() keeps the first error only',
	src_matches( '/is_valid\(\s*\$name\s*\)\s*\)\s*\{\s*\$id\s*=/s' )
);

check(
	'WPCF7_Submission::add_extra_attachments( $file_path, $template )',
	src_matches( '/function\s+add_extra_attachments\s*\(\s*\$file_path\s*,\s*\$template\s*=\s*[\'"]mail[\'"]/' )
);

/* -------------------------------------------------------------------------
 * Optional features (degradation paths)
 * ---------------------------------------------------------------------- */

echo "\nOptional features\n";

$optional = array(
	'class WPCF7_TagGeneratorGenerator'       => '/\bclass\s+WPCF7_TagGeneratorGenerator\b/',
	'class WPCF7_HTMLFormatter'               => '/\bclass\s+WPCF7_HTMLFormatter\b/',
	'WPCF7_TagGeneratorGenerator::field_type()' => '/function\s+field_type\s*\(/',
	'WPCF7_TagGeneratorGenerator::field_name()' => '/function\s+field_name\s*\(/',
	'WPCF7_TagGeneratorGenerator::id_attr()'   => '/function\s+id_attr\s*\(/',
	'WPCF7_TagGeneratorGenerator::class_attr()' => '/function\s+class_attr\s*\(/',
	'WPCF7_TagGeneratorGenerator::insert_box_content()' => '/function\s+insert_box_content\s*\(/',
	'WPCF7_TagGeneratorGenerator::mail_tag_tip()' => '/function\s+mail_tag_tip\s*\(/',
	'function wpcf7_swv_create_rule()'        => '/\bfunction\s+wpcf7_swv_create_rule\s*\(/',
	'SWV required rule is available'          => '/[\'"]required[\'"]\s*=>\s*[\'"][^\'"]*RequiredRule[\'"]/',
	'do_action( wpcf7_swv_create_schema )'    => '/do_action\(\s*[\'"]wpcf7_swv_create_schema[\'"]/',
	'wpcf7_kses_allowed_html() allows data-*' => '/[\'"]data-\*[\'"]\s*=>\s*true/',
	'tag generator JS reads data-tag-part'    => '/data-tag-part/',
);

foreach ( $optional as $label => $pattern ) {
	check_optional( $label, src_matches( $pattern ) );
}

/* -------------------------------------------------------------------------
 * PDF plugin, when present next to Contact Form 7
 * ---------------------------------------------------------------------- */

$pdf_path = dirname( $target ) . '/send-pdf-for-contact-form-7';

if ( is_dir( $pdf_path ) ) {
	echo "\nSend PDF for Contact Form 7\n";

	$pdf_source = '';

	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pdf_path, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$pdf_source .= (string) file_get_contents( $file->getPathname() ) . "\n";
		}
	}

	check(
		'filter wpcf7pdf_text( $data, $contact_form )',
		filter_arg_count( 'wpcf7pdf_text', $pdf_source ) >= 2
	);
}

/* -------------------------------------------------------------------------
 * Summary
 * ---------------------------------------------------------------------- */

$total = $passed + $failed;

echo "\n" . str_repeat( '=', 64 ) . "\n";
printf( "%d/%d checks passed\n", $passed, $total );

if ( $degraded > 0 ) {
	printf( "%d optional feature(s) unavailable — the documented fallback is used.\n", $degraded );
}

if ( $failed > 0 ) {
	echo "\nContract broken. Review these before shipping:\n";

	foreach ( $failed_checks as $check ) {
		echo '  - ' . $check . "\n";
	}

	exit( 1 );
}

echo "\nContract intact: EWEB CF7 Signature is compatible with Contact Form 7 " . $version . ".\n";

exit( 0 );

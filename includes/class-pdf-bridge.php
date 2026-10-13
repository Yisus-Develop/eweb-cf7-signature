<?php
/**
 * PDF generator bridge: local filesystem paths instead of HTTP loopback.
 *
 * @package EWEB_CF7_Signature
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EWEB_CF7_Signature_PDF_Bridge' ) ) {

	/**
	 * Feeds PDF generators (mPDF, dompdf, TCPDF...) with the absolute path of
	 * the signature file.
	 *
	 * Why the path and not the URL: a PDF engine that receives an URL has to
	 * perform an HTTP request against the site itself. That loopback call is
	 * slow, breaks behind proxies, is blocked by most hosts and fails whenever
	 * the request cannot resolve the domain (cron, CLI, staging).
	 *
	 * Two layers are provided:
	 *
	 * - `EWEB_CF7_Signature_PDF_Bridge::get_local_path()` plus the
	 *   `eweb_cf7_signature_pdf_path` filter, for any custom integration.
	 * - An automatic swap on the `wpcf7pdf_text` filter of "Send PDF for
	 *   Contact Form 7", which receives the final HTML right before it is
	 *   handed to mPDF.
	 */
	final class EWEB_CF7_Signature_PDF_Bridge {

		/**
		 * Nothing to instantiate: the class only exposes static methods.
		 */
		private function __construct() {}

		/**
		 * Hooks the module.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'wpcf7pdf_text', array( __CLASS__, 'filter_pdf_html' ), 10, 2 );
		}

		/**
		 * Resolves a field name or a public URL into an absolute local path.
		 *
		 * @param string                 $reference    Form field name or stored signature URL.
		 * @param WPCF7_ContactForm|null $contact_form Current contact form, for context.
		 * @return string Normalised absolute path, or an empty string.
		 */
		public static function get_local_path( string $reference, $contact_form = null ): string {
			$reference = trim( $reference );

			if ( '' === $reference ) {
				return '';
			}

			$file = EWEB_CF7_Signature_Handler::get_file( $reference );

			$path = null === $file
				? EWEB_CF7_Signature_Handler::url_to_path( $reference )
				: $file['path'];

			if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
				return '';
			}

			/**
			 * Filters the absolute path handed over to the PDF generator.
			 *
			 * @param string                 $path         Absolute path of the PNG file.
			 * @param string                 $reference    Form field name or stored signature URL.
			 * @param WPCF7_ContactForm|null $contact_form Current contact form.
			 */
			return (string) apply_filters( 'eweb_cf7_signature_pdf_path', $path, $reference, $contact_form );
		}

		/**
		 * Builds the `<img>` markup a PDF engine can render offline.
		 *
		 * @param string               $reference Form field name or stored signature URL.
		 * @param array<string, mixed> $args      {
		 *    Optional. Rendering arguments.
		 *
		 *     @type string $class    CSS class of the image. Default `eweb-signature-pdf`.
		 *     @type int    $width    Forced width, in pixels. Default `0` (natural size).
		 *     @type int    $height   Forced height, in pixels. Default `0` (natural size).
		 *     @type bool   $data_uri Embed the image as a Base64 data URI instead of a path.
		 * }
		 * @return string Empty string when the signature cannot be resolved.
		 */
		public static function get_image_html( string $reference, array $args = array() ): string {
			$args = wp_parse_args(
				$args,
				array(
					'class'    => 'eweb-signature-pdf',
					'width'    => 0,
					'height'   => 0,
					'data_uri' => false,
				)
			);

			$path = self::get_local_path( $reference );

			if ( '' === $path ) {
				return '';
			}

			$source = $path;

			if ( $args['data_uri'] ) {
				$binary = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file written by the plugin itself, never a remote URL.

				if ( is_string( $binary ) && '' !== $binary ) {
					$source = 'data:image/png;base64,' . base64_encode( $binary ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inlining the PNG is the documented purpose of this option.
				}
			}

			$dimensions = '';

			if ( (int) $args['width'] > 0 ) {
				$dimensions .= sprintf( ' width="%d"', (int) $args['width'] );
			}

			if ( (int) $args['height'] > 0 ) {
				$dimensions .= sprintf( ' height="%d"', (int) $args['height'] );
			}

			/**
			 * Filters the `<img>` markup generated for a PDF document.
			 *
			 * @param string $markup `<img>` tag.
			 * @param string $source Image source (path or data URI).
			 * @param string $path   Absolute path of the PNG file.
			 */
			return (string) apply_filters(
				'eweb_cf7_signature_pdf_image',
				sprintf(
					'<img src="%1$s" alt="%2$s" class="%3$s"%4$s style="max-width:100%%;" />',
					esc_attr( $source ),
					esc_attr__( 'Digital signature', 'eweb-cf7-signature' ),
					esc_attr( (string) $args['class'] ),
					$dimensions
				),
				$source,
				$path
			);
		}

		/**
		 * Replaces every signature URL (or unresolved mail-tag) with a local image.
		 *
		 * Hooked to `wpcf7pdf_text`, the filter of "Send PDF for Contact Form 7"
		 * that receives the final HTML just before `mPDF::WriteHTML()`.
		 *
		 * @param string                 $html         Final HTML of the PDF document.
		 * @param WPCF7_ContactForm|null $contact_form Current contact form.
		 * @return string
		 */
		public static function filter_pdf_html( $html, $contact_form = null ) {
			if ( ! is_string( $html ) || '' === $html ) {
				return $html;
			}

			foreach ( EWEB_CF7_Signature_Handler::get_files() as $field_name => $file ) {
				$path = self::get_local_path( (string) $field_name, $contact_form );

				if ( '' === $path ) {
					continue;
				}

				$url = (string) $file['url'];

				if ( '' !== $url ) {
					// The URL already sits inside an attribute: keep it an attribute.
					$html = str_replace(
						array(
							'src="' . $url . '"',
							"src='" . $url . "'",
							'href="' . $url . '"',
							"href='" . $url . "'",
						),
						array(
							'src="' . $path . '"',
							"src='" . $path . "'",
							'href="' . $path . '"',
							"href='" . $path . "'",
						),
						$html
					);

					// Any remaining occurrence is plain text: render the image.
					$html = str_replace( $url, self::get_image_html( (string) $field_name ), $html );
				}

				// The PDF template may also contain the raw mail-tag.
				$html = str_replace(
					array( '[' . $field_name . ']', '[_raw_' . $field_name . ']' ),
					self::get_image_html( (string) $field_name ),
					$html
				);
			}

			/**
			 * Filters the final HTML of a PDF document once the signatures
			 * have been converted into offline images.
			 *
			 * @param string                 $html         Final HTML.
			 * @param WPCF7_ContactForm|null $contact_form Current contact form.
			 */
			return (string) apply_filters( 'eweb_cf7_signature_pdf_text', $html, $contact_form );
		}
	}
}

<?php
/**
 * Contact Form 7 mail integration: attachments and inline images.
 *
 * @package EWEB_CF7_Signature
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EWEB_CF7_Signature_Mail_Integrator' ) ) {

	/**
	 * Injects the stored signature into every outgoing Contact Form 7 e-mail.
	 *
	 * Three independent integrations are provided:
	 *
	 * - `wpcf7_mail_components`: the absolute PNG path is appended to the mail
	 *   attachments, so no HTTP loopback request is ever made by CF7.
	 * - `wpcf7_mail_tag_replaced`: the `[signature]` mail-tag is rendered as a
	 *   public link, an inline Base64 image or a native CID image, depending on
	 *   the `mode:` form-tag option. When an external consumer (a PDF builder,
	 *   for instance) resolves the mail-tags outside a mail composition, the
	 *   absolute filesystem path is handed over instead.
	 * - `phpmailer_init`: registers the CID images that the HTML body refers to,
	 *   so they travel inside the message and no client has to fetch anything.
	 */
	final class EWEB_CF7_Signature_Mail_Integrator {

		/**
		 * CID images waiting to be embedded in the next message.
		 *
		 * @var array<string, string>
		 */
		private static array $pending = array();

		/**
		 * Form-tag options, cached per field name.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private static array $options = array();

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
			add_filter( 'wpcf7_mail_components', array( __CLASS__, 'filter_mail_components' ), 10, 3 );
			add_filter( 'wpcf7_mail_tag_replaced', array( __CLASS__, 'filter_mail_tag_replaced' ), 10, 4 );
			add_action( 'phpmailer_init', array( __CLASS__, 'embed_pending_images' ), 20, 1 );
		}

		/**
		 * Appends the signature files to the outgoing mail attachments.
		 *
		 * @param array             $components   Mail components.
		 * @param WPCF7_ContactForm $contact_form Current contact form.
		 * @param WPCF7_Mail        $mail         Mail template being composed.
		 * @return array
		 */
		public static function filter_mail_components( $components, $contact_form = null, $mail = null ): array {
			if ( ! is_array( $components ) ) {
				return (array) $components;
			}

			$template = 'mail';

			if ( is_object( $mail ) && method_exists( $mail, 'name' ) ) {
				$name = (string) $mail->name();

				if ( '' !== $name ) {
					$template = $name;
				}
			}

			$attachments = $components['attachments'] ?? array();

			if ( is_string( $attachments ) ) {
				$attachments = preg_split( '/\r\n|\r|\n/', $attachments );
			}

			$attachments = array_filter( (array) $attachments, 'is_string' );

			foreach ( EWEB_CF7_Signature_Handler::get_files() as $field_name => $file ) {
				$options = self::field_options( (string) $field_name, $contact_form );

				if ( ! $options['attach'] ) {
					continue;
				}

				if ( 'both' !== $options['attach_to'] && $options['attach_to'] !== $template ) {
					continue;
				}

				if ( in_array( $file['path'], $attachments, true ) ) {
					continue;
				}

				$attachments[] = $file['path'];
			}

			$components['attachments'] = array_values( $attachments );

			return $components;
		}

		/**
		 * Renders the `[signature]` mail-tag.
		 *
		 * @param string        $replaced Replaced value produced by Contact Form 7.
		 * @param mixed         $submitted Raw submitted value.
		 * @param bool          $html     Whether the current component is an HTML body.
		 * @param WPCF7_MailTag $mail_tag Mail-tag being replaced.
		 * @return string
		 */
		public static function filter_mail_tag_replaced( $replaced, $submitted, $html, $mail_tag ) {
			if ( ! $mail_tag instanceof WPCF7_MailTag ) {
				return $replaced;
			}

			// `field_name()` is the modern accessor; `tag_name()` covers older releases.
			$field_name = method_exists( $mail_tag, 'field_name' )
				? (string) $mail_tag->field_name()
				: (string) $mail_tag->tag_name();

			$file = EWEB_CF7_Signature_Handler::get_file( $field_name );

			if ( null === $file ) {
				return $replaced;
			}

			$options = self::field_options(
				$field_name,
				$mail_tag->corresponding_form_tag()
			);

			/*
			 * No mail template is being composed: an external consumer (a PDF
			 * generator, a shortcode, a REST request...) is resolving the
			 * mail-tags. Give it the local filesystem path so it never has to
			 * perform a loopback HTTP request against the site itself.
			 */
			if ( null === self::current_template() ) {
				$value = (string) apply_filters(
					'eweb_cf7_signature_external_value',
					$file['path'],
					$file,
					$field_name
				);

				return '' === $value ? (string) $replaced : $value;
			}

			if ( 'none' === $options['mode'] ) {
				return '';
			}

			// A plain text message can only carry the public URL.
			if ( ! $html ) {
				return $replaced;
			}

			if ( 'cid' === $options['mode'] ) {
				return self::render_cid_image( $file );
			}

			if ( 'base64' === $options['mode'] ) {
				return self::render_base64_image( $file );
			}

			return $replaced;
		}

		/**
		 * Registers the embedded (CID) images of the message being sent.
		 *
		 * Runs after Contact Form 7's own `phpmailer_init` callback, which turns
		 * the body into HTML, so the `cid:` references are guaranteed to be
		 * intact when the related parts are attached.
		 *
		 * @param mixed $phpmailer PHPMailer instance.
		 * @return void
		 */
		public static function embed_pending_images( $phpmailer ): void {
			if ( ! is_object( $phpmailer ) || ! method_exists( $phpmailer, 'addEmbeddedImage' ) ) {
				return;
			}

			$pending       = self::$pending;
			self::$pending = array();

			foreach ( $pending as $cid => $file ) {
				if ( is_readable( $file ) ) {
					$phpmailer->addEmbeddedImage( $file, $cid, basename( $file ), 'base64', 'image/png' );
				}
			}
		}

		/**
		 * Returns the pending CID images. Useful for testing.
		 *
		 * @return array<string, string>
		 */
		public static function get_pending_images(): array {
			return self::$pending;
		}

		/*
		---------------------------------------------------------------------
		 * Internals
		 * ------------------------------------------------------------------
		 */

		/**
		 * Builds an `<img>` tag pointing to an embedded CID part.
		 *
		 * @param array{path: string, url: string, file: string, bytes: int, width: int, height: int} $file Stored file.
		 * @return string
		 */
		private static function render_cid_image( array $file ): string {
			$cid = 'eweb-signature-' . md5( $file['file'] ) . '.png';

			self::$pending[ $cid ] = $file['path'];

			return self::image_tag( 'cid:' . $cid, $file );
		}

		/**
		 * Builds an `<img>` tag carrying the image as a Base64 data URI.
		 *
		 * Note that large inline images make the message heavier and can be
		 * stripped or clipped by strict clients; `cid` is the safest mode.
		 *
		 * @param array{path: string, url: string, file: string, bytes: int, width: int, height: int} $file Stored file.
		 * @return string
		 */
		private static function render_base64_image( array $file ): string {
			$binary = @file_get_contents( $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file written by the plugin itself, never a remote URL.

			if ( ! is_string( $binary ) || '' === $binary ) {
				return (string) $file['url'];
			}

			return self::image_tag( 'data:image/png;base64,' . base64_encode( $binary ), $file ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inlining the PNG is the documented purpose of this mode.
		}

		/**
		 * Builds the `<img>` markup shared by the inline modes.
		 *
		 * @param string                                                                              $source `cid:` or `data:` source.
		 * @param array{path: string, url: string, file: string, bytes: int, width: int, height: int} $file   Stored file.
		 * @return string
		 */
		private static function image_tag( string $source, array $file ): string {
			$dimensions = '';

			if ( $file['width'] > 0 && $file['height'] > 0 ) {
				$dimensions = sprintf( ' width="%d" height="%d"', $file['width'], $file['height'] );
			}

			/**
			 * Filters the markup used to display a signature inside an e-mail body.
			 *
			 * @param string $markup `<img>` tag.
			 * @param string $source Image source (`cid:` or `data:` URI).
			 * @param array  $file   Stored file details.
			 */
			return (string) apply_filters(
				'eweb_cf7_signature_mail_image',
				sprintf(
					'<img src="%1$s" alt="%2$s"%3$s style="max-width:100%%;height:auto;" />',
					esc_attr( $source ),
					esc_attr__( 'Digital signature', 'eweb-cf7-signature' ),
					$dimensions
				),
				$source,
				$file
			);
		}

		/**
		 * Returns the form-tag options of a field, reading them once per request.
		 *
		 * @param string                               $field_name   Form field name.
		 * @param WPCF7_ContactForm|WPCF7_FormTag|null $for_form    Contact form or form-tag to read the options from.
		 * @return array{pen: string, background: string, pen_width: string, width: int, height: int, mode: string, attach: bool, attach_to: string, trim: bool, hint: string}
		 */
		private static function field_options( string $field_name, $for_form = null ): array {
			if ( isset( self::$options[ $field_name ] ) ) {
				return self::$options[ $field_name ];
			}

			if ( $for_form instanceof WPCF7_FormTag ) {
				$options = EWEB_CF7_Signature_Handler::field_options( $for_form );
			} else {
				$options = EWEB_CF7_Signature_Handler::field_options(
					self::find_form_tag( $field_name, $for_form )
				);
			}

			self::$options[ $field_name ] = $options;

			return $options;
		}

		/**
		 * Finds the form-tag of a signature field inside a contact form.
		 *
		 * @param string                 $field_name   Form field name.
		 * @param WPCF7_ContactForm|null $contact_form Contact form to search in.
		 * @return WPCF7_FormTag|null
		 */
		private static function find_form_tag( string $field_name, $contact_form = null ) {
			if ( ! $contact_form instanceof WPCF7_ContactForm ) {
				$contact_form = function_exists( 'wpcf7_get_current_contact_form' )
					? wpcf7_get_current_contact_form()
					: null;

				if ( ! $contact_form instanceof WPCF7_ContactForm ) {
					return null;
				}
			}

			$tags = $contact_form->scan_form_tags(
				array( 'basetype' => array( 'signature' ) )
			);

			foreach ( $tags as $tag ) {
				if ( $tag instanceof WPCF7_FormTag && (string) $tag->name === $field_name ) {
					return $tag;
				}
			}

			return null;
		}

		/**
		 * Returns the name of the mail template being composed, if any.
		 *
		 * `null` means that no Contact Form 7 mail is being built, therefore the
		 * mail-tags are being resolved by a third party.
		 *
		 * @return string|null
		 */
		private static function current_template(): ?string {
			if ( ! class_exists( 'WPCF7_Mail' ) ) {
				return null;
			}

			if ( method_exists( 'WPCF7_Mail', 'get_current_template_name' ) ) {
				$name = WPCF7_Mail::get_current_template_name();

				return is_string( $name ) && '' !== $name ? $name : null;
			}

			if ( method_exists( 'WPCF7_Mail', 'get_current' ) ) {
				$mail = WPCF7_Mail::get_current();

				if ( is_object( $mail ) && method_exists( $mail, 'name' ) ) {
					$name = (string) $mail->name();

					return '' !== $name ? $name : null;
				}
			}

			return null;
		}
	}
}
